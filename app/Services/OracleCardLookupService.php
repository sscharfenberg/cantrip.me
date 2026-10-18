<?php

namespace App\Services;

use App\Models\OracleCard;
use Illuminate\Database\Eloquent\Builder;

/**
 * The welcome page's Oracle card lookup: find oracle cards by name (English
 * or any printed language, ranked exact > prefix > contains), optionally
 * narrowed to a printing with `set:` / `number:`, and pick the printing
 * each result's detail modal shows.
 *
 * Deck-less, so unlike the deck quick add no legality, colour identity or
 * copy-limit filter applies — every oracle card can be looked up, except
 * the objects Scryfall files as oracle cards that are no cards to play
 * with: see {@see self::NON_CARD_LAYOUTS}.
 *
 * The printing is the card's first regular paper printing
 * ({@see FirstPrintingService}) — the Alpha Sol Ring, not the latest Secret
 * Lair. A `set:` / `number:` search shows the printing it names instead.
 */
final class OracleCardLookupService
{
    /** Most cards a lookup returns; past this the result says it was cut short. */
    public const RESULT_LIMIT = 50;

    /**
     * Layouts of oracle "cards" that are no cards: tokens and emblems, the
     * art series' "Card // Card" art cards, and the front cards of
     * checklist-style inserts. The quick add never meets them, because its
     * legality filter drops them; the lookup has no such filter. Planes,
     * schemes and Vanguards stay — they are oversized, but real cards.
     */
    private const NON_CARD_LAYOUTS = ['token', 'double_faced_token', 'emblem', 'art_series', 'front_card'];

    /**
     * Look up oracle cards for a search string.
     *
     * @return array{
     *     cards: list<array{
     *         id: string,
     *         name: string,
     *         color_identity: string|null,
     *         printing_id: string|null,
     *         faces: list<array{type_line: string|null, mana_cost: string|null}>,
     *     }>,
     *     truncated: bool,
     * }
     */
    public static function search(string $rawQuery): array
    {
        $parsed = CardSearchParser::parse($rawQuery);
        if (! $parsed) {
            return ['cards' => [], 'truncated' => false];
        }

        $query = OracleCard::query()->whereNotIn('oracle_cards.layout', self::NON_CARD_LAYOUTS);
        OracleNameSearch::applyMultiTableNameSegments($query, $parsed['normalized_name_segments']);
        // One printing has to match both tokens: `set:mh2 number:189` means
        // MH2 #189, not "printed in MH2, and #189 in some other set".
        if ($parsed['set_code'] || $parsed['collector_number']) {
            $query->whereHas('defaults', fn (Builder $printing) => FirstPrintingService::narrow(
                $printing,
                $parsed['set_code'],
                $parsed['collector_number'],
            ));
        }
        OracleNameSearch::applyNameRanking($query, $parsed['normalized_name_segments']);

        $cards = $query
            ->select('oracle_cards.id', 'oracle_cards.name', 'oracle_cards.color_identity')
            ->with(['faces' => fn ($faces) => $faces->select('oracle_card_id', 'face_index', 'mana_cost', 'type_line')
                ->orderBy('face_index')])
            ->limit(self::RESULT_LIMIT + 1)
            ->get();

        $truncated = $cards->count() > self::RESULT_LIMIT;
        $cards = $cards->take(self::RESULT_LIMIT);
        $printings = FirstPrintingService::forOracles($cards->pluck('id')->all(), $parsed['set_code'], $parsed['collector_number']);

        return [
            'cards' => $cards->map(fn (OracleCard $card): array => [
                'id' => $card->id,
                'name' => $card->name,
                'color_identity' => $card->color_identity,
                'printing_id' => $printings[$card->id]['id'] ?? null,
                'faces' => $card->faces->map(fn ($face): array => [
                    'type_line' => $face->type_line,
                    'mana_cost' => $face->mana_cost,
                ])->values()->all(),
            ])->values()->all(),
            'truncated' => $truncated,
        ];
    }
}
