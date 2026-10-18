<?php

namespace App\Services;

use App\Models\DefaultCard;
use Illuminate\Database\Eloquent\Builder;

/**
 * Picks the printing that pictures an oracle card where no printing is
 * implied — the welcome page's Oracle card lookup and the banned &
 * restricted lists: the card's first regular paper printing, see
 * {@see self::compare()}.
 *
 * Not the newest printing the deck pages default to. A card shown on its
 * own should look the way players know it — the Alpha Sol Ring, not the
 * latest Secret Lair. A plain "oldest first" would not do that either:
 * MTGO promos are dated 2002 and so predate most cards' first paper
 * printing, and store promos or serialized cards are often the oldest of a
 * set's printings.
 */
final class FirstPrintingService
{
    /**
     * Scryfall set types whose printings are a card as it was released:
     * the sets that make up the game, plus its preconstructed and joke
     * products. Left out are promos, Secret Lair and other boxed
     * collections, Masterpieces, memorabilia, tokens and digital-only sets.
     */
    private const REGULAR_SET_TYPES = [
        'core',
        'expansion',
        'masters',
        'draft_innovation',
        'commander',
        'starter',
        'duel_deck',
        'funny',
        'planechase',
        'archenemy',
        'eternal',
    ];

    /**
     * Each oracle card's first printing by {@see self::compare()}, among
     * the printings a `set:` / `number:` search names when it names any.
     * Cards without a (matching) printing are absent.
     *
     * @param  list<string>  $oracleIds
     * @return array<string, array{id: string, image: string|null}> Oracle id → printing.
     */
    public static function forOracles(array $oracleIds, ?string $setCode = null, ?string $collectorNumber = null): array
    {
        if ($oracleIds === []) {
            return [];
        }

        $query = DefaultCard::query()
            ->join('sets', 'sets.id', '=', 'default_cards.set_id')
            ->whereIn('default_cards.oracle_id', $oracleIds);
        self::narrow($query, $setCode, $collectorNumber);

        $first = [];
        foreach ($query->toBase()->get(['default_cards.id', 'default_cards.oracle_id', 'default_cards.collector_number', 'default_cards.digital', 'default_cards.card_image_0', 'sets.set_type', 'sets.released_at']) as $row) {
            $printing = [
                'id' => (string) $row->id,
                'image' => $row->card_image_0,
                'collector_number' => (string) $row->collector_number,
                'digital' => (bool) $row->digital,
                'set_type' => (string) $row->set_type,
                'released_at' => $row->released_at === null ? null : (string) $row->released_at,
            ];
            $current = $first[$row->oracle_id] ?? null;
            if ($current === null || self::compare($printing, $current) < 0) {
                $first[$row->oracle_id] = $printing;
            }
        }

        return array_map(fn (array $printing): array => ['id' => $printing['id'], 'image' => $printing['image']], $first);
    }

    /**
     * Order two printings of one card, the one to show first. In turn:
     *
     *   1. a paper printing in a {@see self::REGULAR_SET_TYPES} set, then
     *      any other paper printing, then digital ones;
     *   2. the earlier set release;
     *   3. within one set, the plain-numbered card before its variants:
     *      positive whole collector numbers first, lowest first — The One
     *      Ring's #246 before the serialized #0, Fable's #141 before the
     *      showcase #357, Davriel's #83 before the #83★ promo;
     *   4. the printing id, so the choice is stable.
     *
     * @param  array{id: string, collector_number: string, digital: bool, set_type: string, released_at: string|null}  $a
     * @param  array{id: string, collector_number: string, digital: bool, set_type: string, released_at: string|null}  $b
     */
    public static function compare(array $a, array $b): int
    {
        return [self::kindRank($a), $a['released_at'] ?? '9999-12-31', ...self::numberRank($a['collector_number']), $a['id']]
            <=> [self::kindRank($b), $b['released_at'] ?? '9999-12-31', ...self::numberRank($b['collector_number']), $b['id']];
    }

    /**
     * Restrict a printings query (rooted at `default_cards`) to the given
     * set and/or collector number.
     *
     * @param  Builder<DefaultCard>  $query
     */
    public static function narrow(Builder $query, ?string $setCode, ?string $collectorNumber): void
    {
        if ($setCode) {
            $query->whereHas('set', fn (Builder $set) => $set->where('code', $setCode));
        }
        if ($collectorNumber) {
            $query->where('default_cards.collector_number', $collectorNumber);
        }
    }

    /**
     * @param  array{digital: bool, set_type: string}  $printing
     */
    private static function kindRank(array $printing): int
    {
        if ($printing['digital']) {
            return 2;
        }

        return in_array($printing['set_type'], self::REGULAR_SET_TYPES, true) ? 0 : 1;
    }

    /**
     * A plain positive collector number sorts first, by value; anything
     * else (`0`, `141s`, `86★`, `2X2-107`) after it, by string.
     *
     * @return array{0: int, 1: int, 2: string}
     */
    private static function numberRank(string $collectorNumber): array
    {
        if (ctype_digit($collectorNumber) && (int) $collectorNumber > 0) {
            return [0, (int) $collectorNumber, ''];
        }

        return [1, 0, $collectorNumber];
    }
}
