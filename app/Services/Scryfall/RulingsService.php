<?php

namespace App\Services\Scryfall;

use App\Models\OracleCard;
use App\Models\Ruling;
use App\Services\FormatService;
use Cerbero\JsonParser\JsonParser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class RulingsService
{
    private const BUFFER_SIZE = 500;

    private FormatService $formatService;

    private BulkdataService $bulkdataService;

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
     * Truncate the rulings table before a fresh import.
     *
     * Temporarily disables foreign key checks to allow truncation.
     */
    private function preRunCleanup(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        Ruling::truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');
        Log::channel('scryfall')->notice('truncated rulings table.');
    }

    /**
     * Load all oracle_card IDs into the in-memory lookup. Called once
     * before traversal so each ruling can be FK-checked without hitting
     * the database.
     */
    private function loadKnownOracleIds(): void
    {
        $this->knownOracleIds = OracleCard::query()
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

        $this->buffer[] = [
            'id' => (string) Str::uuid(),
            'oracle_card_id' => $oracleId,
            'source' => $ruling['source'] ?? '',
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

        Ruling::insert($this->buffer);
        $this->buffer = [];
    }

    /**
     * Stream-parse the bulk JSON file and buffer-insert each ruling.
     *
     * Uses JsonParser to avoid loading the entire file into memory.
     *
     * @param  string  $fileName  The filename on the "scryfall-bulk" disk.
     */
    private function traverseJson(string $fileName): void
    {
        $start = now();
        $count = 0;
        Log::channel('scryfall')->notice('begin traversing rulings json.');
        JsonParser::parse(Storage::disk('scryfall-bulk')->get($fileName))->traverse(function (mixed $value, string|int $key, JsonParser $parser) use (&$count) {
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
     * Downloads the "rulings" bulk JSON (if not already cached),
     * truncates the existing data, streams through every ruling to
     * insert it, and cleans up the downloaded file afterwards.
     */
    public function updateRulings(): void
    {
        $type = 'rulings';
        if (! $this->bulkdataService->prepareJson($type)) {
            Log::channel('scryfall')->error("error preparing '$type.json', aborting.");

            return;
        }
        $this->preRunCleanup();
        $this->loadKnownOracleIds();
        $this->traverseJson($type.'.json');
        $this->bulkdataService->postRunCleanup($type.'.json');
    }
}
