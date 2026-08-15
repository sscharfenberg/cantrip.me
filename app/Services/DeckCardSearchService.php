<?php

namespace App\Services;

use App\Companions\CompanionRegistry;
use App\Enums\Finish;
use App\Formats\FormatProfile;
use App\Models\Deck;
use App\Models\DefaultCard;
use App\Models\OracleCard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Searches for cards eligible to be added to a deck.
 *
 * Two public entry points, same response shape, different use cases:
 *
 *  - {@see searchOracleForDeck} — oracle-level search. Ignores any
 *    `set:` / `cn:` tokens and returns distinct oracle cards with their
 *    newest printing auto-resolved. Used by the quick-add input so the
 *    user types a name and picks an oracle card without worrying about
 *    which printing to show.
 *  - {@see searchPrintingsForDeck} — printing-level search. Honors
 *    `set:` / `cn:` tokens and can return multiple printings of the
 *    same oracle card. Used by the full card-add modal where the user
 *    explicitly picks a specific printing. An optional `includeNonLegal`
 *    flag drops the format-legality filter while keeping color identity
 *    enforcement — for when the user wants to sleeve up something banned
 *    in a Rule 0 / kitchen table context.
 *
 * Both paths:
 *  - Filter by commander color identity when the format enforces it
 *  - AND-match every normalized query segment against `searchable_name`
 *  - Rank exact > prefix > contains on the first segment
 *
 * Legality is applied unconditionally in the oracle path and conditionally
 * in the printings path.
 */
final class DeckCardSearchService
{
    public const DEFAULT_LIMIT = 20;

    public const PRINTINGS_LIMIT = 100;

    /**
     * Candidate pool size for the quick-add oracle search. Over-fetched so
     * that filtering out cards already at the format's per-card max still
     * leaves a usable page of results when the deck happens to contain many
     * cards matching the query (e.g. searching "land" in a deck with 30+
     * lands already in the 99).
     */
    public const QUICK_ADD_CANDIDATE_LIMIT = 100;

    /**
     * Upper bound on the number of oracle cards considered in phase 1 of
     * {@see searchPrintingsForDeck}. Each oracle has a handful of printings,
     * so 200 oracles maps to ~1–2k printings — comfortably above the 60-row
     * PRINTINGS_LIMIT, while keeping the IN(…) clause small enough that
     * MariaDB picks the FK index over a hash join.
     */
    public const ORACLE_PREFILTER_LIMIT = 200;

    /**
     * Oracle-level search — returns up to $limit distinct oracle cards,
     * each with its newest printing resolved.
     *
     * Any `set:` / `cn:` tokens in the query are ignored; this path is
     * about picking a card by name, not a specific printing.
     *
     * The newest printing is fetched in a single batched query rather than
     * via an Eloquent `hasOneOfMany` relationship, because Laravel's
     * `ofMany` builder can't express "aggregate on a joined table column"
     * without generating an invalid dotted SQL alias.
     *
     * @return array<int, array{
     *     oracle_id: string,
     *     name: string,
     *     cmc: float,
     *     color_identity: string|null,
     *     printing: array{
     *         id: string,
     *         card_image_0: string|null,
     *         card_image_1: string|null,
     *         set_code: string,
     *         collector_number: string
     *     }|null
     * }>
     */
    public static function searchOracleForDeck(Deck $deck, string $rawQuery, int $limit = self::DEFAULT_LIMIT): array
    {
        $parsed = CardSearchParser::parse($rawQuery);
        if (! $parsed) {
            return [];
        }

        $query = OracleCard::query()->legalIn($deck->format);

        self::applyColorIdentityFilter($query, $deck);
        OracleNameSearch::applyMultiTableNameSegments($query, $parsed['normalized_name_segments']);
        self::applyNameRanking($query, 'oracle_cards.searchable_name', 'oracle_cards.name', $parsed['normalized_name_segments']);

        $oracleCards = $query
            ->select('oracle_cards.id', 'oracle_cards.name', 'oracle_cards.cmc', 'oracle_cards.color_identity', 'oracle_cards.searchable_name')
            ->limit($limit)
            ->get();

        if ($oracleCards->isEmpty()) {
            return [];
        }

        $newestPrintings = self::fetchNewestPrintings($oracleCards->pluck('id')->all());

        return $oracleCards
            ->map(fn (OracleCard $card): array => [
                'oracle_id' => $card->id,
                'name' => $card->name,
                'cmc' => (float) $card->cmc,
                'color_identity' => $card->color_identity,
                'printing' => $newestPrintings[$card->id] ?? null,
            ])
            ->all();
    }

    /**
     * Batch-fetch the newest printing for each given oracle card id.
     *
     * "Newest" is decided by `sets.released_at` (desc), with `default_cards.id`
     * as a deterministic tie-breaker. Returns a map keyed by oracle_id so the
     * caller can look up each printing in O(1) when assembling results.
     *
     * @param  string[]  $oracleIds
     * @return array<string, array{id: string, card_image_0: string|null, card_image_1: string|null, set_code: string, collector_number: string}>
     */
    private static function fetchNewestPrintings(array $oracleIds): array
    {
        if ($oracleIds === []) {
            return [];
        }

        $rows = DB::table('default_cards as dc')
            ->join('sets as s', 'dc.set_id', '=', 's.id')
            ->whereIn('dc.oracle_id', $oracleIds)
            ->select(
                'dc.id',
                'dc.oracle_id',
                'dc.card_image_0',
                'dc.card_image_1',
                'dc.collector_number',
                's.code as set_code',
                's.released_at',
            )
            ->orderBy('s.released_at', 'desc')
            ->orderBy('dc.id', 'desc')
            ->get();

        $printings = [];
        foreach ($rows as $row) {
            if (! isset($printings[$row->oracle_id])) {
                $printings[$row->oracle_id] = [
                    'id' => $row->id,
                    'card_image_0' => $row->card_image_0,
                    'card_image_1' => $row->card_image_1,
                    'set_code' => $row->set_code,
                    'collector_number' => $row->collector_number,
                ];
            }
        }

        return $printings;
    }

    /**
     * Quick-add oracle search — returns oracle cards with each face's
     * `type_line` and `mana_cost`, shaped like the commander-search response.
     *
     * Honors `set:` / `cn:` tokens so the user can narrow by printing
     * provenance even though the result itself is oracle-level (a single
     * row per oracle card, not per printing).
     *
     * Two-phase performance pattern: SQL handles only the cheap, indexed
     * predicates on `oracle_cards` (name, legality, color identity), with
     * `set:` / `cn:` pushed into a `whereHas('defaults')` correlated
     * subquery. Faces are eager-loaded with the minimum columns needed for
     * the response so the mapper doesn't trigger N+1 queries.
     *
     * @return array<int, array{
     *     id: string,
     *     name: string,
     *     color_identity: string|null,
     *     default_card_id: string|null,
     *     is_basic_land: bool,
     *     has_unlimited_copies: bool,
     *     faces: array<int, array{type_line: string|null, mana_cost: string|null}>
     * }>
     */
    public static function searchOracleCardsForDeck(Deck $deck, string $rawQuery, int $limit = self::DEFAULT_LIMIT): array
    {
        $parsed = CardSearchParser::parse($rawQuery);
        if (! $parsed) {
            return [];
        }

        $query = OracleCard::query()->legalIn($deck->format);

        self::applyColorIdentityFilter($query, $deck);
        OracleNameSearch::applyMultiTableNameSegments($query, $parsed['normalized_name_segments']);

        if ($parsed['set_code']) {
            $query->whereHas('defaults', fn (Builder $q) => $q->whereHas(
                'set',
                fn (Builder $sq) => $sq->where('code', $parsed['set_code'])
            ));
        }

        if ($parsed['collector_number']) {
            $query->whereHas(
                'defaults',
                fn (Builder $q) => $q->where('collector_number', $parsed['collector_number'])
            );
        }

        self::applyNameRanking($query, 'oracle_cards.searchable_name', 'oracle_cards.name', $parsed['normalized_name_segments']);

        // Over-fetch so the per-card-max filter below still produces a full
        // page when the deck already contains many matching cards.
        // `oracle_text` is included on the faces select so `hasUnlimitedCopiesRule`
        // (called inside canAddCopy) doesn't trigger an N+1 lazy-load.
        $candidates = $query
            ->select('oracle_cards.id', 'oracle_cards.name', 'oracle_cards.color_identity')
            ->with(['faces' => fn ($q) => $q->select('oracle_card_id', 'face_index', 'mana_cost', 'type_line', 'oracle_text')
                ->orderBy('face_index')])
            ->limit(self::QUICK_ADD_CANDIDATE_LIMIT)
            ->get();

        if ($candidates->isEmpty()) {
            return [];
        }

        // Drop cards the deck already has at the format's per-card maximum:
        // singleton (already in the deck) for commander-likes, max-copies
        // (4 of any non-basic, non-unlimited card) for constructed. Pass `0`
        // for current deck size so a full deck doesn't blank the results —
        // deck-full feedback comes from the actual add flow.
        $copiesByOracleId = $deck->deckCards()
            ->whereIn('oracle_card_id', $candidates->pluck('id'))
            ->selectRaw('oracle_card_id, SUM(quantity) as total')
            ->groupBy('oracle_card_id')
            ->pluck('total', 'oracle_card_id');

        $profile = $deck->format->rules();

        $survivors = $candidates
            ->filter(fn (OracleCard $card): bool => $profile->canAddCopy(
                $card,
                (int) ($copiesByOracleId[$card->id] ?? 0),
                0,
            )->allowed)
            ->take($limit)
            ->values();

        // Resolve each survivor's newest printing so the quick-add click can
        // POST a `default_card_id` to /api/decks/{deck}/cards without an
        // intermediate lookup.
        $newestPrintings = self::fetchNewestPrintings($survivors->pluck('id')->all());

        return $survivors
            ->map(fn (OracleCard $card): array => [
                'id' => $card->id,
                'name' => $card->name,
                'color_identity' => $card->color_identity,
                'default_card_id' => $newestPrintings[$card->id]['id'] ?? null,
                // Cards exempt from per-card copy limits — the frontend uses these
                // to decide when a result row should stick around past the max.
                'is_basic_land' => in_array($card->name, FormatProfile::BASIC_LANDS, true),
                'has_unlimited_copies' => $card->hasUnlimitedCopiesRule(),
                'faces' => $card->faces->map(fn ($face) => [
                    'type_line' => $face->type_line,
                    'mana_cost' => $face->mana_cost,
                ])->values()->all(),
            ])
            ->all();
    }

    /**
     * Printing-level search — returns all matching printings.
     * Filters push into the related oracle card so legality + CI still apply.
     *
     * Honors `set:` / `cn:` tokens from the query so the user can pin
     * results to a specific printing. When `$includeNonLegal` is true,
     * both the format-legality filter AND the color-identity filter are
     * dropped — it's the full Rule 0 / kitchen-table escape hatch.
     *
     * @return array<int, array{
     *     oracle_id: string,
     *     name: string,
     *     cmc: float,
     *     color_identity: string|null,
     *     printing: array{
     *         id: string,
     *         name: string,
     *         card_image_0: string|null,
     *         card_image_1: string|null,
     *         artist: string|null,
     *         cn: string,
     *         finishes: array<string>,
     *         set: array{name: string, code: string, path: string|null}|null
     *     }
     * }>
     */
    public static function searchPrintingsForDeck(Deck $deck, string $rawQuery, bool $includeNonLegal = false): array
    {
        $parsed = CardSearchParser::parse($rawQuery);
        if (! $parsed) {
            return [];
        }

        // Resolve the companion's profile once (if any). Each result is
        // probed against `failsAddingCard()` so the frontend can render a
        // soft warning badge on cards that would break the rule. Post-
        // consolidation, `$deck->companion` is a DeckCard row; the profile
        // lookup wants the underlying OracleCard.
        $deck->loadMissing('companion.oracleCard');
        $companionOracle = $deck->companion?->oracleCard;
        $companionProfile = $companionOracle !== null
            ? CompanionRegistry::profileFor($companionOracle)
            : null;

        // Two-phase search to avoid the `LIKE '%term%'` running on the much
        // larger default_cards table (the leading wildcard prevents any
        // index from helping, so a direct query forced a full table scan
        // followed by per-row EXISTS evaluation — multi-second for common
        // terms like "face").
        //
        // Phase 1: filter on oracle_cards. CardNameNormalizer runs on both
        // import paths (OracleCardsService, DefaultCardsService) using the
        // same pipeline, so an oracle whose searchable_name matches always
        // has matching printings — and oracle_cards is ~30k rows, so even
        // a leading-wildcard scan completes in milliseconds. We pull the
        // full oracle row (and faces, when a companion is set) so phase 2
        // doesn't need to re-fetch it as an eager load on each printing.
        $oracleQuery = OracleCard::query();
        if (! $includeNonLegal) {
            $oracleQuery->legalIn($deck->format);
            self::applyColorIdentityFilter($oracleQuery, $deck);
        }
        OracleNameSearch::applyMultiTableNameSegments($oracleQuery, $parsed['normalized_name_segments']);

        // Push `set:` / `cn:` down to phase 1 so the 200-oracle prefilter only
        // considers oracles that actually have a matching printing. Without
        // this, a token-only query like `cn:144` returns the first 200 oracles
        // in arbitrary order, and most don't have a cn=144 printing, so phase
        // 2's filter leaves a tiny, biased result set. Mirrors the pattern in
        // {@see searchOracleCardsForDeck}.
        if ($parsed['set_code']) {
            $oracleQuery->whereHas('defaults', fn (Builder $q) => $q->whereHas(
                'set',
                fn (Builder $sq) => $sq->where('code', $parsed['set_code'])
            ));
        }
        if ($parsed['collector_number']) {
            $oracleQuery->whereHas(
                'defaults',
                fn (Builder $q) => $q->where('collector_number', $parsed['collector_number'])
            );
        }

        if ($companionProfile !== null) {
            $oracleQuery->with('faces:oracle_card_id,face_index,type_line,mana_cost,oracle_text');
        }

        // Order phase 1 deterministically so the 200-oracle slice is stable.
        // For name queries, this ranks exact > prefix > contains; for token-
        // only queries, it falls through to the alphabetical tiebreaker.
        self::applyNameRanking(
            $oracleQuery,
            'oracle_cards.searchable_name',
            'oracle_cards.name',
            $parsed['normalized_name_segments']
        );

        /** @var Collection<string, OracleCard> $oraclesById */
        $oraclesById = $oracleQuery
            ->limit(self::ORACLE_PREFILTER_LIMIT)
            ->get(['oracle_cards.id', 'oracle_cards.name', 'oracle_cards.searchable_name', 'oracle_cards.cmc', 'oracle_cards.color_identity'])
            ->keyBy('id');

        if ($oraclesById->isEmpty()) {
            return [];
        }

        // Per-oracle "matched by translated name" badge — only present
        // when the English searchable_name didn't already explain the
        // match. Attached to each printing below so the frontend can
        // render the foreign printed_name + flag without a separate
        // lookup. Empty when no name segments were provided (pure
        // set:/cn: query has nothing to match against translations).
        $matchedTranslations = OracleNameSearch::resolveMatchedTranslations(
            $oraclesById->mapWithKeys(fn (OracleCard $oc) => [$oc->id => (string) $oc->searchable_name])->all(),
            $parsed['normalized_name_segments'],
            Auth::user()?->locale->value,
        );

        // Phase 2: load printings for those oracle ids via the query builder
        // with explicit joins to `sets` and `artists`, bypassing Eloquent
        // hydration entirely. A 60-row result set means we'd otherwise be
        // building 60 DefaultCard models + their `set` and `artist` relations,
        // which dominates the request's PHP time once the SQL is fast. The
        // result mapping resolves name/cmc/color_identity from the phase-1
        // OracleCard map (still Eloquent — small, and faces are needed for
        // the companion-violation probe).
        $query = DB::table('default_cards')
            ->leftJoin('sets', 'sets.id', '=', 'default_cards.set_id')
            ->leftJoin('artists', 'artists.id', '=', 'default_cards.artist_id')
            ->whereIn('default_cards.oracle_id', $oraclesById->keys());

        if ($parsed['set_code']) {
            $query->where('sets.code', $parsed['set_code']);
        }

        if ($parsed['collector_number']) {
            $query->where('default_cards.collector_number', $parsed['collector_number']);
        }

        $first = $parsed['normalized_name_segments'][0] ?? null;
        if ($first !== null) {
            $query->orderByRaw(
                'CASE
                    WHEN default_cards.searchable_name = ? THEN 0
                    WHEN default_cards.searchable_name LIKE ? THEN 1
                    ELSE 2
                END',
                [$first, $first.'%']
            );
        }
        $query->orderByRaw('CHAR_LENGTH(default_cards.name)')->orderBy('default_cards.name');

        $rows = $query
            ->limit(self::PRINTINGS_LIMIT)
            ->get([
                'default_cards.id',
                'default_cards.oracle_id',
                'default_cards.name as printing_name',
                'default_cards.card_image_0',
                'default_cards.card_image_1',
                'default_cards.collector_number',
                'default_cards.finishes',
                'sets.name as set_name',
                'sets.code as set_code',
                'sets.path as set_path',
                'artists.name as artist_name',
            ]);

        return $rows
            ->map(function (object $row) use ($oraclesById, $companionProfile, $deck, $matchedTranslations): array {
                $oracle = $oraclesById->get($row->oracle_id);

                return [
                    'oracle_id' => $row->oracle_id,
                    'name' => $oracle?->name ?? $row->printing_name,
                    'cmc' => (float) ($oracle?->cmc ?? 0),
                    'color_identity' => $oracle?->color_identity,
                    'violates_companion' => $companionProfile !== null
                        && $oracle !== null
                        && $companionProfile->failsAddingCard($deck, $oracle),
                    'printing' => [
                        'id' => $row->id,
                        'name' => $row->printing_name,
                        'card_image_0' => $row->card_image_0,
                        'card_image_1' => $row->card_image_1,
                        'artist' => $row->artist_name,
                        'cn' => $row->collector_number,
                        'finishes' => Finish::labelsFromMask((int) $row->finishes),
                        'set' => $row->set_code !== null ? [
                            'name' => $row->set_name,
                            'code' => $row->set_code,
                            'path' => $row->set_path,
                        ] : null,
                        'matched_translation' => $matchedTranslations[$row->oracle_id] ?? null,
                    ],
                ];
            })
            ->all();
    }

    /**
     * Constrain the query to cards inside the deck's color identity.
     *
     * Only applies when the format enforces color identity (Commander,
     * Oathbreaker, Brawl, etc.). `$deck->colors` holds the identity for
     * commander formats. Empty `color_identity` (colorless) is always
     * allowed — the regex `^[WUBRG]*$` matches the empty string.
     *
     * @param  Builder<OracleCard>  $query
     */
    private static function applyColorIdentityFilter(Builder $query, Deck $deck): void
    {
        $profile = $deck->format->rules();
        if (! $profile->enforcesColorIdentity()) {
            return;
        }

        $colors = $deck->colors ?? '';
        // Whitelist to WUBRG to defeat any injection; `colors` is already
        // enum-like in the app but this is the regex character class.
        $safeColors = preg_replace('/[^WUBRG]/', '', $colors) ?? '';

        $query->where(function (Builder $q) use ($safeColors): void {
            $q->whereNull('color_identity')
                ->orWhere('color_identity', '');

            // Only add the REGEXP branch when the deck actually has colors.
            // For colorless decks the NULL / empty checks above are enough,
            // and an empty character class (`[]`) is invalid regex anyway.
            if ($safeColors !== '') {
                $q->orWhereRaw('color_identity REGEXP ?', ['^['.$safeColors.']*$']);
            }
        });
    }

    /**
     * Order by exact/prefix/contains rank on the first segment, then by
     * name length (shortest wins), then alphabetical.
     *
     * @param  Builder<OracleCard>  $query
     * @param  string[]  $segments
     */
    private static function applyNameRanking(Builder $query, string $searchableColumn, string $nameColumn, array $segments): void
    {
        if ($segments !== []) {
            // Ranked against the WHOLE normalized query, not just the first
            // segment. For a one-word query the two are identical; for
            // "lightning bolt" only the whole string can ever be an exact
            // match, so the old first-segment form could never fire on a
            // multi-word name.
            $full = implode(' ', $segments);

            // Translations rank alongside the English name, because a card
            // matched ONLY through a translation otherwise scores nothing and
            // falls through to the length tiebreaker — i.e. gets ordered by
            // how long its ENGLISH name happens to be, which is unrelated to
            // the query. Searching "稲妻" (→ "dao qi", a unique translation
            // belonging to exactly one card) returned twenty other cards and
            // not Lightning Bolt, purely because "Lightning Bolt" is 14
            // characters and "Seeker" is 6.
            //
            // Both branches are index seeks on `(lang, searchable_name)`, so
            // this asks only the two questions that index can answer: equality
            // and prefix. A leading-wildcard rank would be a scan per row.
            $exactTranslation = OracleNameSearch::translationMatchesSql('=');
            $prefixTranslation = OracleNameSearch::translationMatchesSql('LIKE');

            $query->orderByRaw(
                "CASE
                    WHEN {$searchableColumn} = ? THEN 0
                    WHEN {$exactTranslation} THEN 0
                    WHEN {$searchableColumn} LIKE ? THEN 1
                    WHEN {$prefixTranslation} THEN 1
                    ELSE 2
                END",
                [$full, $full, $full.'%', $full.'%']
            );
        }

        $query->orderByRaw("CHAR_LENGTH({$nameColumn})")->orderBy($nameColumn);
    }
}
