<?php

namespace App\Services;

use App\Companions\CompanionRegistry;
use App\Enums\CardLegality;
use App\Enums\DeckZone;
use App\Formats\FormatProfile;
use App\Models\Deck;
use App\Models\DeckCard;
use App\Models\OracleCard;

/**
 * Runs whole-deck legality checks and reports violations.
 *
 * Per-card violations (flagged on individual cards):
 *
 *  - pool_legality — card banned or not legal in the deck's format
 *  - copy_limit — total copies across all rows for an oracle card exceeds
 *    the format's maxCopies (basic lands and unlimited-copies cards exempt)
 *  - color_identity — card color identity is not a subset of the combined
 *    commanders' color identity (only in formats that enforce it)
 *
 * Deck-level violations (apply to the whole deck, not a specific card):
 *
 *  - deck_size_min — main deck below the format minimum
 *  - deck_size_max — main deck above the format maximum
 *  - sideboard_size_max — sideboard above the format maximum
 *  - commander_banned — one or more commanders are on the format's
 *    banned-as-commander overlay (legal in the 99, banned in the command
 *    zone — Duel Commander has its own list for this).
 *
 * Expects these relations to already be loaded on the deck so the service
 * itself performs no queries:
 *
 *  - deckCards.oracleCard.legalities (scoped to the deck's format)
 *  - deckCards.oracleCard.faces (for hasUnlimitedCopiesRule)
 *  - commanders (for combined color identity)
 *
 * @phpstan-type Violation array{type: string, card_ids?: array<int, string>, current?: int, min?: int, max?: int}
 */
final class DeckValidator
{
    private const WUBRG = ['W', 'U', 'B', 'R', 'G'];

    /**
     * Return all legality violations for the deck.
     *
     * @return array<int, Violation>
     */
    public static function validate(Deck $deck): array
    {
        $profile = $deck->format->rules();
        $violations = [];

        $poolIds = self::poolLegalityViolations($deck, $profile);
        if ($poolIds !== []) {
            $violations[] = ['type' => 'pool_legality', 'card_ids' => array_values($poolIds)];
        }

        if ($profile->enforcesColorIdentity()) {
            $ciIds = self::colorIdentityViolations($deck);
            if ($ciIds !== []) {
                $violations[] = ['type' => 'color_identity', 'card_ids' => array_values($ciIds)];
            }
        }

        $copyIds = self::copyLimitViolations($deck, $profile);
        if ($copyIds !== []) {
            $violations[] = ['type' => 'copy_limit', 'card_ids' => array_values($copyIds)];
        }

        $bannedCommanderNames = self::bannedCommanderNames($deck, $profile);
        if ($bannedCommanderNames !== []) {
            $violations[] = ['type' => 'commander_banned', 'names' => $bannedCommanderNames];
        }

        $mainSize = self::zoneSize($deck, DeckZone::Main);
        if ($profile->requiresCommander()) {
            $mainSize += $deck->commanders->count();
        }
        if ($mainSize < $profile->minDeckSize()) {
            $violations[] = ['type' => 'deck_size_min', 'current' => $mainSize, 'min' => $profile->minDeckSize()];
        }
        if ($profile->maxDeckSize() !== null && $mainSize > $profile->maxDeckSize()) {
            $violations[] = ['type' => 'deck_size_max', 'current' => $mainSize, 'max' => $profile->maxDeckSize()];
        }

        $sideSize = self::zoneSize($deck, DeckZone::Side);
        if ($sideSize > $profile->maxSideboardSize()) {
            $violations[] = [
                'type' => 'sideboard_size_max',
                'current' => $sideSize,
                'max' => $profile->maxSideboardSize(),
            ];
        }

        $companionViolation = self::companionRestrictionViolation($deck, $violations);
        if ($companionViolation !== null) {
            $violations[] = $companionViolation;
        }

        return $violations;
    }

    /**
     * Run the current companion's deckbuilding restriction, if any.
     *
     * Per-card variants emit as `companion_restriction` with `card_ids`; the
     * size-based Yorion variant emits as `companion_size_restriction` with
     * `current`/`min`. The two distinct `type` values keep the frontend
     * discriminated union unambiguous.
     *
     * Per-card card IDs are deduplicated against IDs already reported by
     * other per-card violations (pool_legality, copy_limit, color_identity).
     * A card that is banned in the format is already called out there, and
     * showing it again under the companion rule is noise. If every offending
     * ID is already reported elsewhere, the companion violation is dropped.
     *
     * @param  array<int, array{type: string, card_ids?: array<int, string>}>  $priorViolations
     * @return array{type: string, message_key: string, card_ids?: array<int, string>, current?: int, min?: int}|null
     */
    private static function companionRestrictionViolation(Deck $deck, array $priorViolations): ?array
    {
        if ($deck->companion === null) {
            return null;
        }

        $profile = CompanionRegistry::profileFor($deck->companion);
        if ($profile === null) {
            return null;
        }

        $result = $profile->validate($deck);
        if ($result === null) {
            return null;
        }

        if (! isset($result['card_ids'])) {
            return ['type' => 'companion_size_restriction', 'message_key' => $profile->messageKey()] + $result;
        }

        $alreadyReported = [];
        foreach ($priorViolations as $violation) {
            foreach ($violation['card_ids'] ?? [] as $id) {
                $alreadyReported[$id] = true;
            }
        }

        $filtered = array_values(array_filter(
            $result['card_ids'],
            fn (string $id): bool => ! isset($alreadyReported[$id]),
        ));
        if ($filtered === []) {
            return null;
        }

        return [
            'type' => 'companion_restriction',
            'message_key' => $profile->messageKey(),
            'card_ids' => $filtered,
        ];
    }

    /**
     * Flatten all per-card violations into a lookup of deck_card_id => true,
     * ready for fast `isset()` checks when building the card payload.
     *
     * @param  array<int, Violation>  $violations
     * @return array<string, true>
     */
    public static function illegalDeckCardIds(array $violations): array
    {
        $ids = [];
        foreach ($violations as $violation) {
            foreach ($violation['card_ids'] ?? [] as $id) {
                $ids[$id] = true;
            }
        }

        return $ids;
    }

    /**
     * @return array<string, string>
     */
    private static function poolLegalityViolations(Deck $deck, FormatProfile $profile): array
    {
        $ids = [];
        $format = $deck->format->value;
        foreach ($deck->deckCards as $deckCard) {
            if (! self::isInPool($deckCard->oracleCard, $format, $profile)) {
                $ids[$deckCard->id] = $deckCard->id;
            }
        }

        return $ids;
    }

    /**
     * @return array<string, string>
     */
    private static function colorIdentityViolations(Deck $deck): array
    {
        $commanderIdentity = self::combinedColorIdentity($deck->commanders);
        $ids = [];
        foreach ($deck->deckCards as $deckCard) {
            if (! self::respectsColorIdentity($deckCard->oracleCard->color_identity, $commanderIdentity)) {
                $ids[$deckCard->id] = $deckCard->id;
            }
        }

        return $ids;
    }

    /**
     * Flag every row of an oracle card whose total copies exceed maxCopies.
     *
     * @return array<string, string>
     */
    private static function copyLimitViolations(Deck $deck, FormatProfile $profile): array
    {
        $totals = [];
        foreach ($deck->deckCards as $deckCard) {
            $totals[$deckCard->oracle_card_id] = ($totals[$deckCard->oracle_card_id] ?? 0) + $deckCard->quantity;
        }

        $maxCopies = $profile->maxCopies();
        $ids = [];
        foreach ($deck->deckCards as $deckCard) {
            if (($totals[$deckCard->oracle_card_id] ?? 0) <= $maxCopies) {
                continue;
            }

            $oracle = $deckCard->oracleCard;
            if (in_array($oracle->name, FormatProfile::BASIC_LANDS, true)) {
                continue;
            }
            if ($oracle->hasUnlimitedCopiesRule()) {
                continue;
            }

            $ids[$deckCard->id] = $deckCard->id;
        }

        return $ids;
    }

    /**
     * Names of commanders that appear on the format's banned-as-commander
     * overlay. Returns the empty list when the format has no overlay or
     * none of the deck's commanders match.
     *
     * @return array<int, string>
     */
    private static function bannedCommanderNames(Deck $deck, FormatProfile $profile): array
    {
        $banned = $profile->bannedAsCommander();
        if ($banned === []) {
            return [];
        }

        return $deck->commanders
            ->filter(fn (OracleCard $commander): bool => in_array($commander->name, $banned, true))
            ->pluck('name')
            ->values()
            ->all();
    }

    private static function zoneSize(Deck $deck, DeckZone $zone): int
    {
        return (int) $deck->deckCards
            ->filter(fn (DeckCard $deckCard): bool => $deckCard->zone === $zone)
            ->sum('quantity');
    }

    private static function isInPool(OracleCard $card, string $format, FormatProfile $profile): bool
    {
        $legality = $card->legalities->firstWhere('format', $format);

        if ($legality === null) {
            return false;
        }

        if (! in_array($legality->legality, [CardLegality::Legal, CardLegality::Restricted], true)) {
            return false;
        }

        return $profile->isInPool($card);
    }

    /**
     * @param  iterable<OracleCard>  $commanders
     */
    private static function combinedColorIdentity(iterable $commanders): string
    {
        $letters = [];
        foreach ($commanders as $commander) {
            if ($commander->color_identity === null) {
                continue;
            }
            foreach (str_split($commander->color_identity) as $letter) {
                $letters[$letter] = true;
            }
        }

        $ordered = array_filter(self::WUBRG, fn (string $letter): bool => isset($letters[$letter]));

        return implode('', $ordered);
    }

    private static function respectsColorIdentity(?string $cardIdentity, string $commanderIdentity): bool
    {
        if ($cardIdentity === null || $cardIdentity === '') {
            return true;
        }

        foreach (str_split($cardIdentity) as $letter) {
            if (! str_contains($commanderIdentity, $letter)) {
                return false;
            }
        }

        return true;
    }
}
