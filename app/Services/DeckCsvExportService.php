<?php

namespace App\Services;

use App\Models\Deck;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Stream a CSV export of a deck's composition: commanders, companion,
 * and deck_cards in that order. The shape is the deck-side counterpart
 * of {@see CsvExportService} — it deliberately drops stack-only fields
 * (condition, last modified, container) and the now-removed
 * deck_cards.finish / language columns. Stack-level facts are reachable
 * via the `Card Stack ID` pointer column for tools that want to
 * correlate against a collection export.
 *
 * Multi-claim handling: a deck card backed by N stacks ships as a single
 * row with `Card Stack ID` set to a comma-joined list. Keeps `Count`
 * meaningful as "how many copies the deck wants" and avoids re-aggregation
 * on import.
 */
class DeckCsvExportService
{
    /** @var array<string> */
    private const HEADERS = [
        'Role',
        'Deck Card ID',
        'Scryfall ID',
        'Name',
        'Edition',
        'Collector Number',
        'Count',
        'Zone',
        'Category',
        'Is Partner',
        'Card Stack ID',
    ];

    /**
     * Stream the deck CSV.
     *
     * @param  bool  $includeStackIds  When false, the `Card Stack ID`
     *                                 column is blanked for every row.
     *                                 Used for public-deck exports
     *                                 requested by non-owners — those
     *                                 ids are private to the owner's
     *                                 collection and would leak the
     *                                 owner's stacks otherwise.
     */
    public static function streamDeckCsv(Deck $deck, bool $includeStackIds): StreamedResponse
    {
        $filename = 'cantrip-deck-'.Str::slug($deck->name).'-'.now()->format('Y-m-d').'.csv';

        return new StreamedResponse(function () use ($deck, $includeStackIds) {
            $handle = fopen('php://output', 'w');

            // UTF-8 BOM so Excel interprets the file correctly.
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, self::HEADERS);

            self::writeCommanderRows($handle, $deck);
            self::writeCompanionRow($handle, $deck);
            self::writeDeckCardRows($handle, $deck, $includeStackIds);

            fclose($handle);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * @param  resource  $handle
     */
    private static function writeCommanderRows($handle, Deck $deck): void
    {
        // Post-consolidation, command-zone cards live in `deck_cards`
        // with `zone='command'` and `role in (commander, partner,
        // signature_spell)`. The CSV mirrors the database: `Role` carries
        // the real role string, `Zone` carries the real zone, and
        // `Is Partner` stays as a derived convenience column (true for
        // any non-primary command-zone slot) so older importers that
        // only knew about Role='commander' + Is Partner can still route
        // the row.
        $rows = DB::table('deck_cards')
            ->join('default_cards', 'deck_cards.default_card_id', '=', 'default_cards.id')
            ->leftJoin('sets', 'default_cards.set_id', '=', 'sets.id')
            ->where('deck_cards.deck_id', $deck->id)
            ->where('deck_cards.zone', 'command')
            ->orderByRaw("CASE deck_cards.role WHEN 'commander' THEN 0 ELSE 1 END")
            ->orderBy('deck_cards.created_at')
            ->get([
                'default_cards.id as scryfall_id',
                'default_cards.name as card_name',
                'default_cards.collector_number',
                'sets.code as set_code',
                'deck_cards.role',
            ]);

        foreach ($rows as $row) {
            $isPartner = $row->role !== 'commander';
            fputcsv($handle, [
                $row->role,
                '',
                $row->scryfall_id ?? '',
                $row->card_name ?? '',
                strtoupper($row->set_code ?? ''),
                $row->collector_number ?? '',
                1,
                'command',
                '',
                $isPartner ? 'true' : 'false',
                '',
            ]);
        }
    }

    /**
     * @param  resource  $handle
     */
    private static function writeCompanionRow($handle, Deck $deck): void
    {
        $row = DB::table('deck_cards')
            ->join('default_cards', 'deck_cards.default_card_id', '=', 'default_cards.id')
            ->leftJoin('sets', 'default_cards.set_id', '=', 'sets.id')
            ->where('deck_cards.deck_id', $deck->id)
            ->where('deck_cards.role', 'companion')
            ->first([
                'default_cards.id as scryfall_id',
                'default_cards.name as card_name',
                'default_cards.collector_number',
                'sets.code as set_code',
            ]);

        if ($row === null) {
            return;
        }

        fputcsv($handle, [
            'companion',
            '',
            $row->scryfall_id ?? '',
            $row->card_name ?? '',
            strtoupper($row->set_code ?? ''),
            $row->collector_number ?? '',
            1,
            'companion',
            '',
            '',
            '',
        ]);
    }

    /**
     * @param  resource  $handle
     */
    private static function writeDeckCardRows($handle, Deck $deck, bool $includeStackIds): void
    {
        // Pre-fetch claimed stack ids per deck_card in one query so the
        // main row loop doesn't N+1 across the pivot. Order the joined
        // ids so multi-claim rows are deterministic on disk. Skip the
        // pivot query entirely when the requester isn't the owner —
        // the column will be blank for every row anyway.
        $stackIdsByDeckCard = $includeStackIds
            ? DB::table('deck_card_card_stack')
                ->join('deck_cards', 'deck_cards.id', '=', 'deck_card_card_stack.deck_card_id')
                ->where('deck_cards.deck_id', $deck->id)
                ->orderBy('deck_card_card_stack.card_stack_id')
                ->get([
                    'deck_card_card_stack.deck_card_id',
                    'deck_card_card_stack.card_stack_id',
                ])
                ->groupBy('deck_card_id')
                ->map(fn ($group) => $group->pluck('card_stack_id')->all())
            : collect();

        // Command zone and companion are written separately above —
        // exclude them here so a deck_cards consolidation doesn't cause
        // them to be emitted twice in the CSV.
        $rows = DB::table('deck_cards')
            ->join('default_cards', 'deck_cards.default_card_id', '=', 'default_cards.id')
            ->leftJoin('sets', 'default_cards.set_id', '=', 'sets.id')
            ->leftJoin('deck_categories', 'deck_cards.category_id', '=', 'deck_categories.id')
            ->where('deck_cards.deck_id', $deck->id)
            ->whereNotIn('deck_cards.zone', ['command', 'companion'])
            ->orderBy('deck_cards.zone') // main before side
            ->orderBy('deck_categories.name')
            ->orderBy('default_cards.name')
            ->get([
                'deck_cards.id as deck_card_id',
                'deck_cards.zone',
                'deck_cards.quantity',
                'default_cards.id as scryfall_id',
                'default_cards.name as card_name',
                'default_cards.collector_number',
                'sets.code as set_code',
                'deck_categories.name as category_name',
            ]);

        foreach ($rows as $row) {
            $stackIds = $stackIdsByDeckCard->get($row->deck_card_id, []);
            fputcsv($handle, [
                'card',
                $row->deck_card_id,
                $row->scryfall_id ?? '',
                $row->card_name ?? '',
                strtoupper($row->set_code ?? ''),
                $row->collector_number ?? '',
                (int) $row->quantity,
                $row->zone ?? '',
                $row->category_name ?? '',
                '',
                $includeStackIds ? implode(',', $stackIds) : '',
            ]);
        }
    }
}
