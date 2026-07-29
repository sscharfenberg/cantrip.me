<?php

namespace App\Services\Scryfall;

use App\Models\BulkData;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BulkdataService extends ScryfallService
{
    private bool $shadow = false;

    private string $bulkDataTable = 'bulk_data';

    /**
     * Resolve the `download_uri` for a bulk-data type from the
     * `bulk_data(__shadow)` table.
     *
     * Single source of truth for the four streaming bulk imports
     * ({@see OracleCardsService}, {@see DefaultCardsService},
     * {@see RulingsService}, {@see TranslationsService}). Returns null
     * if no row exists for the type — caller should log and abort.
     */
    public function resolveDownloadUri(string $type, bool $shadow = false): ?string
    {
        $table = $this->tableName('bulk_data', $shadow);
        $row = DB::table($table)->where('type', '=', $type)->first();
        if ($row === null || ! isset($row->download_uri)) {
            return null;
        }

        return (string) $row->download_uri;
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
     * Fields every /bulk-data entry must carry for the import to work.
     *
     * Checked up-front so a future Scryfall schema change surfaces as a
     * named failure through the `scryfall:update` alert path instead of an
     * `Undefined array key` fatal from inside the insert — which is exactly
     * how the `size` → `compressed_size` rename first showed up.
     */
    private const REQUIRED_FIELDS = [
        'id',
        'type',
        'updated_at',
        'uri',
        'name',
        'description',
        'compressed_size',
        'jsonl_download_uri',
    ];

    /**
     * Persist a single bulk-data catalog entry to the database.
     *
     * Maps the Scryfall API response fields to the BulkData model. Note the
     * two column/field mismatches, both from Scryfall's move to gzipped
     * JSON Lines: `size` holds `compressed_size` (the uncompressed size is
     * no longer published at all) and `download_uri` holds
     * `jsonl_download_uri` (the plain `.json` bulks now 404).
     *
     * @param  array  $bulk  A single item from the Scryfall /bulk-data response.
     */
    private function insertBulkData(array $bulk): void
    {
        $missing = array_diff(self::REQUIRED_FIELDS, array_keys($bulk));
        if ($missing !== []) {
            $type = is_string($bulk['type'] ?? null) ? $bulk['type'] : 'unknown';
            throw new \RuntimeException(
                "scryfall /bulk-data entry '$type' is missing expected field(s): ".implode(', ', $missing)
                .'. The Scryfall bulk-data schema has changed.'
            );
        }

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
            'size' => $bulk['compressed_size'],
            'download_uri' => $bulk['jsonl_download_uri'],
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
