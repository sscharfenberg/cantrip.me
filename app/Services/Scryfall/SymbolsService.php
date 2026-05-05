<?php

namespace App\Services\Scryfall;

use App\Models\Symbol;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class SymbolsService extends ScryfallService
{
    /**
     * Prepare the database for a symbols import by truncating the symbols table.
     *
     * SVGs are intentionally not purged — symbol SVGs are stable (Scryfall rarely
     * changes them) and getSymbolSvg() only downloads files that are missing,
     * so wiping cached SVGs would cause unnecessary re-downloads on every run.
     */
    private function setup(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        Symbol::truncate();
        Log::channel('scryfall')->debug("table 'symbols' truncated.");
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');
    }

    /**
     * Derive a safe local filename from a Scryfall symbol object.
     *
     * Prefers loose_variant (e.g. "R", "2/W") with slashes replaced by "-",
     * falling back to stripping curly braces from the symbol string itself.
     * Both curly braces and slashes are invalid or problematic in filenames/URLs.
     *
     * @param  array  $symbol  A single symbol object from the Scryfall /symbology response.
     * @return string e.g. "R.svg", "2-W.svg", "CHAOS.svg"
     */
    private function buildFileName(array $symbol): string
    {
        $base = $symbol['loose_variant']
            ?? trim($symbol['symbol'], '{}');

        return str_replace('/', '-', $base).'.svg';
    }

    /**
     * Download a symbol SVG if it is not already cached locally.
     *
     * Returns the public-facing path regardless of whether a fresh
     * download was needed.
     *
     * @param  array  $symbol  A single symbol object from the Scryfall /symbology response.
     * @return string The public-facing path to the stored SVG, e.g. "/symbol/R.svg".
     *
     * @throws ConnectionException
     */
    private function getSymbolSvg(array $symbol): string
    {
        $fileName = $this->buildFileName($symbol);
        if (Storage::disk('symbol')->missing($fileName)) {
            $response = $this->http()
                ->get($symbol['svg_uri']);
            if ($response->successful()) {
                Storage::disk('symbol')->put($fileName, $response->body());
                Log::channel('scryfall')->debug("created SVG in storage disk 'symbol': $fileName");
            } else {
                Log::channel('scryfall')->error("error downloading symbol SVG '{$symbol['svg_uri']}': ".$response->body());
            }
        }

        return '/symbol/'.$fileName;
    }

    /**
     * Persist a single symbol from the Scryfall API response to the database.
     *
     * @param  array  $symbol  A single symbol object from the Scryfall /symbology response.
     *
     * @throws ConnectionException
     */
    private function insertSymbol(array $symbol): void
    {
        $path = $this->getSymbolSvg($symbol);
        $newSymbol = Symbol::create([
            'symbol' => $symbol['symbol'],
            'svg_uri' => $symbol['svg_uri'],
            'loose_variant' => $symbol['loose_variant'] ?? null,
            'english' => $symbol['english'],
            'represents_mana' => $symbol['represents_mana'],
            'appears_in_mana_costs' => $symbol['appears_in_mana_costs'],
            'transposable' => $symbol['transposable'],
            'hybrid' => $symbol['hybrid'],
            'phyrexian' => $symbol['phyrexian'],
            'funny' => $symbol['funny'],
            'cmc' => $symbol['cmc'] ?? null,
            'colors' => implode('', $symbol['colors']),
            'path' => $path,
        ]);
        if ($newSymbol->wasRecentlyCreated) {
            Log::channel('scryfall')->debug("created symbol {$newSymbol->symbol} → $path");
        }
    }

    /**
     * Fetch all symbols from the Scryfall API and replace the local database.
     *
     * Runs setup() first to truncate existing data.
     */
    public function updateSymbols(): void
    {
        $this->setup();
        try {
            $response = $this->http()
                ->get('https://api.scryfall.com/symbology');
            if ($response->successful()) {
                $symbols = $response->json();
                if (array_key_exists('data', $symbols)) {
                    collect($symbols['data'])->each(fn ($symbol) => $this->insertSymbol($symbol));
                } else {
                    Log::channel('scryfall')->error("Scryfall response successful, but json does not have a field 'data'.");
                }
            } else {
                Log::channel('scryfall')->error('Scryfall response failed: '.$response->body());
            }
        } catch (\Exception $exception) {
            Log::channel('scryfall')->error($exception->getMessage());
            report($exception);
        }
    }
}
