<?php

namespace App\Services\Scryfall;

use App\Models\Artist;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ArtistsService
{
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
     * Truncate the artists table before a fresh import.
     *
     * Called from DefaultCardsService::preRunCleanup() since artists are
     * derived from card data and must be rebuilt alongside default_cards.
     */
    public function truncate(): void
    {
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

        $artist = Artist::create(['name' => $name]);
        $this->cache[$name] = $artist->id;

        return $artist->id;
    }
}
