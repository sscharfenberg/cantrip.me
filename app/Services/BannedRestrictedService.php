<?php

namespace App\Services;

use App\Enums\CardFormat;
use App\Enums\CardLegality;
use App\Enums\Scryfall\ScryfallCardLayout;
use App\Models\Deck;
use App\Models\OracleCard;
use App\Models\OracleCardFace;
use App\Models\OracleCardLegality;
use Illuminate\Support\Facades\Cache;

/**
 * Banned and restricted lists for the public B&R page (`/b+r`).
 *
 * The lists come straight from the `legalities` table (Scryfall's own
 * `banned` / `restricted` statuses), for every format {@see CardFormat}
 * knows — Scryfall formats without a case there stay hidden, as they do
 * everywhere else.
 *
 * Within a list, cards are grouped by colour in {@see self::GROUPS} order
 * (WUBRG, multicolor, colorless, lands) and sorted by name — except the
 * blanket bans, which Wizards' own lists name as one line each rather than
 * card by card: Conspiracies, ante cards, the cards removed for offensive
 * content, and cards that bring a sticker or an Attraction into the game.
 * Those go to {@see self::CATEGORIES} instead, see {@see self::category()}.
 * A card on several lists (Sol Ring, Black Lotus) is shipped once, in
 * `cards`, and the lists refer to it by oracle id — that halves the page
 * payload. It is pictured by its first regular paper printing
 * ({@see FirstPrintingService}), as the welcome page's lookup does.
 *
 * The lists only change with the Scryfall dataset, so they are cached and
 * re-warmed by `scryfall:cache` at the end of every `scryfall:update`. No
 * deploy clears the cache, so the key carries a version: bump it whenever
 * the cached shape changes, or the new frontend gets the old shape until
 * the next sync. An empty result is never cached — it means the dataset is
 * missing or mid-truncate (a standalone `scryfall:oracle`), not that no
 * card is banned. Each format's deck count on cantrip.me — which orders the
 * tabs — changes with user activity and is read per request from one GROUP
 * BY. The page sorts by it: the tie-break is the format's translated name,
 * which only the frontend has.
 */
class BannedRestrictedService
{
    public const CACHE_KEY = 'bannedRestricted.lists.v3';

    /** Display order of the colour groups. */
    public const GROUPS = ['W', 'U', 'B', 'R', 'G', 'multicolor', 'colorless', 'land'];

    /** Display order of the blanket-ban categories — the order Wizards lists them in. */
    public const CATEGORIES = ['conspiracy', 'ante', 'offensive', 'sticker'];

    /**
     * The cards Wizards banned in every format in June 2020 for racist or
     * culturally offensive art, text or names. Nothing in Scryfall's data
     * marks them, so unlike the other categories this one is a hand-kept
     * list: a card Wizards adds to it shows as an ordinary ban until its
     * name is added here.
     */
    private const OFFENSIVE_CARDS = [
        'Cleanse',
        'Crusade',
        'Imprison',
        'Invoke Prejudice',
        'Jihad',
        'Pradesh Gypsies',
        'Stone-Throwing Devils',
    ];

    /**
     * Layouts with two physical faces: only the front decides colour, as it
     * does in every zone but the battlefield.
     */
    private const DOUBLE_FACED_LAYOUTS = [
        ScryfallCardLayout::Transform->value,
        ScryfallCardLayout::ModalDfc->value,
        ScryfallCardLayout::Meld->value,
        ScryfallCardLayout::ReversibleCard->value,
        ScryfallCardLayout::DoubleFacedToken->value,
    ];

    /**
     * Payload for the B&R page.
     *
     * @return array{
     *     formats: list<array{
     *         format: string,
     *         decks: int,
     *         banned: array{groups: list<array{group: string, cards: list<string>}>, categories: list<array{category: string, cards: list<string>}>},
     *         restricted: array{groups: list<array{group: string, cards: list<string>}>, categories: list<array{category: string, cards: list<string>}>},
     *     }>,
     *     cards: array<string, array{name: string, mana_cost: list<string|null>, printing: array{id: string, image: string|null}|null}>,
     * }
     */
    public static function forPage(): array
    {
        $lists = Cache::get(self::CACHE_KEY) ?? self::cacheLists();

        $deckCounts = self::deckCounts();
        $empty = ['groups' => [], 'categories' => []];
        $formats = array_map(fn (CardFormat $format): array => [
            'format' => $format->value,
            'decks' => $deckCounts[$format->value] ?? 0,
            'banned' => $lists['formats'][$format->value][CardLegality::Banned->value] ?? $empty,
            'restricted' => $lists['formats'][$format->value][CardLegality::Restricted->value] ?? $empty,
        ], CardFormat::cases());

        return ['formats' => $formats, 'cards' => $lists['cards']];
    }

    /**
     * Rebuild the cached lists. Called by `scryfall:cache` so the first
     * visitor after a Scryfall sync doesn't pay for the build.
     */
    public static function warmCache(): void
    {
        self::cacheLists();
    }

    /**
     * Build the lists and cache them for a day — unless they came back
     * empty, see the class docblock.
     *
     * @return array{formats: array<string, array<string, array<string, list<array<string, mixed>>>>>, cards: array<string, array<string, mixed>>}
     */
    private static function cacheLists(): array
    {
        $lists = self::buildLists();
        if ($lists['cards'] !== []) {
            Cache::put(self::CACHE_KEY, $lists, now()->addDay());
        }

        return $lists;
    }

    /**
     * Which of {@see self::GROUPS} a card is listed under.
     *
     * A land goes under lands whatever its colour (Dryad Arbor). Otherwise
     * the card's colours decide — the front face's for a double-faced card,
     * every face's for one that shares a single frame (split, adventure).
     * Scryfall keeps the latter's colours on the card rather than on its
     * halves, and the importer stores face colours only, so for those the
     * halves' mana costs stand in. A single-faced card is never read from
     * its mana cost: devoid cards (Inverter of Truth) have a coloured cost
     * and no colour.
     *
     * @param  list<array{mana_cost: string|null, type_line: string, colors: string|null}>  $faces  Front first.
     */
    public static function colorGroup(string $layout, array $faces): string
    {
        if ($faces === []) {
            return 'colorless';
        }
        if (preg_match('/\bLand\b/', $faces[0]['type_line'])) {
            return 'land';
        }

        $counted = in_array($layout, self::DOUBLE_FACED_LAYOUTS, true) ? [$faces[0]] : $faces;
        $colors = implode('', array_map(fn (array $face): string => (string) $face['colors'], $counted));
        if ($colors === '' && count($counted) > 1) {
            $colors = implode('', array_map(fn (array $face): string => (string) $face['mana_cost'], $counted));
        }

        preg_match_all('/[WUBRG]/', $colors, $matches);
        $distinct = array_values(array_unique($matches[0]));

        return match (count($distinct)) {
            0 => 'colorless',
            1 => $distinct[0],
            default => 'multicolor',
        };
    }

    /**
     * Which of {@see self::CATEGORIES} a card belongs to, or null for a card
     * Wizards bans by name. All but the offensive-content category are read
     * off the card, so a newly printed sticker card files itself:
     *
     *   - `conspiracy` — the card type Conspiracy.
     *   - `ante` — rules text that mentions ante ("…if you're not playing
     *     for ante").
     *   - `offensive` — {@see self::OFFENSIVE_CARDS}.
     *   - `sticker` — an Attraction, or rules text that puts a sticker or
     *     opens an Attraction.
     *
     * @param  list<array{type_line: string, oracle_text: string|null}>  $faces  Front first.
     */
    public static function category(string $name, array $faces): ?string
    {
        $types = implode(' // ', array_column($faces, 'type_line'));
        $text = implode(' // ', array_map(fn (array $face): string => (string) $face['oracle_text'], $faces));

        return match (true) {
            preg_match('/\bConspiracy\b/', $types) === 1 => 'conspiracy',
            preg_match('/\bante\b/i', $text) === 1 => 'ante',
            in_array($name, self::OFFENSIVE_CARDS, true) => 'offensive',
            preg_match('/\bAttraction\b/', $types) === 1,
            preg_match('/\bstickers?\b|\bAttractions?\b/i', $text) === 1 => 'sticker',
            default => null,
        };
    }

    /**
     * Number of decks per format value, across every user.
     *
     * @return array<string, int>
     */
    private static function deckCounts(): array
    {
        return Deck::query()
            ->toBase()
            ->selectRaw('format, COUNT(*) as decks')
            ->groupBy('format')
            ->pluck('decks', 'format')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
    }

    /**
     * Build the grouped lists and the cards they refer to.
     *
     * Reads through `toBase()` so no model casts run: an oracle card whose
     * layout {@see ScryfallCardLayout} doesn't know yet must not break the
     * page.
     *
     * @return array{
     *     formats: array<string, array<string, array{groups: list<array{group: string, cards: list<string>}>, categories: list<array{category: string, cards: list<string>}>}>>,
     *     cards: array<string, array{name: string, mana_cost: list<string|null>, printing: array{id: string, image: string|null}|null}>,
     * }
     */
    private static function buildLists(): array
    {
        $entries = OracleCardLegality::query()
            ->toBase()
            ->whereIn('format', array_map(fn (CardFormat $format): string => $format->value, CardFormat::cases()))
            ->whereIn('legality', [CardLegality::Banned->value, CardLegality::Restricted->value])
            ->get(['oracle_card_id', 'format', 'legality']);

        $oracleIds = $entries->pluck('oracle_card_id')->unique()->values()->all();
        if ($oracleIds === []) {
            return ['formats' => [], 'cards' => []];
        }

        $oracles = OracleCard::query()
            ->toBase()
            ->whereIn('id', $oracleIds)
            ->get(['id', 'name', 'searchable_name', 'layout'])
            ->keyBy('id');
        $faces = OracleCardFace::query()
            ->toBase()
            ->whereIn('oracle_card_id', $oracleIds)
            ->orderBy('face_index')
            ->get(['oracle_card_id', 'mana_cost', 'type_line', 'oracle_text', 'colors'])
            ->groupBy('oracle_card_id');
        $printings = FirstPrintingService::forOracles($oracleIds);

        $cards = [];
        $groupOf = [];
        $categoryOf = [];
        foreach ($oracles as $id => $oracle) {
            $cardFaces = ($faces[$id] ?? collect())
                ->map(fn (object $face): array => [
                    'mana_cost' => $face->mana_cost,
                    'type_line' => (string) $face->type_line,
                    'oracle_text' => $face->oracle_text,
                    'colors' => $face->colors,
                ])
                ->values()
                ->all();
            $groupOf[$id] = self::colorGroup((string) $oracle->layout, $cardFaces);
            $categoryOf[$id] = self::category((string) $oracle->name, $cardFaces);
            $cards[$id] = [
                'name' => $oracle->name,
                'mana_cost' => array_column($cardFaces, 'mana_cost'),
                'printing' => $printings[$id] ?? null,
            ];
        }

        // searchable_name sorts "Æther Vial" under A, where the display name
        // would put it after Z.
        $sorted = $entries
            ->filter(fn (object $entry): bool => isset($cards[$entry->oracle_card_id]))
            ->sortBy(fn (object $entry): string => (string) $oracles[$entry->oracle_card_id]->searchable_name, SORT_STRING);

        $buckets = [];
        foreach ($sorted as $entry) {
            $id = $entry->oracle_card_id;
            if ($categoryOf[$id] !== null) {
                $buckets[$entry->format][$entry->legality]['categories'][$categoryOf[$id]][] = $id;
            } else {
                $buckets[$entry->format][$entry->legality]['groups'][$groupOf[$id]][] = $id;
            }
        }

        $formats = [];
        foreach ($buckets as $format => $lists) {
            foreach ($lists as $legality => $list) {
                $formats[$format][$legality] = [
                    'groups' => self::inOrder($list['groups'] ?? [], self::GROUPS, 'group'),
                    'categories' => self::inOrder($list['categories'] ?? [], self::CATEGORIES, 'category'),
                ];
            }
        }

        return ['formats' => $formats, 'cards' => $cards];
    }

    /**
     * Turn `name → ids` buckets into a list in the given display order,
     * leaving out empty ones.
     *
     * @param  array<string, list<string>>  $buckets
     * @param  list<string>  $order
     * @return list<array<string, string|list<string>>>
     */
    private static function inOrder(array $buckets, array $order, string $key): array
    {
        $list = [];
        foreach ($order as $name) {
            if (isset($buckets[$name])) {
                $list[] = [$key => $name, 'cards' => $buckets[$name]];
            }
        }

        return $list;
    }
}
