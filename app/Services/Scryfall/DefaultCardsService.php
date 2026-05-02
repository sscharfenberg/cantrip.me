<?php

namespace App\Services\Scryfall;

use App\Enums\Finish;
use App\Enums\Game;
use App\Enums\Scryfall\ScryfallRelatedComponent;
use App\Models\DefaultCard;
use App\Models\DefaultCardRelation;
use App\Services\CardNameNormalizer;
use App\Services\FormatService;
use Cerbero\JsonParser\JsonParser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Handles all database operations for the default_cards table.
 *
 * This service owns the DB writes for default cards. During import, it stores
 * Scryfall URLs as-is. Path resolution to local cache is handled separately
 * by ResolveImagePathsService after ImageDownloadService has fetched images.
 *
 * Separation of concerns:
 *   - DefaultCardsService         → database (import, stores Scryfall URLs)
 *   - ImageDownloadService        → filesystem (downloading images to disk)
 *   - ResolveImagePathsService    → database (URL → local path resolution)
 *
 * Also captures `all_parts` printing-pair edges into default_card_relations
 * during the same file walk — buffered in memory throughout traversal and
 * flushed once at the end so every printing referenced by an edge has
 * already been inserted (Scryfall's bulk isn't dependency-ordered).
 */
class DefaultCardsService
{
    private ScryfallImageService $imageService;

    private ArtistsService $artistsService;

    private FormatService $formatService;

    private BulkdataService $bulkdataService;

    /**
     * Buffered all_parts edges, flushed at the end of the file walk.
     *
     * @var array<array{source_default_card_id: string, related_default_card_id: string, component: string}>
     */
    private array $relationsBuffer = [];

    private int $relationsInserted = 0;

    private int $relationsSkippedComponent = 0;

    public function __construct()
    {
        $this->imageService = new ScryfallImageService;
        $this->artistsService = new ArtistsService;
        $this->formatService = new FormatService;
        $this->bulkdataService = new BulkdataService;
    }

    /**
     * Truncate the default_cards, default_card_relations and artists
     * tables before a fresh import.
     *
     * Temporarily disables foreign key checks to allow truncation.
     */
    private function preRunCleanup(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        DefaultCardRelation::truncate();
        DefaultCard::truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');
        Log::channel('scryfall')->notice('truncated default_cards and default_card_relations tables.');
        $this->artistsService->truncate();
    }

    /**
     * Persist a single default card to the database.
     *
     * Maps required Scryfall fields (prices, finishes, rarity, etc.) and
     * conditionally includes optional ones (oracle_id, layout, artist_id).
     * Image URLs are stored as Scryfall URLs; resolution to local paths
     * happens later via ResolveImagePathsService.
     *
     * @param  array  $card  A single card object from the default_cards bulk JSON.
     */
    private function insertCard(array $card): void
    {
        $cardImages = $this->imageService->getCardImages($card);
        // non nullable values
        $arr = [
            'id' => $card['id'],
            'name' => $card['name'],
            'searchable_name' => CardNameNormalizer::normalize($card['name']),
            'collector_number' => $card['collector_number'],
            'lang' => $card['lang'],
            'card_image_0' => $cardImages['card_image_0'],
            'card_image_1' => $cardImages['card_image_1'],
            'art_crop' => $this->imageService->getArtCrop($card),
            'finishes' => Finish::fromScryfallArray($card['finishes']),
            'games' => Game::fromScryfallArray($card['games']),
            'price_usd' => $card['prices']['usd'] ?? null,
            'price_usd_foil' => $card['prices']['usd_foil'] ?? null,
            'price_usd_etched' => $card['prices']['usd_etched'] ?? null,
            'price_eur' => $card['prices']['eur'] ?? null,
            'price_eur_foil' => $card['prices']['eur_foil'] ?? null,
            'price_eur_etched' => $card['prices']['eur_etched'] ?? null,
            'digital' => $card['digital'],
            'rarity' => $card['rarity'],
            'set_id' => $card['set_id'],
            'artist_id' => $this->artistsService->resolveArtistId($card['artist'] ?? null),
        ];
        // nullable values
        if (array_key_exists('oracle_id', $card)) {
            $arr['oracle_id'] = $card['oracle_id'];
        }
        if (array_key_exists('layout', $card)) {
            $arr['layout'] = $card['layout'];
        }
        // insert into db
        try {
            DefaultCard::create($arr);
        } catch (\Exception $e) {
            Log::channel('scryfall')->error('error inserting DefaultCard ['.strtoupper($card['set']).'] '.$card['name'].': '.$e->getMessage());
            Log::channel('scryfall')->error($e->getTraceAsString());
        }
    }

    /**
     * Buffer a card's `all_parts` edges as default_card_relations rows.
     *
     * Skips entries with an unknown `component` value (Scryfall could
     * theoretically add a fifth) and self-references (Scryfall sometimes
     * lists the source itself as a `combo_piece`). FK safety is implicit:
     * the buffer is only flushed at the end of the file walk by
     * {@see flushRelationsBuffer()}, by which point every printing
     * referenced here has been inserted by {@see insertCard()}.
     *
     * Periodic mid-walk flushing is deliberately NOT done — a relation
     * row whose related_id sits later in the bulk would FK-fail if
     * flushed before its target card was inserted. ~120 k rows × ~50 B
     * = ~6 MB peak, freed after the single end-of-walk flush.
     *
     * @param  array  $card  A single card object from the bulk JSON.
     */
    private function bufferRelations(array $card): void
    {
        if (! isset($card['all_parts']) || ! is_array($card['all_parts'])) {
            return;
        }

        foreach ($card['all_parts'] as $part) {
            $component = ScryfallRelatedComponent::tryFrom($part['component'] ?? '');
            if ($component === null) {
                $this->relationsSkippedComponent++;

                continue;
            }
            $relatedId = $part['id'] ?? null;
            if ($relatedId === null || $relatedId === $card['id']) {
                continue;
            }
            $this->relationsBuffer[] = [
                'source_default_card_id' => $card['id'],
                'related_default_card_id' => $relatedId,
                'component' => $component->value,
            ];
        }
    }

    /**
     * Bulk-insert the buffered relations in chunks and clear the buffer.
     *
     * Chunked to stay under MySQL's 65 535-placeholder ceiling per
     * prepared statement (3 columns × 5 000 rows = 15 000 placeholders,
     * comfortable headroom). `insertOrIgnore` silently drops duplicate
     * composite keys (Scryfall sometimes emits the same edge twice across
     * reprints) and any rare orphan that slipped through (related_id
     * pointing to a printing not in the bulk).
     */
    private function flushRelationsBuffer(): void
    {
        if (empty($this->relationsBuffer)) {
            return;
        }

        foreach (array_chunk($this->relationsBuffer, 5000) as $chunk) {
            $this->relationsInserted += DefaultCardRelation::insertOrIgnore($chunk);
        }
        $this->relationsBuffer = [];
    }

    /**
     * Stream-parse the bulk JSON file and insert each card. Card edges
     * (all_parts → default_card_relations) are buffered during the walk
     * and flushed once at the end so FK constraints hold without a
     * dependency-ordered traversal.
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
        Log::channel('scryfall')->notice("begin traversing $fileName.");
        JsonParser::parse(Storage::disk('scryfall-bulk')->get($fileName))->traverse(function (mixed $value, string|int $key, JsonParser $parser) use (&$count) {
            $this->insertCard($value);
            $this->bufferRelations($value);
            $count++;
        });
        $this->flushRelationsBuffer();
        $ms = $start->diffInMilliseconds(now());
        $numCards = number_format($count, 0, ',', '.');
        $numRelations = number_format($this->relationsInserted, 0, ',', '.');
        $numSkipped = number_format($this->relationsSkippedComponent, 0, ',', '.');
        Log::channel('scryfall')->notice("finished inserting $numCards cards and $numRelations relations into database in ".$this->formatService->formatMs($ms)." (skipped $numSkipped relations for unknown component).");
    }

    /**
     * Run a full default-cards import from Scryfall.
     *
     * Downloads the "default_cards" bulk JSON (if not already cached),
     * truncates the existing data, streams through every card to insert
     * it, and cleans up the downloaded file afterwards.
     */
    public function updateAllCards(): void
    {
        $type = 'default_cards';
        if (! $this->bulkdataService->prepareJson($type)) {
            Log::channel('scryfall')->error("error preparing '$type.json', aborting.");

            return; // error downloading file, abort
        }
        $this->preRunCleanup();
        $this->traverseJson($type.'.json');
        $this->bulkdataService->postRunCleanup($type.'.json');
    }
}
