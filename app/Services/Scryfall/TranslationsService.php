<?php

namespace App\Services\Scryfall;

use App\Models\OracleCardFaceTranslation;
use App\Models\OracleCardTranslation;
use App\Services\CardNameNormalizer;
use App\Services\FormatService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Imports foreign-language card-name translations from Scryfall's
 * `all_cards` bulk into `oracle_card_translations` and
 * `oracle_card_face_translations`. Read by the search services
 * (deck card-add, collection-add, commander picker) so users can
 * find a card by any printed-language name — e.g. "Blitzschlag"
 * resolves to Lightning Bolt.
 *
 * Streaming strategy
 * ------------------
 * {@see ScryfallService::streamJsonl()} inflates and reads the gzipped
 * JSON Lines bulk one line at a time, so the full bulk is never
 * materialized in memory. Critical for `all_cards` at ~372 MB gzipped;
 * the sibling services ({@see OracleCardsService},
 * {@see DefaultCardsService}, {@see RulingsService}) use the same
 * pattern. Tradeoff: a mid-stream abort means the next run re-fetches
 * from Scryfall.
 *
 * Dedupe + buffer
 * ---------------
 * `all_cards` lists every printing of every card. Foreign-language
 * names are reprinted verbatim across sets (same German name
 * "Blitzschlag" on every German printing of Lightning Bolt), so
 * we dedupe in memory keyed by `(oracle_id, lang)` for oracle-level
 * and `(oracle_id, face_index, lang)` for face-level. First entry
 * wins; subsequent reprints with the same key are silently skipped.
 *
 * Peak RAM ≈ ~65 MB. ~300k oracle entries + ~150k face entries
 * × ~140 B/entry. The full dataset must stay buffered until end of
 * walk because dedup can't be partial — a periodic flush would
 * cause PK violations when a later printing repeats an already-
 * flushed key.
 *
 * After the walk, both buffers are chunk-inserted at
 * `DB::table()->insert()` in 1 000-row batches (well under MySQL's
 * 65 535-placeholder cap with 4 columns per row).
 *
 * Eloquent is unsuitable here — the `$table` property is hardcoded
 * on each model, but we need to write to either the live or shadow
 * table at runtime. Plain `DB::table($name)->insert(...)` is the
 * documented escape hatch (see CLAUDE.md, "Shadow-table architecture").
 */
class TranslationsService extends ScryfallService
{
    /**
     * Number of rows per bulk INSERT. With 4 columns/row and MySQL's
     * 65 535-placeholder cap, 16k rows fits — but 1 000 is a
     * conservative chunk size matching sibling services and keeps
     * each statement well under any prepared-statement limits.
     */
    private const INSERT_CHUNK_SIZE = 1000;

    private FormatService $formatService;

    private BulkdataService $bulkdataService;

    private bool $shadow = false;

    private string $oracleTranslationsTable = 'oracle_card_translations';

    private string $faceTranslationsTable = 'oracle_card_face_translations';

    private string $oracleCardsTable = 'oracle_cards';

    private string $oracleCardFacesTable = 'oracle_card_faces';

    /**
     * Oracle IDs known to exist in the oracle_cards(__shadow) table.
     * Built once before traversal so each printing can be FK-checked
     * without a per-row query.
     *
     * @var array<string, true>
     */
    private array $knownOracleIds = [];

    /**
     * Face index lookup: `[oracle_card_id][face_index] => true` for
     * every row present in oracle_card_faces(__shadow). Used to skip
     * printings whose face count exceeds the oracle's face count
     * (rare but happens for proxies / data anomalies).
     *
     * @var array<string, array<int, true>>
     */
    private array $knownFaces = [];

    /**
     * Oracle-level translations buffer, keyed by "$oracleId|$lang"
     * to dedupe across reprints. Flushed to DB at end of walk.
     *
     * @var array<string, array{oracle_card_id: string, lang: string, printed_name: string, searchable_name: string}>
     */
    private array $oracleBuffer = [];

    /**
     * Face-level translations buffer, keyed by
     * "$oracleId|$faceIndex|$lang" to dedupe across reprints.
     * Flushed to DB at end of walk.
     *
     * @var array<string, array{oracle_card_id: string, face_index: int, lang: string, printed_name: string, searchable_name: string}>
     */
    private array $faceBuffer = [];

    /**
     * Skip counters for the end-of-run log line.
     */
    private int $skippedEnglish = 0;

    private int $skippedUnknownOracle = 0;

    private int $skippedMissingPrintedName = 0;

    private int $skippedUnknownFace = 0;

    private int $oracleDedupeHits = 0;

    private int $faceDedupeHits = 0;

    public function __construct()
    {
        $this->formatService = new FormatService;
        $this->bulkdataService = new BulkdataService;
    }

    /**
     * Run a full translations import from Scryfall.
     *
     * Reads the `all_cards` download URI off the bulk_data(__shadow)
     * row, streams the gzipped JSONL bulk directly from Scryfall, and
     * bulk-inserts the deduped translation
     * rows into oracle_card_translations(__shadow) and
     * oracle_card_face_translations(__shadow).
     *
     * In shadow mode: rows are written to the `__shadow` tables and
     * FK lookups read from oracle_cards__shadow / oracle_card_faces__shadow
     * so the freshly imported oracle dataset is authoritative.
     */
    public function updateTranslations(bool $shadow = false): void
    {
        $this->shadow = $shadow;
        $this->oracleTranslationsTable = $this->tableName('oracle_card_translations', $shadow);
        $this->faceTranslationsTable = $this->tableName('oracle_card_face_translations', $shadow);
        $this->oracleCardsTable = $this->tableName('oracle_cards', $shadow);
        $this->oracleCardFacesTable = $this->tableName('oracle_card_faces', $shadow);

        $this->resetState();

        $downloadUri = $this->bulkdataService->resolveDownloadUri('all_cards', $shadow);
        if ($downloadUri === null) {
            $bulkTable = $this->tableName('bulk_data', $shadow);
            Log::channel('scryfall')->error("no 'all_cards' row found in $bulkTable — run `scryfall:bulk` first.");

            return;
        }

        $this->preRunCleanup();
        $this->loadKnownOracleIds();
        $this->loadKnownFaces();
        $this->traverseJson($downloadUri);
        $this->flushOracleBuffer();
        $this->flushFaceBuffer();
        $this->logSkipSummary();
    }

    /**
     * Reset buffers and counters between runs (the service is
     * sometimes reused inside the test process for back-to-back
     * shadow/live runs).
     */
    private function resetState(): void
    {
        $this->knownOracleIds = [];
        $this->knownFaces = [];
        $this->oracleBuffer = [];
        $this->faceBuffer = [];
        $this->skippedEnglish = 0;
        $this->skippedUnknownOracle = 0;
        $this->skippedMissingPrintedName = 0;
        $this->skippedUnknownFace = 0;
        $this->oracleDedupeHits = 0;
        $this->faceDedupeHits = 0;
    }

    /**
     * Truncate the live translation tables before a fresh import.
     *
     * Skipped in shadow mode — the orchestrator created empty
     * `oracle_card_translations__shadow` and
     * `oracle_card_face_translations__shadow` before invoking this
     * service.
     *
     * No `SET FOREIGN_KEY_CHECKS=0` wrapping (unlike
     * {@see RulingsService} / {@see OracleCardsService}) — those
     * services wrap defensively but their tables also have no
     * incoming FKs. The translation tables likewise only have
     * outgoing FKs (to `oracle_cards`), so truncating them needs no
     * FK toggle on MariaDB, and the bare `truncate()` call works
     * uniformly on SQLite (used by the unit-test suite) as well.
     */
    private function preRunCleanup(): void
    {
        if ($this->shadow) {
            return;
        }
        OracleCardFaceTranslation::truncate();
        OracleCardTranslation::truncate();
        Log::channel('scryfall')->notice('truncated oracle_card_translations and oracle_card_face_translations tables.');
    }

    /**
     * Load all oracle_card IDs into the in-memory FK lookup. Reads
     * from `oracle_cards__shadow` in shadow mode so newly imported
     * cards match and stale-only-in-live cards don't.
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
     * Load `(oracle_card_id, face_index)` pairs into the in-memory
     * face lookup. Same shadow-mode rationale as
     * {@see loadKnownOracleIds()}.
     */
    private function loadKnownFaces(): void
    {
        $rows = DB::table($this->oracleCardFacesTable)
            ->select(['oracle_card_id', 'face_index'])
            ->get();
        foreach ($rows as $row) {
            $this->knownFaces[$row->oracle_card_id][(int) $row->face_index] = true;
        }
        $count = number_format($rows->count(), 0, ',', '.');
        Log::channel('scryfall')->debug("loaded $count known oracle_card_faces pairs for FK lookup.");
    }

    /**
     * Stream the all_cards gzipped JSONL bulk directly from Scryfall,
     * inflating and decoding one line at a time — no on-disk file, no
     * full-response materialization in PHP memory.
     */
    private function traverseJson(string $downloadUri): void
    {
        $start = now();
        Log::channel('scryfall')->notice("begin streaming all_cards bulk from '$downloadUri'.");
        $count = $this->streamJsonl($downloadUri, function (array $printing): void {
            $this->bufferPrinting($printing);
        });
        $ms = $start->diffInMilliseconds(now());
        $numPrintings = number_format($count, 0, ',', '.');
        Log::channel('scryfall')->notice("finished streaming all_cards bulk ($numPrintings printings parsed) in ".$this->formatService->formatMs($ms).'.');
    }

    /**
     * Decide what oracle- and face-level translation rows (if any)
     * to buffer for a single printing. Skips:
     *  - English printings (already in `oracle_cards.name`)
     *  - printings whose oracle isn't in our shadow/live dataset
     *  - oracle entries without a `printed_name` (or face-0 fallback)
     *  - face entries whose `(oracleId, faceIndex)` isn't in the
     *    oracle_card_faces lookup
     *
     * @param  array<string, mixed>  $printing  A single card object from the bulk.
     */
    private function bufferPrinting(array $printing): void
    {
        $lang = $printing['lang'] ?? null;
        if ($lang === 'en' || ! is_string($lang)) {
            $this->skippedEnglish++;

            return;
        }

        $oracleId = $printing['oracle_id'] ?? null;
        if (! is_string($oracleId) || ! isset($this->knownOracleIds[$oracleId])) {
            $this->skippedUnknownOracle++;

            return;
        }

        $this->bufferOracleTranslation($printing, $oracleId, $lang);
        $this->bufferFaceTranslations($printing, $oracleId, $lang);
    }

    /**
     * Buffer the oracle-level translation row for a printing.
     *
     * Resolves the printed name: top-level `printed_name` first
     * (most reprints), falling back to `card_faces[0].printed_name`
     * for multi-faced layouts where Scryfall puts the name on the
     * face row instead of the top level. If neither is present,
     * skip — there's nothing to search on.
     *
     * @param  array<string, mixed>  $printing
     */
    private function bufferOracleTranslation(array $printing, string $oracleId, string $lang): void
    {
        $printedName = $printing['printed_name'] ?? null;
        if (! is_string($printedName) || $printedName === '') {
            $firstFace = $printing['card_faces'][0] ?? null;
            if (is_array($firstFace) && isset($firstFace['printed_name']) && is_string($firstFace['printed_name'])) {
                $printedName = $firstFace['printed_name'];
            }
        }

        if (! is_string($printedName) || $printedName === '') {
            $this->skippedMissingPrintedName++;

            return;
        }

        $key = "$oracleId|$lang";
        if (isset($this->oracleBuffer[$key])) {
            $this->oracleDedupeHits++;

            return;
        }

        $this->oracleBuffer[$key] = [
            'oracle_card_id' => $oracleId,
            'lang' => $lang,
            'printed_name' => $printedName,
            'searchable_name' => CardNameNormalizer::normalize($printedName),
        ];
    }

    /**
     * Buffer per-face translation rows for a printing's
     * `card_faces` array.
     *
     * For reversible layouts the parent oracle differs from the
     * printing's `oracle_id` — Scryfall puts the per-face oracle on
     * `card_faces[i].oracle_id`. Fall back to the printing's
     * `oracle_id` for non-reversible multi-faced layouts (transform,
     * MDFC, split, adventure) where the parent oracle is shared.
     *
     * @param  array<string, mixed>  $printing
     */
    private function bufferFaceTranslations(array $printing, string $printingOracleId, string $lang): void
    {
        $faces = $printing['card_faces'] ?? null;
        if (! is_array($faces)) {
            return;
        }

        foreach ($faces as $index => $face) {
            if (! is_array($face)) {
                continue;
            }
            $printedName = $face['printed_name'] ?? null;
            if (! is_string($printedName) || $printedName === '') {
                continue;
            }

            $faceOracleId = (isset($face['oracle_id']) && is_string($face['oracle_id']))
                ? $face['oracle_id']
                : $printingOracleId;
            $faceIndex = (int) $index;

            if (! isset($this->knownFaces[$faceOracleId][$faceIndex])) {
                $this->skippedUnknownFace++;

                continue;
            }

            $key = "$faceOracleId|$faceIndex|$lang";
            if (isset($this->faceBuffer[$key])) {
                $this->faceDedupeHits++;

                continue;
            }

            $this->faceBuffer[$key] = [
                'oracle_card_id' => $faceOracleId,
                'face_index' => $faceIndex,
                'lang' => $lang,
                'printed_name' => $printedName,
                'searchable_name' => CardNameNormalizer::normalize($printedName),
            ];
        }
    }

    /**
     * Chunk-insert all buffered oracle translations and clear the
     * buffer. Single statement per chunk keeps placeholder count
     * far below MySQL's 65 535-per-statement cap.
     */
    private function flushOracleBuffer(): void
    {
        if ($this->oracleBuffer === []) {
            return;
        }
        $rows = array_values($this->oracleBuffer);
        foreach (array_chunk($rows, self::INSERT_CHUNK_SIZE) as $chunk) {
            DB::table($this->oracleTranslationsTable)->insert($chunk);
        }
        $inserted = number_format(count($rows), 0, ',', '.');
        Log::channel('scryfall')->notice("inserted $inserted rows into $this->oracleTranslationsTable.");
        $this->oracleBuffer = [];
    }

    /**
     * Chunk-insert all buffered face translations and clear the
     * buffer.
     */
    private function flushFaceBuffer(): void
    {
        if ($this->faceBuffer === []) {
            return;
        }
        $rows = array_values($this->faceBuffer);
        foreach (array_chunk($rows, self::INSERT_CHUNK_SIZE) as $chunk) {
            DB::table($this->faceTranslationsTable)->insert($chunk);
        }
        $inserted = number_format(count($rows), 0, ',', '.');
        Log::channel('scryfall')->notice("inserted $inserted rows into $this->faceTranslationsTable.");
        $this->faceBuffer = [];
    }

    /**
     * Single-line skip + dedupe summary so the run footprint is
     * grep-able from the scryfall log.
     */
    private function logSkipSummary(): void
    {
        $fmt = fn (int $n): string => number_format($n, 0, ',', '.');
        Log::channel('scryfall')->notice(sprintf(
            'translations skip summary — english: %s, unknown oracle: %s, missing printed_name: %s, unknown face: %s; dedupe hits — oracle: %s, face: %s.',
            $fmt($this->skippedEnglish),
            $fmt($this->skippedUnknownOracle),
            $fmt($this->skippedMissingPrintedName),
            $fmt($this->skippedUnknownFace),
            $fmt($this->oracleDedupeHits),
            $fmt($this->faceDedupeHits),
        ));
    }
}
