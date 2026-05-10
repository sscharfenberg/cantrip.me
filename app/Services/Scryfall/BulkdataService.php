<?php

namespace App\Services\Scryfall;

use App\Models\BulkData;
use App\Services\FormatService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class BulkdataService extends ScryfallService
{
    private FormatService $formatService;

    private bool $shadow = false;

    private string $bulkDataTable = 'bulk_data';

    public function __construct()
    {
        $this->formatService = new FormatService;
    }

    /**
     * Download a Scryfall bulk-data JSON file to local storage.
     *
     * Skips the download if the file already exists on the "scryfall-bulk"
     * disk. Verifies the downloaded file size against the expected size
     * from the BulkData model to detect truncated downloads.
     *
     * @param  string  $type  The bulk-data type identifier (e.g. "oracle_cards").
     * @return bool True on success or if the file already exists, false on failure.
     */
    public function prepareJson(string $type, bool $shadow = false): bool
    {
        $fileName = $type.'.json';
        if (Storage::disk('scryfall-bulk')->exists($fileName)) {
            Log::channel('scryfall')->notice("JSON file '$fileName' already exists in disk 'scryfall-bulk'.");

            return true;
        }
        $start = now();
        $bulkDataTable = $this->tableName('bulk_data', $shadow);
        $bd = DB::table($bulkDataTable)->where('type', '=', $type)->first();
        $uri = $bd->download_uri;
        try {
            Log::channel('scryfall')->notice("starting download of '$fileName' from scryfall.");
            $response = $this->http()
                ->timeout(-1) // disable timeouts since we want to download large files.
                ->get($uri);
            if ($response->failed()) {
                Log::channel('scryfall')->critical("error calling oracle uri '$uri' from scryfall: ".$response->body());

                return false;
            } else {
                Storage::disk('scryfall-bulk')->put($fileName, $response->body());
                $realSize = Storage::disk('scryfall-bulk')->size($fileName);
                $realSizeFormatted = number_format($realSize, 0, ',', '.');
                if ($realSize != $bd->size) {
                    Log::channel('scryfall')->error("downloaded size for '$fileName' ($realSize) differs from expected size ({$bd->size}).");

                    return false;
                }
                Log::channel('scryfall')->debug("downloaded '$fileName' from scryfall to disk 'scryfall-bulk'.");
                Log::channel('scryfall')->debug("filesize for '$fileName' ($realSizeFormatted = ".$this->formatService->formatBytes($realSize).') as expected.');
                $ms = $start->diffInMilliseconds(now());
                Log::channel('scryfall')->notice("downloaded '$fileName' in ".$this->formatService->formatMs($ms).'.');

                return true;
            }
        } catch (\Exception $e) {
            Log::channel('scryfall')->error("error downloading '$fileName': ".$e->getMessage());
            Log::channel('scryfall')->error($e->getTraceAsString());

            return false;
        }
    }

    /**
     * Remove the downloaded bulk JSON file after processing.
     *
     * Only deletes in production to keep local/dev files available
     * for debugging.
     *
     * @param  string  $fileName  The filename on the "scryfall-bulk" disk.
     */
    public function postRunCleanup($fileName): void
    {
        if (app()->environment('production')) {
            Storage::disk('scryfall-bulk')->delete($fileName);
            Log::channel('scryfall')->notice("deleted '$fileName' from disk 'scryfall-bulk'.");
        }
    }

    /**
     * Truncate the live bulk_data table before a fresh import.
     *
     * Skipped in shadow mode — the orchestrator created an empty
     * `bulk_data__shadow` before invoking this service.
     */
    private function preRunCleanup(): void
    {
        if ($this->shadow) {
            return;
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        BulkData::truncate();
        Log::channel('scryfall')->debug("table 'bulk_data' truncated.");
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');
    }

    /**
     * Persist a single bulk-data catalog entry to the database.
     *
     * Maps the Scryfall API response fields to the BulkData model.
     *
     * @param  array  $bulk  A single item from the Scryfall /bulk-data response.
     */
    private function insertBulkData(array $bulk): void
    {
        $arr = [
            'id' => $bulk['id'],
            'type' => $bulk['type'],
            // Scryfall sends ISO 8601 with timezone offset (e.g.
            // "2026-05-10T12:34:56.789+00:00"). DB::table()->insert() bypasses
            // the BulkData model's `'updated_at' => 'datetime'` cast, so we
            // must hand MySQL a DATETIME-compatible string ourselves.
            'updated_at' => Carbon::parse($bulk['updated_at'])->toDateTimeString(),
            'uri' => $bulk['uri'],
            'name' => $bulk['name'],
            'description' => $bulk['description'],
            'size' => $bulk['size'],
            'download_uri' => $bulk['download_uri'],
            'content_type' => $bulk['content_type'],
            'content_encoding' => $bulk['content_encoding'],
        ];
        DB::table($this->bulkDataTable)->insert($arr);
        Log::channel('scryfall')->debug("created bulkdata entry '{$arr['name']}' last updated @ {$arr['updated_at']}.");
    }

    /**
     * Fetch and store the bulk-data catalog from the Scryfall API.
     *
     * Truncates existing live entries (or builds the shadow set) and
     * replaces them with the latest catalog so subsequent imports use
     * up-to-date download URIs and sizes.
     */
    public function getBulkMetadata(bool $shadow = false): void
    {
        $this->shadow = $shadow;
        $this->bulkDataTable = $this->tableName('bulk_data', $shadow);
        Log::channel('scryfall')->debug('Updating bulk metadata from scryfall.');
        $this->preRunCleanup();
        $response = $this->http()
            ->get('https://api.scryfall.com/bulk-data');
        if ($response->failed()) {
            Log::channel('scryfall')->error('Failed scryfall api request: '.$response->body());
            throw new \RuntimeException('scryfall /bulk-data request failed with HTTP '.$response->status());
        }
        $json = $response->json();
        Log::channel('scryfall')->debug('API request to scryfall successful.: '.json_encode($json, JSON_PRETTY_PRINT));
        collect($json['data'])->each(fn ($item) => $this->insertBulkData($item));
    }
}
