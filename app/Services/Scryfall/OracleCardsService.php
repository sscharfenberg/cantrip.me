<?php

namespace App\Services\Scryfall;

use App\Enums\CardLegality;
use App\Models\OracleCard;
use App\Models\OracleCardFace;
use App\Models\OracleCardLegality;
use App\Services\CardNameNormalizer;
use App\Services\FormatService;
use Cerbero\JsonParser\JsonParser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class OracleCardsService
{
    private const LEGALITY_BUFFER_SIZE = 500;

    private const FACE_BUFFER_SIZE = 500;

    private FormatService $formatService;

    private BulkdataService $bulkdataService;

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
     * Truncate the oracle_cards, oracle_card_faces and legalities tables
     * before a fresh import.
     *
     * Temporarily disables foreign key checks to allow truncation.
     */
    private function preRunCleanup(): void
    {
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
        if (array_key_exists('produced_mana', $card) && is_array($card['produced_mana']) && count($card['produced_mana']) > 0) {
            $arr['produced_mana'] = implode('', $card['produced_mana']);
        }
        // insert into db
        try {
            $newCard = OracleCard::create($arr);
            if ($newCard->wasRecentlyCreated) {
                Log::channel('scryfall')->debug('Inserted OracleCard "'.$newCard->name.'".');
                $this->bufferFaces($card);
                $this->bufferLegalities($card['oracle_id'], $card['legalities'] ?? []);
            }
        } catch (\Exception $e) {
            Log::channel('scryfall')->error('error inserting card '.$card['name'].': '.$e->getMessage());
            Log::channel('scryfall')->error($e->getTraceAsString());
        }
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

        OracleCardFace::insert($this->faceBuffer);
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

        OracleCardLegality::insert($this->legalityBuffer);
        $this->legalityBuffer = [];
    }

    /**
     * Stream-parse the bulk JSON file and insert each card.
     *
     * Uses JsonParser to avoid loading the entire file into memory,
     * which is critical for the large Scryfall bulk exports.
     *
     * @param  string  $fileName  The filename on the "scryfall-bulk" disk.
     */
    private function traverseJson($fileName): void
    {
        $start = now();
        $count = 0;
        Log::channel('scryfall')->notice('begin traversing oracle cards json.');
        JsonParser::parse(Storage::disk('scryfall-bulk')->get($fileName))->traverse(function (mixed $value, string|int $key, JsonParser $parser) use (&$count) {
            $this->insertCard($value);
            $count++;
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
     * Downloads the "oracle_cards" bulk JSON (if not already cached),
     * truncates the existing data, streams through every card to insert
     * it, and cleans up the downloaded file afterwards.
     */
    public function updateOracleCards(): void
    {
        $type = 'oracle_cards';
        if (! $this->bulkdataService->prepareJson($type)) {
            Log::channel('scryfall')->error("error preparing '$type.json', aborting.");

            return; // error downloading file, abort
        }
        $this->preRunCleanup();
        $this->traverseJson($type.'.json');
        $this->bulkdataService->postRunCleanup($type.'.json');
    }
}
