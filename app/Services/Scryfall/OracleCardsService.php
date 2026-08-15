<?php

namespace App\Services\Scryfall;

use App\Enums\CardLegality;
use App\Models\OracleCard;
use App\Models\OracleCardFace;
use App\Models\OracleCardLegality;
use App\Services\CardNameNormalizer;
use App\Services\FormatService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Streaming strategy
 * ------------------
 * {@see ScryfallService::streamJsonl()} inflates and reads the gzipped
 * JSON Lines bulk one line at a time, so the full bulk is never
 * materialized in memory. No on-disk caching; every run re-fetches from
 * Scryfall. Tradeoff: a mid-stream abort means the next run re-fetches.
 *
 * Eloquent is unsuitable for the inserts here — the `$table` property is
 * hardcoded on each model, but we need to write to either the live or
 * shadow table at runtime. Plain `DB::table($name)->insert(...)` is the
 * documented escape hatch (see CLAUDE.md, "Shadow-table architecture").
 */
class OracleCardsService extends ScryfallService
{
    private const LEGALITY_BUFFER_SIZE = 500;

    private const FACE_BUFFER_SIZE = 500;

    /** Width of `oracle_cards.type_line`; see {@see combinedTypeLine()}. */
    private const TYPE_LINE_MAX = 160;

    /**
     * Columns this importer writes that arrived after the table's original
     * migration, and so can be absent on a host that has not migrated yet.
     * Checked once per run — see {@see updateOracleCards()}.
     */
    private const REQUIRED_COLUMNS = ['type_line'];

    private FormatService $formatService;

    private BulkdataService $bulkdataService;

    private bool $shadow = false;

    private string $oracleCardsTable = 'oracle_cards';

    private string $oracleCardFacesTable = 'oracle_card_faces';

    private string $legalitiesTable = 'legalities';

    /** @var array<array{oracle_card_id: string, format: string, legality: string}> */
    private array $legalityBuffer = [];

    /** @var array<array<string, mixed>> */
    private array $faceBuffer = [];

    public function __construct()
    {
        $this->formatService = new FormatService;
        $this->bulkdataService = new BulkdataService;
    }

    /**
     * Truncate the live oracle_cards, oracle_card_faces and legalities
     * tables before a fresh import.
     *
     * Skipped in shadow mode — the orchestrator created empty shadows
     * for all three tables before invoking this service.
     */
    private function preRunCleanup(): void
    {
        if ($this->shadow) {
            return;
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        OracleCardLegality::truncate();
        OracleCardFace::truncate();
        OracleCard::truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');
        Log::channel('scryfall')->notice('truncated oracle_cards, oracle_card_faces and legalities tables.');
    }

    /**
     * Persist a single oracle card to the database along with its face rows.
     *
     * Card-level fields (name, layout, cmc, color_identity, etc.) go to
     * oracle_cards. Per-face fields (type_line, oracle_text, mana_cost,
     * colors, power/toughness/loyalty/defense, image) go to oracle_card_faces
     * via the face buffer. Single-faced cards get 1 face row synthesized from
     * root-level fields; multi-faced cards get one row per card_faces entry.
     *
     * @param  array  $card  A single card object from the oracle_cards bulk JSON.
     */
    private function insertCard(array $card): void
    {
        // non nullable values
        $arr = [
            'id' => $card['oracle_id'],
            'name' => $card['name'],
            'searchable_name' => CardNameNormalizer::normalize($card['name']),
            'collector_number' => $card['collector_number'],
            'lang' => $card['lang'],
            'cmc' => $card['cmc'],
            'reserved' => $card['reserved'],
            'game_changer' => $card['game_changer'],
            'scryfall_uri' => $card['scryfall_uri'],
        ];
        // nullable values
        if (array_key_exists('layout', $card)) {
            $arr['layout'] = $card['layout'];
        }
        if (array_key_exists('color_identity', $card) && count($card['color_identity']) > 0) {
            $arr['color_identity'] = implode('', $card['color_identity']);
        }
        $typeLine = self::combinedTypeLine($card);
        if ($typeLine !== '') {
            $arr['type_line'] = $typeLine;
        }
        if (array_key_exists('produced_mana', $card) && is_array($card['produced_mana']) && count($card['produced_mana']) > 0) {
            $arr['produced_mana'] = implode('', $card['produced_mana']);
        }
        // insert into db
        try {
            DB::table($this->oracleCardsTable)->insert($arr);
            Log::channel('scryfall')->debug('Inserted OracleCard "'.$card['name'].'".');
            $this->bufferFaces($card);
            $this->bufferLegalities($card['oracle_id'], $card['legalities'] ?? []);
        } catch (\Exception $e) {
            Log::channel('scryfall')->error('error inserting card '.$card['name'].': '.$e->getMessage());
            Log::channel('scryfall')->error($e->getTraceAsString());
        }
    }

    /**
     * The card's full type line, every face included.
     *
     * Denormalised onto oracle_cards so the deck-card search filter can exempt
     * cards by type in a single predicate — see the migration that adds the
     * column for the measurement behind that.
     *
     * Scryfall already joins both halves at the root of a multi-faced card
     * ("Instant // Sorcery"), so the root value is preferred verbatim and the
     * separator matches it. Layouts that carry no root type_line (reversible
     * cards) fall back to joining the faces the same way.
     *
     * Truncated to the column width rather than risking a failed insert: this
     * method's caller catches and logs, so an over-long value would drop the
     * whole card from the dataset. Widest real value is 91 of 160 characters,
     * so the guard is not expected to fire.
     *
     * Public and static because it is a pure mapping from bulk JSON to one
     * string, and the rest of this class cannot be exercised on SQLite —
     * `preRunCleanup()` emits `SET FOREIGN_KEY_CHECKS`, which only MariaDB
     * understands. Keeping this reachable lets the layout handling be tested
     * directly instead of not at all.
     *
     * @param  array  $card  A single card object from the oracle_cards bulk JSON.
     */
    public static function combinedTypeLine(array $card): string
    {
        $line = trim((string) ($card['type_line'] ?? ''));

        if ($line === '' && array_key_exists('card_faces', $card) && is_array($card['card_faces'])) {
            $faceLines = [];
            foreach ($card['card_faces'] as $face) {
                $faceLine = trim((string) ($face['type_line'] ?? ''));
                if ($faceLine !== '') {
                    $faceLines[] = $faceLine;
                }
            }
            $line = implode(' // ', $faceLines);
        }

        if (mb_strlen($line) > self::TYPE_LINE_MAX) {
            // Logged rather than truncated silently: the consumer of this
            // column is a type-matching predicate, so a dropped tail would
            // mis-classify that card indefinitely with nothing to point at.
            Log::channel('scryfall')->warning(
                'type_line for "'.($card['name'] ?? 'unknown').'" exceeded '.self::TYPE_LINE_MAX.' chars ('.mb_strlen($line).') and was truncated.'
            );

            return mb_substr($line, 0, self::TYPE_LINE_MAX);
        }

        return $line;
    }

    /**
     * Buffer face rows for a card. Single-faced cards produce exactly one
     * face row synthesized from root-level fields; multi-faced cards produce
     * one row per entry in the card_faces array.
     *
     * @param  array  $card  A single card object from the oracle_cards bulk JSON.
     */
    private function bufferFaces(array $card): void
    {
        if (array_key_exists('card_faces', $card) && is_array($card['card_faces']) && count($card['card_faces']) > 0) {
            foreach ($card['card_faces'] as $index => $face) {
                $this->faceBuffer[] = $this->buildFaceRow(
                    oracleCardId: $card['oracle_id'],
                    faceIndex: $index,
                    source: $face,
                );
            }
        } else {
            $this->faceBuffer[] = $this->buildFaceRow(
                oracleCardId: $card['oracle_id'],
                faceIndex: 0,
                source: $card,
            );
        }

        if (count($this->faceBuffer) >= self::FACE_BUFFER_SIZE) {
            $this->flushFaceBuffer();
        }
    }

    /**
     * Build a single oracle_card_faces row from a card or card_face object.
     *
     * @param  string  $oracleCardId  The parent oracle card UUID.
     * @param  int  $faceIndex  0 = front, 1 = back (or higher for split cards).
     * @param  array  $source  Root card object or card_faces entry.
     * @return array<string, mixed>
     */
    private function buildFaceRow(string $oracleCardId, int $faceIndex, array $source): array
    {
        return [
            'id' => (string) Str::uuid(),
            'oracle_card_id' => $oracleCardId,
            'face_index' => $faceIndex,
            'name' => $source['name'] ?? '',
            'mana_cost' => $source['mana_cost'] ?? null,
            'type_line' => $source['type_line'] ?? '',
            'oracle_text' => $source['oracle_text'] ?? null,
            'colors' => (array_key_exists('colors', $source) && count($source['colors']) > 0)
                ? implode('', $source['colors'])
                : null,
            'power' => $source['power'] ?? null,
            'toughness' => $source['toughness'] ?? null,
            'loyalty' => $source['loyalty'] ?? null,
            'defense' => $source['defense'] ?? null,
        ];
    }

    /**
     * Insert all buffered face rows and clear the buffer.
     */
    private function flushFaceBuffer(): void
    {
        if (empty($this->faceBuffer)) {
            return;
        }

        DB::table($this->oracleCardFacesTable)->insert($this->faceBuffer);
        $this->faceBuffer = [];
    }

    /**
     * Buffer legality rows for a card, flushing when the buffer is full.
     *
     * Skips `not_legal` entries — absence from the table implies not legal.
     *
     * @param  array<string, string>  $legalities  Format → status map from Scryfall.
     */
    private function bufferLegalities(string $oracleCardId, array $legalities): void
    {
        foreach ($legalities as $format => $status) {
            $legality = CardLegality::tryFrom($status);
            if (! $legality) {
                continue;
            }

            $this->legalityBuffer[] = [
                'oracle_card_id' => $oracleCardId,
                'format' => $format,
                'legality' => $legality->value,
            ];
        }

        if (count($this->legalityBuffer) >= self::LEGALITY_BUFFER_SIZE) {
            $this->flushLegalityBuffer();
        }
    }

    /**
     * Insert all buffered legality rows and clear the buffer.
     */
    private function flushLegalityBuffer(): void
    {
        if (empty($this->legalityBuffer)) {
            return;
        }

        DB::table($this->legalitiesTable)->insert($this->legalityBuffer);
        $this->legalityBuffer = [];
    }

    /**
     * Stream the gzipped JSONL bulk directly from Scryfall and insert each
     * card — no on-disk file, no full-response materialization in PHP
     * memory.
     */
    private function traverseJson(string $downloadUri): void
    {
        $start = now();
        Log::channel('scryfall')->notice("begin streaming oracle_cards bulk from '$downloadUri'.");
        $count = $this->streamJsonl($downloadUri, function (array $card): void {
            $this->insertCard($card);
        });
        $this->flushFaceBuffer();
        $this->flushLegalityBuffer();
        $ms = $start->diffInMilliseconds(now());
        $numCards = number_format($count, 0, ',', '.');
        Log::channel('scryfall')->notice("finished inserting $numCards oracle cards into database in ".$this->formatService->formatMs($ms).'.');
    }

    /**
     * Run a full oracle-cards import from Scryfall.
     *
     * Resolves the `oracle_cards` download URI from bulk_data(__shadow),
     * truncates the existing live data (or skips in shadow mode where the
     * orchestrator pre-created empty shadows), and stream-parses every
     * card from the live Scryfall CDN.
     *
     * In shadow mode: rows are inserted into oracle_cards__shadow,
     * oracle_card_faces__shadow, and legalities__shadow. The BulkdataService
     * lookup reads from bulk_data__shadow so the latest download URI is used.
     */
    public function updateOracleCards(bool $shadow = false): void
    {
        $this->shadow = $shadow;
        $this->oracleCardsTable = $this->tableName('oracle_cards', $shadow);
        $this->oracleCardFacesTable = $this->tableName('oracle_card_faces', $shadow);
        $this->legalitiesTable = $this->tableName('legalities', $shadow);

        // Fail before the download, not during it. Every insert below is
        // wrapped in a per-card catch, so a target table missing a column this
        // importer writes does not surface as an error — it surfaces as ~38k
        // logged failures and a run that still reports completion, after a
        // ~475 MB bulk has been pulled. The shadow flow's orphan validator
        // would abort the swap afterwards, but on the actual cause it is
        // silent. Checking here turns deploy-order drift (code live, migration
        // not yet run) into one line naming the fix.
        foreach (self::REQUIRED_COLUMNS as $column) {
            if (! Schema::hasColumn($this->oracleCardsTable, $column)) {
                $message = "$this->oracleCardsTable is missing the `$column` column — run `php artisan migrate` before importing.";
                Log::channel('scryfall')->error("$message Aborting before the bulk download.");

                // Thrown rather than returned, unlike the missing-bulk path
                // below. UpdateEverything::runStep() catches, raises the mail +
                // Discord alert and rethrows, so this stops the orchestrator
                // here instead of letting it walk default_cards, rulings and
                // images against an empty table for several minutes and then
                // fail at the orphan validator — which reports an orphan count,
                // not the schema drift that caused it.
                throw new RuntimeException($message);
            }
        }

        $downloadUri = $this->bulkdataService->resolveDownloadUri('oracle_cards', $shadow);
        if ($downloadUri === null) {
            $bulkTable = $this->tableName('bulk_data', $shadow);
            Log::channel('scryfall')->error("no 'oracle_cards' row found in $bulkTable — run `scryfall:bulk` first.");

            return;
        }
        $this->preRunCleanup();
        $this->traverseJson($downloadUri);
    }
}
