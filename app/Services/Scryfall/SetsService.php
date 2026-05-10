<?php

namespace App\Services\Scryfall;

use App\Models\Set;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class SetsService extends ScryfallService
{
    private bool $shadow = false;

    private string $setsTable = 'sets';

    /**
     * Cached list of files on the "set" disk, loaded lazily on first access.
     * Used to identify stale icon versions to delete when Scryfall updates
     * the icon for a set (new timestamp in icon_svg_uri).
     *
     * @var string[]|null
     */
    private ?array $cachedSetIcons = null;

    /**
     * Prepare the live sets table for a fresh import by truncating it.
     *
     * Skipped in shadow mode — the orchestrator created an empty
     * `sets__shadow` via {@see ShadowTableService::createLike()} before
     * invoking this service.
     *
     * Icon SVGs are intentionally not purged here — getSetIcon() uses
     * timestamp-suffixed filenames and only re-downloads when Scryfall
     * publishes a new timestamp, so wiping cached icons on every run
     * would cause unnecessary re-downloads.
     */
    private function setup(): void
    {
        if ($this->shadow) {
            return;
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        Set::truncate();
        Log::channel('scryfall')->debug("table 'sets' truncated.");
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');
    }

    /**
     * Derive the local filename for a set icon SVG.
     *
     * Embeds the Scryfall timestamp from icon_svg_uri so a filesystem check
     * alone can determine whether the cached icon is current.
     *
     * Format: {code}--{timestamp}.svg (or {code}.svg if no timestamp).
     *
     * @param  string  $code  The set code (e.g. "lea", "mh3").
     * @param  string|null  $timestamp  The timestamp from the Scryfall URL.
     * @return string e.g. "lea--1709234567.svg"
     */
    private function buildFileName(string $code, ?string $timestamp): string
    {
        if ($timestamp !== null) {
            return "$code--$timestamp.svg";
        }

        return "$code.svg";
    }

    /**
     * List (lazily) all files on the "set" disk, cached for the duration
     * of the current updateSets() run. Used to locate stale icon versions
     * when a set's icon_svg_uri timestamp has changed.
     *
     * @return string[]
     */
    private function getCachedSetIcons(): array
    {
        if ($this->cachedSetIcons === null) {
            $this->cachedSetIcons = Storage::disk('set')->files();
        }

        return $this->cachedSetIcons;
    }

    /**
     * Download a set icon SVG if the current (timestamped) version is not
     * already cached locally. When a newer timestamp is detected, stale
     * versions of the icon for the same set code are deleted first.
     *
     * @param  string  $uri  The Scryfall icon_svg_uri for the set.
     * @param  string  $code  The set code, used as the local filename.
     * @return string The public-facing path to the stored SVG.
     *
     * @throws ConnectionException
     */
    private function getSetIcon(string $uri, string $code): string
    {
        $timestamp = parse_url($uri, PHP_URL_QUERY) ?: null;
        $fileName = $this->buildFileName($code, $timestamp);

        if (Storage::disk('set')->exists($fileName)) {
            return "/set/$fileName";
        }

        // Delete any stale versions of this set's icon (old timestamp, or
        // legacy non-timestamped file) before downloading the fresh one.
        foreach ($this->getCachedSetIcons() as $existing) {
            $basename = basename($existing);
            if ($basename === "$code.svg" || str_starts_with($basename, "$code--")) {
                Storage::disk('set')->delete($existing);
                Log::channel('scryfall')->debug("deleted stale set icon: $basename");
            }
        }

        $response = $this->http()->get($uri);
        if ($response->successful()) {
            Storage::disk('set')->put($fileName, $response->body());
            Log::channel('scryfall')->debug("created SVG in storage disk 'set': $fileName");
        } else {
            Log::channel('scryfall')->error("error calling icon uri '$uri' from scryfall: ".$response->body());
        }

        return "/set/$fileName";
    }

    /**
     * Persist a single set from the Scryfall API response to the database.
     *
     * Maps required fields directly and conditionally includes optional
     * fields (block_code, released_at, etc.) only when present.
     * Also downloads the set icon via getSetIcon().
     *
     * @param  array  $set  A single set object from the Scryfall /sets response.
     *
     * @throws ConnectionException
     */
    private function insertSet(array $set): void
    {
        // these array keys always exist.
        $arr = [
            'id' => $set['id'],
            'code' => $set['code'],
            'name' => $set['name'],
            'scryfall_uri' => $set['scryfall_uri'],
            'set_type' => $set['set_type'],
            'path' => $this->getSetIcon($set['icon_svg_uri'], $set['code']),
            'card_count' => $set['card_count'],
            'digital' => $set['digital'],
        ];
        // these array keys might not exist.
        if (array_key_exists('block_code', $set)) {
            $arr['block_code'] = $set['block_code'];
        }
        if (array_key_exists('released_at', $set)) {
            $arr['released_at'] = $set['released_at'];
        }
        if (array_key_exists('block', $set)) {
            $arr['block'] = $set['block'];
        }
        if (array_key_exists('parent_set_code', $set)) {
            $arr['parent_set_code'] = $set['parent_set_code'];
        }
        if (array_key_exists('printed_size', $set)) {
            $arr['printed_size'] = $set['printed_size'];
        }
        DB::table($this->setsTable)->insert($arr);
        Log::channel('scryfall')->debug("created set [{$arr['code']}] {$arr['name']}.");
    }

    /**
     * Fetch all sets from the Scryfall API and replace the local database
     * (live mode) or build the shadow table (shadow mode).
     *
     * Filters out sets with zero cards. Runs setup() first which truncates
     * the live table; in shadow mode setup() is a no-op because the
     * orchestrator already created an empty `sets__shadow`.
     */
    public function updateSets(bool $shadow = false): void
    {
        $this->shadow = $shadow;
        $this->setsTable = $this->tableName('sets', $shadow);
        $this->cachedSetIcons = null;
        $this->setup();
        try {
            $response = $this->http()
                ->get('https://api.scryfall.com/sets');
            if ($response->successful()) {
                $sets = $response->json();
                if (array_key_exists('data', $sets)) { // all seems fine, proceed with updating tb
                    $sets = collect($sets['data'])->filter(function ($set) {
                        return $set['card_count'] > 0;
                    });
                    $sets->each(fn ($set) => $this->insertSet($set));
                } else { // json does not have 'data' prop
                    Log::channel('scryfall')->error("Scryfall response successful, but json does not have a field 'data'.");
                }
            } else { // scryfall response not successful
                Log::channel('scryfall')->error('Scryfall response failed: '.$response->body());
            }
        } catch (\Exception $exception) {
            Log::channel('scryfall')->error($exception->getMessage());
            report($exception);
        }
    }
}
