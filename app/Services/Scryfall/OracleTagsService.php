<?php

namespace App\Services\Scryfall;

use App\Models\OracleCard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sync Scryfall's oracle-tagger flags (`otag:…`) into boolean columns on
 * `oracle_cards`. Tags from the tagger system are NOT included in
 * Scryfall's bulk data dumps — they're only reachable via the search
 * endpoint, hence this dedicated, rate-limited, paginated sync.
 *
 * Powers the bracket auto-suggest hint on the deck-edit page: the
 * "mass land denial" axis of the Commander Bracket spec maps directly to
 * `otag:mass-land-denial` and is stored as `oracle_cards.mld`.
 */
class OracleTagsService extends ScryfallService
{
    /**
     * Map of Scryfall oracle-tagger slugs (the part after `otag:`) to the
     * boolean column on `oracle_cards` they populate. Add new entries
     * here to sync additional tags — the loop in {@see syncOracleTags}
     * picks them up automatically.
     */
    private const TAG_TO_COLUMN = [
        'mass-land-denial' => 'mld',
    ];

    /**
     * Track whether any Scryfall request has been issued during this
     * service instance's lifetime. {@see rateLimitedGet} enforces a ≥1s
     * gap between every call (Scryfall asks for 50-100ms; 1s gives
     * ample headroom and avoids edge-case rate-limit responses).
     */
    private bool $firstRequest = true;

    /**
     * Resync every tag in {@see TAG_TO_COLUMN}. Each tag is processed
     * independently — a failed search for one tag does not abort the
     * others, and the corresponding column is left untouched on failure
     * (rather than zeroed) so a transient outage can't wipe valid data.
     *
     * @throws Throwable Propagated from {@see applyFlag} when the DB
     *                   transaction wrapping the bulk update fails. HTTP-level failures
     *                   are caught inside {@see fetchOracleIdsForTag} and don't escape.
     */
    public function syncOracleTags(): void
    {
        foreach (self::TAG_TO_COLUMN as $tag => $column) {
            $this->syncTag($tag, $column);
        }
    }

    /**
     * Pull every oracle id matching a single `otag:` and flip the given
     * column to true for those rows (false for every other row).
     *
     * @throws Throwable Propagated from {@see applyFlag} when the bulk
     *                   update transaction fails.
     */
    private function syncTag(string $tag, string $column): void
    {
        $oracleIds = $this->fetchOracleIdsForTag($tag);
        if ($oracleIds === null) {
            Log::channel('scryfall')->warning(
                "skipping update for column '$column' — Scryfall search for otag:$tag failed."
            );

            return;
        }

        $count = count($oracleIds);
        if ($count === 0) {
            // Treat zero results as a sync failure rather than a real
            // "no MLD cards exist" — the most likely cause is Scryfall
            // renaming or splitting the tag slug, and zeroing the column
            // would silently break the bracket auto-suggest hint.
            Log::channel('scryfall')->warning(
                "tag 'otag:$tag' returned zero cards — Scryfall taxonomy may have changed. Column '$column' left untouched."
            );

            return;
        }

        $this->applyFlag($column, $oracleIds);
        Log::channel('scryfall')->info(
            "tag 'otag:$tag' synced — $count oracle cards flagged in column '$column'."
        );
    }

    /**
     * Walk every page of `/cards/search?q=otag:<tag>&unique=cards` and
     * collect the unique `oracle_id`s returned. `unique=cards` gives us
     * one row per oracle id, but we still dedupe defensively in case the
     * underlying assumption changes.
     *
     * Returns null on any HTTP error so the caller can decide whether to
     * overwrite existing flags (it doesn't).
     *
     * @return list<string>|null
     */
    private function fetchOracleIdsForTag(string $tag): ?array
    {
        $url = sprintf(
            'https://api.scryfall.com/cards/search?q=%s&unique=cards',
            rawurlencode("otag:$tag")
        );
        $oracleIds = [];

        while ($url !== null) {
            try {
                $response = $this->rateLimitedGet($url);
            } catch (ConnectionException $e) {
                // Network-level failure (DNS, connection refused, timeout).
                // Return null so the caller leaves the column untouched —
                // honoring the per-tag isolation contract on syncOracleTags.
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
                if (isset($card['oracle_id'])) {
                    $oracleIds[$card['oracle_id']] = true;
                }
            }
            $url = ($body['has_more'] ?? false) ? ($body['next_page'] ?? null) : null;
        }

        return array_keys($oracleIds);
    }

    /**
     * Atomically clear and re-apply a column's flag for the given oracle
     * ids. Wrapped in a transaction so a partial failure can't leave the
     * table in an "all false" state mid-update — either every flagged
     * card lands together, or nothing changes.
     *
     * Chunked at 1 000 ids per UPDATE to stay well under MySQL's 65 535
     * placeholder cap for prepared statements (108 known MLD cards
     * today, but the chunk-safe path keeps headroom for future growth).
     *
     * @param  list<string>  $oracleIds
     *
     * @throws Throwable Propagated from {@see DB::transaction} when the
     *                   wrapped UPDATEs fail.
     */
    private function applyFlag(string $column, array $oracleIds): void
    {
        DB::transaction(function () use ($column, $oracleIds): void {
            OracleCard::query()->update([$column => false]);
            foreach (array_chunk($oracleIds, 1000) as $chunk) {
                OracleCard::query()->whereIn('id', $chunk)->update([$column => true]);
            }
        });
    }

    /**
     * Sleep ≥1s before every Scryfall call after the very first, so the
     * service-level pacing holds across multiple tag syncs in a single
     * run (not just within a single tag's pagination loop).
     *
     * @throws ConnectionException When the underlying HTTP client fails
     *                             to establish or complete the connection (DNS, refused, timeout).
     */
    private function rateLimitedGet(string $url): Response
    {
        if (! $this->firstRequest) {
            sleep(1);
        }
        $this->firstRequest = false;

        return $this->http()->get($url);
    }
}
