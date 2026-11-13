<?php

namespace App\Services\Scryfall;

use App\Services\Scryfall\OracleTagSyncs\BooleanOracleTagSync;
use App\Services\Scryfall\OracleTagSyncs\FetchPatternOracleTagSync;
use App\Services\Scryfall\OracleTagSyncs\LandSearchPatternOracleTagSync;
use App\Services\Scryfall\OracleTagSyncs\OracleTagSync;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Sync Scryfall oracle-tagger flags onto `oracle_cards`. Tags are not
 * included in Scryfall's bulk data — they're only reachable via
 * `/cards/search?q=otag:<slug>`. This service paginates that endpoint
 * for every registered {@see OracleTagSync}, derives the per-card
 * column value via the strategy, and applies it inside a single
 * transaction (clear column → bulk-update grouped values).
 *
 * Each sync is processed independently — a failure on one tag never
 * touches another tag's column. A sync that fails, finds no cards (most
 * likely a Scryfall taxonomy rename) or cannot classify a single one of
 * them (Scryfall reworded the cards the parser reads) **keeps the
 * previous values**: standalone, the live column is left untouched; in a
 * `scryfall:update` shadow build, whose freshly imported table starts
 * out cleared, they are copied over from the live table. The run summary
 * names every column that was carried over.
 *
 * Rate-limiting is enforced at the service level so the ≥1s pacing
 * survives across multiple tag searches in the same run, not just
 * within a single tag's pagination loop.
 *
 * Add a new tag by registering its strategy in the constructor.
 */
class OracleTagsService extends ScryfallService
{
    /**
     * Maximum oracle ids per UPDATE — chosen well under MySQL's
     * 65 535-placeholder cap on prepared statements. Today's largest
     * tag (`mass-land-denial`) returns ~108 cards; the chunk-safe
     * path keeps headroom for future growth.
     */
    private const UPDATE_CHUNK = 1000;

    /**
     * Inter-request delay in seconds. Scryfall asks for 50–100 ms
     * minimum; we ship 1 s for ample headroom and zero rate-limit
     * surprises across pagination + multi-sync runs.
     */
    private const REQUEST_DELAY_SECONDS = 1;

    /**
     * Registered syncs, processed in order. Add new entries here:
     *
     *   - `BooleanOracleTagSync` for plain "is this card tagged"
     *     boolean columns (`mass-land-denial` → `mld`).
     *   - `FetchPatternOracleTagSync` (or any future
     *     {@see OracleTagSync} subclass) for tags that need a
     *     per-card derivation step.
     *   - `LandSearchPatternOracleTagSync` — the fetch-pattern parse
     *     over `tutor-land` non-lands (~360 cards, three pages).
     *
     * @var list<OracleTagSync>
     */
    private array $syncs;

    /**
     * Track whether any Scryfall request has been issued during this
     * service instance's lifetime. Used by {@see rateLimitedGet} to
     * enforce the inter-request delay across both paginated requests
     * inside one sync AND across distinct sync runs in the same
     * service invocation.
     */
    private bool $firstRequest = true;

    private string $oracleCardsTable = 'oracle_cards';

    private bool $shadow = false;

    public function __construct()
    {
        $this->syncs = [
            new BooleanOracleTagSync(tag: 'mass-land-denial', column: 'mld'),
            new FetchPatternOracleTagSync,
            new LandSearchPatternOracleTagSync,
        ];
    }

    /**
     * Resync every registered tag. Each is processed independently;
     * a failed search for one tag does not abort the others, and the
     * corresponding column keeps its previous values on failure (rather
     * than being zeroed) so a transient outage can't wipe valid data.
     *
     * @throws Throwable Propagated from {@see applySync} when the
     *                   wrapped DB transaction fails. HTTP-level
     *                   failures are caught inside {@see fetchTaggedCards}
     *                   and don't escape.
     */
    public function syncOracleTags(bool $shadow = false): void
    {
        $this->shadow = $shadow;
        $this->oracleCardsTable = $this->tableName('oracle_cards', $shadow);
        $keptBefore = ScryfallRunStats::$oracleTagColumnsCarriedOver;
        foreach ($this->syncs as $sync) {
            $this->runSync($sync);
        }

        $kept = array_diff_key(ScryfallRunStats::$oracleTagColumnsCarriedOver, $keptBefore);
        if ($shadow && $kept !== []) {
            $this->alertAboutKeptColumns($kept);
        }
    }

    /**
     * Alert when a nightly build kept previous values. The run carries on
     * — the values are valid, only not refreshed — but nobody reads the
     * summary of a run that succeeded, and a renamed tag would otherwise
     * go stale for good: every fetchland printed after the rename would
     * show no mana. A standalone run is watched; its warnings suffice.
     *
     * @param  array<string, string>  $kept  Column → reason.
     */
    private function alertAboutKeptColumns(array $kept): void
    {
        $reasons = [];
        foreach ($kept as $column => $reason) {
            $reasons[] = "$column: $reason";
        }
        (new ScryfallFailureAlert)->warn(
            'scryfall:oracle-tags',
            "kept the previous run's values for ".implode('; ', $reasons).'.'
        );
    }

    /**
     * Pull every card matching the sync's query and apply
     * the derived values. Logs at info on success, warning on a tag
     * miss / parser miss, error on HTTP failure.
     *
     * @throws Throwable Propagated from {@see writeColumn}.
     */
    private function runSync(OracleTagSync $sync): void
    {
        $cards = $this->fetchTaggedCards($sync->query());
        if ($cards === null) {
            $this->keepPreviousValues($sync, 'the Scryfall search for '.$sync->query().' failed');

            return;
        }

        if ($cards === []) {
            // Treat zero results as a sync failure rather than a real
            // "no cards exist" — most likely cause is Scryfall renaming
            // the tag slug, and zeroing the column would silently
            // break downstream features.
            $this->keepPreviousValues($sync, "query '".$sync->query()."' returned zero cards — Scryfall taxonomy may have changed");

            return;
        }

        [$byValue, $skipped] = $this->deriveValues($sync, $cards);
        if ($byValue === []) {
            $this->keepPreviousValues($sync, 'none of the '.count($cards)." cards found by '".$sync->query()."' could be classified — Scryfall may have reworded them");

            return;
        }

        $applied = $this->writeColumn($sync, $byValue);

        Log::channel('scryfall')->info(
            "query '".$sync->query()."' synced — $applied cards classified into column '".$sync->column()."'."
        );

        if ($skipped !== []) {
            Log::channel('scryfall')->log(
                $sync->expectsUnclassified() ? 'info' : 'warning',
                'deriveValue() returned null for '.count($skipped).' card(s) in '.$sync->query().': '.implode(', ', $skipped)
            );
        }
    }

    /**
     * Walk every page of `/cards/search?q=<query>&unique=cards`
     * and return the full card payloads — strategies that need oracle
     * text or other per-card fields can read from the payload
     * directly without a follow-up lookup. `unique=cards` gives us
     * one row per oracle id, but we still dedupe defensively.
     *
     * Returns null on any HTTP error so the caller can decide whether
     * to overwrite existing values (it doesn't).
     *
     * @return list<array<string, mixed>>|null
     */
    private function fetchTaggedCards(string $query): ?array
    {
        $url = sprintf(
            'https://api.scryfall.com/cards/search?q=%s&unique=cards',
            rawurlencode($query)
        );
        $cards = [];

        while ($url !== null) {
            try {
                $response = $this->rateLimitedGet($url);
            } catch (ConnectionException $e) {
                Log::channel('scryfall')->error(
                    "connection error during '$query' search: ".$e->getMessage()
                );

                return null;
            }
            if (! $response->successful()) {
                Log::channel('scryfall')->error(
                    "search request for '$query' failed: ".$response->body()
                );

                return null;
            }
            $body = $response->json();
            foreach ($body['data'] ?? [] as $card) {
                if (! isset($card['oracle_id'])) {
                    continue;
                }
                // Dedupe by oracle id — `unique=cards` already collapses
                // printings, but defensive against a future change.
                $cards[$card['oracle_id']] = $card;
            }
            $url = ($body['has_more'] ?? false) ? ($body['next_page'] ?? null) : null;
        }

        return array_values($cards);
    }

    /**
     * Derive the column value of every found card, grouped by value so
     * each distinct value lands as a single (chunked) UPDATE. Cards whose
     * `deriveValue()` returns null are left out (their column stays at the
     * cleared value) and returned by name for logging.
     *
     * Runs before {@see writeColumn}'s transaction so the parser doesn't
     * hold the table lock longer than necessary.
     *
     * @param  list<array<string, mixed>>  $cards
     * @return array{array<string, array{value: mixed, ids: list<string>}>, list<string>} `[byValue, skippedNames]`
     */
    private function deriveValues(OracleTagSync $sync, array $cards): array
    {
        $byValue = [];
        $skipped = [];
        foreach ($cards as $card) {
            $value = $sync->deriveValue($card);
            if ($value === null) {
                $skipped[] = $card['name'] ?? $card['oracle_id'];

                continue;
            }
            $this->addToBucket($byValue, $value, $card['oracle_id']);
        }

        return [$byValue, $skipped];
    }

    /**
     * Keep the column's previous values when this run has nothing
     * trustworthy to replace them with.
     *
     * Standalone (live target) that means doing nothing. A shadow build
     * has to copy them: its `oracle_cards__shadow` was freshly imported, so
     * the column holds the cleared value on every row and the swap would
     * otherwise put that live — no fetchland colours, no land-search ramp,
     * no mass-land-denial hint, and a run that reports success. Rows are
     * matched by oracle id; a card new to this import has no previous
     * value and stays cleared until a sync succeeds again.
     *
     * @throws Throwable Propagated from {@see writeColumn}.
     */
    private function keepPreviousValues(OracleTagSync $sync, string $reason): void
    {
        $column = $sync->column();
        ScryfallRunStats::$oracleTagColumnsCarriedOver[$column] = $reason;

        if (! $this->shadow) {
            Log::channel('scryfall')->warning("$reason. Column '$column' left untouched.");

            return;
        }

        $previous = DB::table('oracle_cards')->select(['id', $column]);
        if ($sync->clearValue() === null) {
            $previous->whereNotNull($column);
        } else {
            $previous->where($column, '!=', $sync->clearValue());
        }

        $byValue = [];
        foreach ($previous->get() as $row) {
            $this->addToBucket($byValue, $row->{$column}, $row->id);
        }
        $copied = $this->writeColumn($sync, $byValue);

        Log::channel('scryfall')->warning(
            "$reason. Carried column '$column' over from the live table instead ($copied cards)."
        );
    }

    /**
     * @param  array<string, array{value: mixed, ids: list<string>}>  $byValue
     */
    private function addToBucket(array &$byValue, mixed $value, string $oracleId): void
    {
        $key = $this->bucketKey($value);
        if (! isset($byValue[$key])) {
            $byValue[$key] = ['value' => $value, 'ids' => []];
        }
        $byValue[$key]['ids'][] = $oracleId;
    }

    /**
     * Atomically clear the sync's column and write the grouped values.
     *
     * Bulk-update strategy: one (chunked) UPDATE per distinct value. For
     * the boolean tag this is one UPDATE; for the fetch-pattern tag
     * with ~52 cards across ~30 distinct patterns, it's ~30 small
     * UPDATEs — still cheap.
     *
     * @param  array<string, array{value: mixed, ids: list<string>}>  $byValue
     * @return int Rows the values were written to — ids no longer in the
     *             table don't count.
     *
     * @throws Throwable Propagated from {@see DB::transaction}.
     */
    private function writeColumn(OracleTagSync $sync, array $byValue): int
    {
        $applied = 0;
        $table = $this->oracleCardsTable;
        DB::transaction(function () use ($sync, $byValue, $table, &$applied): void {
            DB::table($table)->update([$sync->column() => $sync->clearValue()]);
            foreach ($byValue as $bucket) {
                foreach (array_chunk($bucket['ids'], self::UPDATE_CHUNK) as $chunk) {
                    $applied += DB::table($table)
                        ->whereIn('id', $chunk)
                        ->update([$sync->column() => $bucket['value']]);
                }
            }
        });

        return $applied;
    }

    /**
     * Stable string key for grouping cards by derived value. Boolean
     * `true`/`false` need explicit handling because PHP would coerce
     * them to `'1'` / `''` in array keys, which collides with the
     * numeric / empty cases.
     */
    private function bucketKey(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '__true' : '__false';
        }

        return (string) $value;
    }

    /**
     * Sleep ≥{@see REQUEST_DELAY_SECONDS}s before every Scryfall
     * call after the very first, so the service-level pacing holds
     * across multiple tag syncs in a single run (not just within a
     * single tag's pagination loop). Scryfall's published minimum is
     * 50–100 ms; the wider gap is deliberate headroom.
     *
     * @throws ConnectionException When the underlying HTTP client
     *                             fails to establish or complete the
     *                             connection (DNS, refused, timeout).
     */
    private function rateLimitedGet(string $url): Response
    {
        if (! $this->firstRequest) {
            Sleep::for(self::REQUEST_DELAY_SECONDS)->seconds();
        }
        $this->firstRequest = false;

        return $this->api()->get($url);
    }
}
