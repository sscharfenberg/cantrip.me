<?php

namespace App\Services\Scryfall;

use App\Models\Artist;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ArtistsService extends ScryfallService
{
    private bool $shadow = false;

    private string $artistsTable = 'artists';

    /**
     * In-memory cache of artist name → UUID.
     *
     * Avoids repeated DB lookups during the import loop. With ~112k cards
     * but only a few thousand unique artist names, this keeps the import fast.
     *
     * @var array<string, string>
     */
    private array $cache = [];

    /**
     * Switch the service into live or shadow mode and reset the in-memory
     * cache. Called by {@see DefaultCardsService} at the start of an
     * import so subsequent {@see resolveArtistId()} calls write to the
     * correct target table.
     */
    public function useTarget(bool $shadow): void
    {
        $this->shadow = $shadow;
        $this->artistsTable = $this->tableName('artists', $shadow);
        $this->cache = [];
    }

    /**
     * Truncate the live artists table before a fresh import.
     *
     * Skipped in shadow mode — the orchestrator created an empty
     * `artists__shadow` before invoking {@see DefaultCardsService}.
     *
     * Called from {@see DefaultCardsService::preRunCleanup()} since
     * artists are derived from card data and must be rebuilt alongside
     * default_cards.
     */
    public function truncate(): void
    {
        if ($this->shadow) {
            return;
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        Artist::truncate();
        Log::channel('scryfall')->debug("table 'artists' truncated.");
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');
    }

    /**
     * Resolve an artist name to its UUID, creating a new record if needed.
     *
     * Returns null when the card has no artist (e.g. tokens, art cards).
     *
     * The cache compares names byte for byte, the unique index on
     * `artists.name` does not: its collation ignores case and accents. Two
     * spellings Scryfall keeps apart but the index calls equal ("Jose" and
     * "José") would make the second insert fail — and with it
     * `scryfall:default_cards`, every night. The second spelling therefore
     * joins the artist the first one created.
     *
     * @param  string|null  $name  The artist name from the Scryfall card object.
     * @return string|null The artist UUID.
     */
    public function resolveArtistId(?string $name): ?string
    {
        if ($name === null || $name === '') {
            return null;
        }

        if (isset($this->cache[$name])) {
            return $this->cache[$name];
        }

        $id = (string) Str::uuid();
        try {
            DB::table($this->artistsTable)->insert([
                'id' => $id,
                'name' => $name,
            ]);
        } catch (UniqueConstraintViolationException $e) {
            $existing = DB::table($this->artistsTable)->where('name', $name)->value('id');
            if ($existing === null) {
                throw $e;
            }
            $id = (string) $existing;
            Log::channel('scryfall')->info("artist '$name' matches an existing artist under the table's collation; using that one.");
        }
        $this->cache[$name] = $id;

        return $id;
    }
}
