<?php

namespace App\Services\Scryfall\OracleTagSyncs;

use App\Services\Scryfall\OracleTagsService;

/**
 * One Scryfall oracle-tag → `oracle_cards` column mapping. Each
 * concrete sync is a self-contained recipe consumed by
 * {@see OracleTagsService}, which handles the
 * paginated search, rate-limiting, and the clear-then-apply
 * transaction. Concrete syncs only describe:
 *
 *   - which tag to pull (`tag()`),
 *   - which column to write (`column()`),
 *   - what value to reset non-matched rows to (`clearValue()`),
 *   - how to derive each matched card's value (`deriveValue()`).
 *
 * Two flavors ship today:
 *
 *   - {@see BooleanOracleTagSync} — boolean column, presence in the
 *     tagger search means "true". Used for `mass-land-denial`
 *     → `oracle_cards.mld`.
 *   - {@see FetchPatternOracleTagSync} — string column, value derived
 *     by parsing each card's oracle text. Used for `fetchland`
 *     → `oracle_cards.fetch_pattern`.
 *
 * Add a new sync by implementing this class and registering an
 * instance in the orchestrator's constructor.
 */
abstract class OracleTagSync
{
    /** Scryfall otag slug (without the `otag:` prefix). */
    abstract public function tag(): string;

    /** Column on `oracle_cards` to write. */
    abstract public function column(): string;

    /**
     * Value to reset every row's column to before applying matched
     * values. Typed as `mixed` so concrete syncs can choose the
     * appropriate "absent" value for their column type — `false`
     * for boolean columns, `null` for nullable strings, etc.
     */
    abstract public function clearValue(): mixed;

    /**
     * Derive the column value for a single matched card. Return
     * `null` to skip the card entirely (the column stays at its
     * cleared value) — useful for tag matches that the per-card
     * derivation can't classify, e.g. a parser regression.
     *
     * @param  array<string, mixed>  $card  The Scryfall card payload from the search endpoint.
     */
    abstract public function deriveValue(array $card): mixed;
}
