<?php

namespace App\Services;

use App\Enums\CardFormat;
use App\Models\Deck;
use App\Models\DefaultCard;
use App\Models\OracleCard;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DeckService
{
    /**
     * Build a CommandZoneService-compatible parsed array from a raw card name.
     *
     * CommandZoneService matches against `searchable_name` via the
     * `normalized_name_segments` list, so the oracle name must go through
     * the same normalizer that {@see CardSearchParser} uses for user queries.
     *
     * @return array{name_segments: string[], normalized_name_segments: string[], set_code: null, collector_number: null}
     */
    private static function parsedForCardName(string $name): array
    {
        $normalized = CardNameNormalizer::normalize($name);

        return [
            'name_segments' => [$name],
            'normalized_name_segments' => $normalized === '' ? [] : explode(' ', $normalized),
            'set_code' => null,
            'collector_number' => null,
        ];
    }

    /**
     * Resolve the newest default card (printing) for an oracle card.
     *
     * Joins through the set's released_at to pick the most recent printing.
     * Falls back to the first default card if no set dates are available.
     */
    public static function newestDefaultCard(string $oracleCardId): ?DefaultCard
    {
        return DefaultCard::query()
            ->where('oracle_id', $oracleCardId)
            ->join('sets', 'sets.id', '=', 'default_cards.set_id')
            ->orderByDesc('sets.released_at')
            ->select('default_cards.*')
            ->first();
    }

    /**
     * Validate that an oracle card is a legal commander for the given format.
     *
     * Uses CommandZoneService's search with an exact name match — if the card
     * appears in results, it's valid.
     */
    public static function isLegalCommander(string $oracleCardId, CardFormat $format): bool
    {
        $oracle = OracleCard::find($oracleCardId);

        if (! $oracle) {
            return false;
        }

        $parsed = self::parsedForCardName($oracle->name);
        $filters = [
            'rule0' => false,
            'partner' => false,
            'friends_forever' => false,
            'doctors_companion' => false,
            'background' => false,
            'partner_type' => null,
            'exclude' => null,
        ];

        $results = CommandZoneService::searchCommanders($format, $parsed, $filters);

        return $results->contains('id', $oracleCardId);
    }

    /**
     * Validate that an oracle card is a legal Oathbreaker planeswalker.
     */
    public static function isLegalOathbreaker(string $oracleCardId, CardFormat $format): bool
    {
        $oracle = OracleCard::find($oracleCardId);

        if (! $oracle) {
            return false;
        }

        $parsed = self::parsedForCardName($oracle->name);

        $results = CommandZoneService::searchOathbreaker($format, $parsed, 'planeswalker', null, false, null);

        return $results->contains('id', $oracleCardId);
    }

    /**
     * Validate that an oracle card is a legal signature spell for the given planeswalker.
     */
    public static function isLegalSignatureSpell(string $oracleCardId, string $planeswalkerOracleCardId, CardFormat $format): bool
    {
        $oracle = OracleCard::find($oracleCardId);
        $planeswalker = OracleCard::find($planeswalkerOracleCardId);

        if (! $oracle || ! $planeswalker) {
            return false;
        }

        $parsed = self::parsedForCardName($oracle->name);

        $results = CommandZoneService::searchOathbreaker(
            $format,
            $parsed,
            'spell',
            $planeswalker->color_identity,
            false,
            $planeswalkerOracleCardId,
        );

        return $results->contains('id', $oracleCardId);
    }

    /**
     * Validate that a companion is a legal pairing for the given commander.
     *
     * Resolves the commander's companion type and searches for the companion
     * using the appropriate filter.
     */
    public static function isLegalCompanion(string $companionOracleCardId, string $commanderOracleCardId, CardFormat $format): bool
    {
        $commander = OracleCard::with(['faces' => fn ($q) => $q->orderBy('face_index')])->find($commanderOracleCardId);

        if (! $commander) {
            return false;
        }

        $allOracleText = $commander->faces->pluck('oracle_text')->implode("\n");
        $frontTypeLine = $commander->faces->first()?->type_line ?? '';
        $companion = CommandZoneService::resolveCompanionType($allOracleText, $frontTypeLine);

        if (! $companion['type']) {
            return false;
        }

        $filters = [
            'rule0' => false,
            'partner' => $companion['type'] === 'partner',
            'friends_forever' => $companion['type'] === 'friends_forever',
            'doctors_companion' => $companion['type'] === 'doctors_companion',
            'background' => $companion['type'] === 'background',
            'partner_type' => $companion['type'] === 'partner_type' ? $companion['partner_with_name'] : null,
            'exclude' => $commanderOracleCardId,
        ];

        // For "partner_with" the companion is predetermined — just check name.
        if ($companion['type'] === 'partner_with') {
            $partnerOracle = OracleCard::where('name', $companion['partner_with_name'])->first();

            return $partnerOracle && $partnerOracle->id === $companionOracleCardId;
        }

        $companionOracle = OracleCard::find($companionOracleCardId);

        if (! $companionOracle) {
            return false;
        }

        $parsed = self::parsedForCardName($companionOracle->name);
        $results = CommandZoneService::searchCommanders($format, $parsed, $filters);

        return $results->contains('id', $companionOracleCardId);
    }

    /**
     * Create a new deck with optional command zone cards.
     *
     * Validates command zone cards against format rules via CommandZoneService
     * search methods. Aborts with 422 if any validation fails.
     *
     * @param  array{format: string, deck_name: string, deck_description?: string|null, commander_id?: string|null, companion_id?: string|null, signature_spell_id?: string|null}  $data
     */
    public static function createDeck(User $user, array $data): Deck
    {
        $format = CardFormat::from($data['format']);

        $deck = Deck::create([
            'user_id' => $user->id,
            'name' => $data['deck_name'],
            'description' => $data['deck_description'] ?? null,
            'format' => $format,
            'colors' => null,
        ]);

        self::setCommandZone(
            $deck,
            $data['commander_id'] ?? null,
            $data['companion_id'] ?? null,
            $data['signature_spell_id'] ?? null,
        );

        return $deck;
    }

    /**
     * Replace the deck's command zone with the given oracle cards.
     *
     * Validates legality + pairing against the deck's format, detaches every
     * existing commander pivot row, attaches the new ones (commander +
     * optional partner-type companion or signature spell), and recomputes
     * the deck's `colors` from the combined color identity of the new
     * command zone. Wrapped in a transaction so a partial failure rolls
     * back cleanly.
     *
     * No-op for formats that don't use a command zone (early-returns when
     * `requiresCommander()` is false). The deck's Magic-keyword companion
     * (`companion_oracle_card_id`) is intentionally left alone — that's a
     * separate slot from the partner-type companion attached via the
     * `commanders` pivot.
     */
    public static function setCommandZone(
        Deck $deck,
        ?string $commanderOracleId,
        ?string $companionOracleId,
        ?string $signatureSpellOracleId,
    ): void {
        $format = $deck->format;
        $profile = $format->rules();

        if (! $profile->requiresCommander() || $commanderOracleId === null) {
            return;
        }

        // Validate command zone cards against format rules.
        if ($profile->hasSignatureSpell()) {
            abort_unless(self::isLegalOathbreaker($commanderOracleId, $format), 422, 'Invalid planeswalker.');

            if ($signatureSpellOracleId) {
                abort_unless(
                    self::isLegalSignatureSpell($signatureSpellOracleId, $commanderOracleId, $format),
                    422,
                    'Invalid signature spell.',
                );
            }
        } else {
            abort_unless(self::isLegalCommander($commanderOracleId, $format), 422, 'Invalid commander.');

            if ($companionOracleId) {
                abort_unless(
                    self::isLegalCompanion($companionOracleId, $commanderOracleId, $format),
                    422,
                    'Invalid companion.',
                );
            }
        }

        DB::transaction(function () use ($deck, $profile, $commanderOracleId, $companionOracleId, $signatureSpellOracleId): void {
            // Wipe any existing command zone before attaching the new one
            // so partner/spell flips between formats don't leave stragglers.
            $deck->commanders()->detach();

            $commanderDefault = self::newestDefaultCard($commanderOracleId);
            abort_unless($commanderDefault !== null, 422, 'No printing found for commander.');
            $deck->commanders()->attach($commanderOracleId, [
                'default_card_id' => $commanderDefault->id,
                'is_partner' => false,
            ]);

            if ($profile->hasSignatureSpell() && $signatureSpellOracleId) {
                $spellDefault = self::newestDefaultCard($signatureSpellOracleId);
                abort_unless($spellDefault !== null, 422, 'No printing found for signature spell.');
                $deck->commanders()->attach($signatureSpellOracleId, [
                    'default_card_id' => $spellDefault->id,
                    'is_partner' => true,
                ]);
            } elseif ($companionOracleId) {
                $companionDefault = self::newestDefaultCard($companionOracleId);
                abort_unless($companionDefault !== null, 422, 'No printing found for companion.');
                $deck->commanders()->attach($companionOracleId, [
                    'default_card_id' => $companionDefault->id,
                    'is_partner' => true,
                ]);
            }

            // Derive deck colors from the combined color identity of the
            // new command zone. Per-card legality (color_identity) is
            // recomputed downstream by DeckValidator on the next page load.
            $commandZoneIds = array_filter([$commanderOracleId, $companionOracleId, $signatureSpellOracleId]);
            $identities = OracleCard::whereIn('id', $commandZoneIds)->pluck('color_identity');
            $merged = collect($identities)
                ->filter()
                ->flatMap(fn (string $ci) => str_split($ci))
                ->unique()
                ->sort(fn (string $a, string $b) => array_search($a, ['W', 'U', 'B', 'R', 'G']) - array_search($b, ['W', 'U', 'B', 'R', 'G']))
                ->implode('');
            $deck->update(['colors' => $merged ?: null]);
        });

        // Detaching the prior command zone may have removed the printing
        // the hero image points at; clear it if it's now orphaned.
        $deck->syncHeroImage();
    }
}
