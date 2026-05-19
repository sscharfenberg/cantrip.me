<?php

namespace App\Services\Scryfall;

use App\Enums\Scryfall\ScryfallRulingSource;
use App\Models\Ruling;
use App\Services\FormatService;
use Cerbero\JsonParser\JsonParser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Streaming strategy
 * ------------------
 * `JsonParser::parse($downloadUri)` engages cerbero's `Endpoint` source
 * which uses Guzzle's PSR-7 streaming response — the JSON arrives
 * chunk-by-chunk and is decoded incrementally without ever materializing
 * the full bulk in memory. No on-disk caching; every run re-fetches from
 * Scryfall. Tradeoff: a mid-stream abort means the next run re-fetches.
 */
class RulingsService extends ScryfallService
{
    private const BUFFER_SIZE = 500;

    private FormatService $formatService;

    private BulkdataService $bulkdataService;

    private bool $shadow = false;

    private string $rulingsTable = 'rulings';

    private string $oracleCardsTable = 'oracle_cards';

    /** @var array<array<string, mixed>> */
    private array $buffer = [];

    /**
     * Lookup table of oracle_card IDs already present in the database.
     * Filled once before traversal so we can skip rulings whose
     * `oracle_id` doesn't reference a known card (FK protection
     * without per-row queries).
     *
     * @var array<string, true>
     */
    private array $knownOracleIds = [];

    private int $skipped = 0;

    public function __construct()
    {
        $this->formatService = new FormatService;
        $this->bulkdataService = new BulkdataService;
    }

    /**
     * Truncate the live rulings table before a fresh import.
     *
     * Skipped in shadow mode — the orchestrator created an empty
     * `rulings__shadow` before invoking this service.
     */
    private function preRunCleanup(): void
    {
        if ($this->shadow) {
            return;
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        Ruling::truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');
        Log::channel('scryfall')->notice('truncated rulings table.');
    }

    /**
     * Load all oracle_card IDs into the in-memory lookup. Called once
     * before traversal so each ruling can be FK-checked without hitting
     * the database.
     *
     * Reads from oracle_cards__shadow in shadow mode so newly imported
     * cards (not yet swapped to live) match for FK validation, and
     * cards present only in stale live data don't.
     */
    private function loadKnownOracleIds(): void
    {
        $this->knownOracleIds = DB::table($this->oracleCardsTable)
            ->pluck('id')
            ->mapWithKeys(fn (string $id): array => [$id => true])
            ->all();
        $count = number_format(count($this->knownOracleIds), 0, ',', '.');
        Log::channel('scryfall')->debug("loaded $count known oracle_card IDs for FK lookup.");
    }

    /**
     * Buffer a single ruling row. Skips rulings whose oracle_id is not
     * in our oracle_cards table (e.g. tokens or cards not yet imported).
     *
     * @param  array  $ruling  A single ruling object from the bulk JSON.
     */
    private function bufferRuling(array $ruling): void
    {
        $oracleId = $ruling['oracle_id'] ?? null;
        if ($oracleId === null || ! isset($this->knownOracleIds[$oracleId])) {
            $this->skipped++;

            return;
        }

        $source = ScryfallRulingSource::tryFrom($ruling['source'] ?? '');
        if ($source === null) {
            $this->skipped++;

            return;
        }

        $this->buffer[] = [
            'id' => (string) Str::uuid(),
            'oracle_card_id' => $oracleId,
            'source' => $source->value,
            'published_at' => $ruling['published_at'] ?? null,
            'comment' => $ruling['comment'] ?? '',
        ];

        if (count($this->buffer) >= self::BUFFER_SIZE) {
            $this->flushBuffer();
        }
    }

    /**
     * Insert all buffered rows and clear the buffer.
     */
    private function flushBuffer(): void
    {
        if (empty($this->buffer)) {
            return;
        }

        DB::table($this->rulingsTable)->insert($this->buffer);
        $this->buffer = [];
    }

    /**
     * Stream-parse the bulk JSON directly from Scryfall and buffer-insert
     * each ruling. Uses cerbero's `Endpoint` source so the JSON is read
     * via a PSR-7 stream wrapper — no on-disk file, no full-response
     * materialization in PHP memory.
     */
    private function traverseJson(string $downloadUri): void
    {
        $start = now();
        $count = 0;
        Log::channel('scryfall')->notice("begin streaming rulings bulk from '$downloadUri'.");
        JsonParser::parse($downloadUri)->traverse(function (mixed $value, string|int $key, JsonParser $parser) use (&$count) {
            $this->bufferRuling($value);
            $count++;
        });
        $this->flushBuffer();
        $ms = $start->diffInMilliseconds(now());
        $numRulings = number_format($count, 0, ',', '.');
        $numSkipped = number_format($this->skipped, 0, ',', '.');
        Log::channel('scryfall')->notice('finished inserting rulings into database in '.$this->formatService->formatMs($ms).' (parsed: '.$numRulings.', skipped: '.$numSkipped.' for missing oracle_card).');
    }

    /**
     * Run a full rulings import from Scryfall.
     *
     * Resolves the `rulings` download URI from bulk_data(__shadow),
     * truncates the existing live data (or skips in shadow mode where the
     * orchestrator pre-created empty shadows), and stream-parses every
     * ruling from the live Scryfall CDN.
     *
     * In shadow mode: rows go into rulings__shadow and FK lookups read
     * from oracle_cards__shadow so the freshly imported oracle dataset
     * is the authority.
     */
    public function updateRulings(bool $shadow = false): void
    {
        $this->shadow = $shadow;
        $this->rulingsTable = $this->tableName('rulings', $shadow);
        $this->oracleCardsTable = $this->tableName('oracle_cards', $shadow);

        $downloadUri = $this->bulkdataService->resolveDownloadUri('rulings', $shadow);
        if ($downloadUri === null) {
            $bulkTable = $this->tableName('bulk_data', $shadow);
            Log::channel('scryfall')->error("no 'rulings' row found in $bulkTable — run `scryfall:bulk` first.");

            return;
        }
        $this->preRunCleanup();
        $this->loadKnownOracleIds();
        $this->traverseJson($downloadUri);
    }
}
