<?php

namespace App\Services;

use App\Companions\CompanionRegistry;
use App\Enums\CardLegality;
use App\Enums\DeckZone;
use App\Formats\FormatProfile;
use App\Models\Deck;
use App\Models\DeckCard;
use App\Models\OracleCard;
use App\Rulebreakers\RulebreakerRegistry;

/**
 * Runs whole-deck legality checks and reports violations.
 *
 * Per-card violations (flagged on individual cards):
 *
 *  - pool_legality — card banned or not legal in the deck's format
 *  - copy_limit — total copies across all rows for an oracle card exceeds
 *    the format's maxCopies (basic lands and unlimited-copies cards exempt)
 *  - color_identity — card color identity is not a subset of the deck's
 *    identity (only in formats that enforce it). That identity comes from
 *    {@see Deck::colorIdentity()}, which the card-search filter reads too, so
 *    a card can never be offered by search and then flagged here.
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
 *  - commanders.oracleCard (to resolve a Rulebreaker commander — see
 *    {@see RulebreakerRegistry} — and for the identity fallback in
 *    {@see Deck::colorIdentity()} when `decks.colors` is empty)
 *
 * @phpstan-type Violation array{type: string, card_ids?: array<int, string>, current?: int, min?: int, max?: int}
 */
final class DeckValidator
{
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

        $bannedCommander = self::bannedCommanderViolation($deck, $profile);
        if ($bannedCommander !== null) {
            $violations[] = $bannedCommander;
        }

        $mainSize = self::zoneSize($deck, DeckZone::Main);
        if ($profile->requiresCommander()) {
            $mainSize += $deck->commanders->count();
        }
        if ($mainSize < $profile->minDeckSize()) {
            $violations[] = ['type' => 'deck_size_min', 'current' => $mainSize, 'min' => $profile->minDeckSize()];
        }
        // Whtz, the Bibliophile has "no maximum deck size" — the one Rulebreaker
        // that relaxes a whole-deck check rather than colour identity, so it
        // is read here rather than through the per-card exemptions. The
        // MINIMUM still applies: the ceiling goes, the floor does not.
        $liftsMaxDeckSize = RulebreakerRegistry::forDeck($deck)?->removesMaxDeckSize() ?? false;
        if (! $liftsMaxDeckSize && $profile->maxDeckSize() !== null && $mainSize > $profile->maxDeckSize()) {
            $violations[] = ['type' => 'deck_size_max', 'current' => $mainSize, 'max' => $profile->maxDeckSize()];
        }
        // Note: `$deck->commanders` is now a HasMany<DeckCard> filtered to
        // zone=command (post-consolidation), so `->count()` still returns
        // the right number even though the underlying shape changed.

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
        $companionDeckCard = $deck->companion;
        if ($companionDeckCard === null) {
            return null;
        }

        $companionOracle = $companionDeckCard->oracleCard;
        if ($companionOracle === null) {
            return null;
        }

        $profile = CompanionRegistry::profileFor($companionOracle);
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
        // Read from the deck rather than derived here, so the legality check
        // and the card-search filter cannot drift apart — see
        // {@see Deck::colorIdentity()} for why they had.
        $commanderIdentity = $deck->colorIdentity();
        // A Rulebreaker commander relaxes this check for some class of card —
        // "Angel cards of any color identity", or for Tolabow instants and
        // sorceries widened by one nominated colour. It reports the identity a
        // given card should be judged against, so the comparison below stays
        // the single place identities are compared. Null for an ordinary deck,
        // and null per-card for anything the rule does not speak to.
        $rulebreaker = RulebreakerRegistry::forDeck($deck);
        $ids = [];
        foreach ($deck->deckCards as $deckCard) {
            // Command-zone rows ARE the source of the deck's color
            // identity; checking them against themselves is trivially
            // pass-through. The Magic-keyword companion is also exempt
            // (its own per-companion validator handles it).
            if ($deckCard->zone === DeckZone::Command || $deckCard->zone === DeckZone::Companion) {
                continue;
            }
            $oracle = $deckCard->oracleCard;
            // A row whose oracle relation does not resolve cannot be judged.
            // Skipping matches the previous behaviour, where a null identity
            // was treated as "no violation"; without the guard the Rulebreaker
            // call below would TypeError and take down the deck page.
            if ($oracle === null) {
                continue;
            }
            $identity = $rulebreaker?->allowedIdentityFor($oracle, $deck, $commanderIdentity) ?? $commanderIdentity;
            if (! self::respectsColorIdentity($oracle->color_identity, $identity)) {
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
     * Build the `commander_banned` violation when one or more command-zone
     * cards appear on the format's banned-as-commander overlay. Returns
     * both `names` (for the LegalityPanel's existing display) and
     * `card_ids` (so {@see illegalDeckCardIds} aggregates them and the
     * per-row "illegal" indicator lights up on the offending commander).
     *
     * @return array{type: 'commander_banned', names: array<int, string>, card_ids: array<int, string>}|null
     */
    private static function bannedCommanderViolation(Deck $deck, FormatProfile $profile): ?array
    {
        $banned = $profile->bannedAsCommander();
        if ($banned === []) {
            return null;
        }

        $names = [];
        $cardIds = [];
        foreach ($deck->commanders as $row) {
            $oracle = $row->oracleCard;
            if ($oracle === null || ! in_array($oracle->name, $banned, true)) {
                continue;
            }
            $names[] = $oracle->name;
            $cardIds[] = $row->id;
        }

        if ($names === []) {
            return null;
        }

        return [
            'type' => 'commander_banned',
            'names' => $names,
            'card_ids' => $cardIds,
        ];
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
