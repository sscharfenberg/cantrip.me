<?php

namespace App\Services\Scryfall;

use App\Models\OracleCard;
use App\Services\Scryfall\OracleTagSyncs\BooleanOracleTagSync;
use App\Services\Scryfall\OracleTagSyncs\FetchPatternOracleTagSync;
use App\Services\Scryfall\OracleTagSyncs\OracleTagSync;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
 * touches another tag's column. Zero results from a tag (most likely
 * a Scryfall taxonomy rename) are treated as a sync failure and the
 * column is **left untouched** rather than zeroed.
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

    public function __construct()
    {
        $this->syncs = [
            new BooleanOracleTagSync(tag: 'mass-land-denial', column: 'mld'),
            new FetchPatternOracleTagSync,
        ];
    }

    /**
     * Resync every registered tag. Each is processed independently;
     * a failed search for one tag does not abort the others, and the
     * corresponding column is left untouched on failure (rather than
     * zeroed) so a transient outage can't wipe valid data.
     *
     * @throws Throwable Propagated from {@see applySync} when the
     *                   wrapped DB transaction fails. HTTP-level
     *                   failures are caught inside {@see fetchTaggedCards}
     *                   and don't escape.
     */
    public function syncOracleTags(): void
    {
        foreach ($this->syncs as $sync) {
            $this->runSync($sync);
        }
    }

    /**
     * Pull every card tagged with the sync's `otag:<tag>` and apply
     * the derived values. Logs at info on success, warning on a tag
     * miss / parser miss, error on HTTP failure.
     *
     * @throws Throwable Propagated from {@see applySync}.
     */
    private function runSync(OracleTagSync $sync): void
    {
        $cards = $this->fetchTaggedCards($sync->tag());
        if ($cards === null) {
            Log::channel('scryfall')->warning(
                "skipping update for column '".$sync->column()."' — Scryfall search for otag:".$sync->tag().' failed.'
            );

            return;
        }

        if ($cards === []) {
            // Treat zero results as a sync failure rather than a real
            // "no cards exist" — most likely cause is Scryfall renaming
            // the tag slug, and zeroing the column would silently
            // break downstream features.
            Log::channel('scryfall')->warning(
                "tag 'otag:".$sync->tag()."' returned zero cards — Scryfall taxonomy may have changed. Column '".$sync->column()."' left untouched."
            );

            return;
        }

        [$applied, $skipped] = $this->applySync($sync, $cards);

        Log::channel('scryfall')->info(
            "tag 'otag:".$sync->tag()."' synced — $applied cards classified into column '".$sync->column()."'."
        );

        if ($skipped !== []) {
            Log::channel('scryfall')->warning(
                'deriveValue() returned null for '.count($skipped).' card(s) in otag:'.$sync->tag().': '.implode(', ', $skipped)
            );
        }
    }

    /**
     * Walk every page of `/cards/search?q=otag:<tag>&unique=cards`
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
    private function fetchTaggedCards(string $tag): ?array
    {
        $url = sprintf(
            'https://api.scryfall.com/cards/search?q=%s&unique=cards',
            rawurlencode("otag:$tag")
        );
        $cards = [];

        while ($url !== null) {
            try {
                $response = $this->rateLimitedGet($url);
            } catch (ConnectionException $e) {
                Log::channel('scryfall')->error(
                    "connection error during otag:$tag search: ".$e->getMessage()
                );

                return null;
            }
            if (! $response->successful()) {
                Log::channel('scryfall')->error(
                    "search request for otag:$tag failed: ".$response->body()
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
     * Atomically clear the sync's column and re-apply derived values
     * for every classified card. Cards whose `deriveValue()` returns
     * null are skipped (their column stays at the cleared value) and
     * returned to the caller for logging.
     *
     * Bulk-update strategy: cards are grouped by their derived value
     * so each distinct value lands as a single (chunked) UPDATE. For
     * the boolean tag this is one UPDATE; for the fetch-pattern tag
     * with ~52 cards across ~30 distinct patterns, it's ~30 small
     * UPDATEs — still cheap.
     *
     * @param  list<array<string, mixed>>  $cards
     * @return array{int, list<string>} `[appliedCount, skippedNames]`
     *
     * @throws Throwable Propagated from {@see DB::transaction}.
     */
    private function applySync(OracleTagSync $sync, array $cards): array
    {
        // Pre-derive outside the transaction so the parser doesn't
        // hold the table lock longer than necessary.
        $byValue = [];
        $skipped = [];
        foreach ($cards as $card) {
            $value = $sync->deriveValue($card);
            if ($value === null) {
                $skipped[] = $card['name'] ?? $card['oracle_id'];

                continue;
            }
            $key = $this->bucketKey($value);
            if (! isset($byValue[$key])) {
                $byValue[$key] = ['value' => $value, 'ids' => []];
            }
            $byValue[$key]['ids'][] = $card['oracle_id'];
        }

        $applied = 0;
        DB::transaction(function () use ($sync, $byValue, &$applied): void {
            OracleCard::query()->update([$sync->column() => $sync->clearValue()]);
            foreach ($byValue as $bucket) {
                foreach (array_chunk($bucket['ids'], self::UPDATE_CHUNK) as $chunk) {
                    OracleCard::query()
                        ->whereIn('id', $chunk)
                        ->update([$sync->column() => $bucket['value']]);
                    $applied += count($chunk);
                }
            }
        });

        return [$applied, $skipped];
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
            sleep(self::REQUEST_DELAY_SECONDS);
        }
        $this->firstRequest = false;

        return $this->http()->get($url);
    }
}
