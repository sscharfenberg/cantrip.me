<?php

namespace App\Http\Controllers\Decks;

use App\Http\Controllers\Controller;
use App\Http\Requests\Decks\ExportDeckRequest;
use App\Models\Deck;
use App\Services\DeckCsvExportService;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportController extends Controller
{
    /**
     * Stream a CSV export of a single deck — commanders, companion, and
     * deck cards in role order. Public decks are exportable by anyone
     * with the link (mirroring the show page); the owner-only `Card
     * Stack ID` pivot column is blanked for non-owners so a public
     * export never leaks the owner's collection ids.
     */
    public function deck(ExportDeckRequest $request, Deck $deck): StreamedResponse
    {
        $isOwner = $request->user()?->id === $deck->user_id;

        return DeckCsvExportService::streamDeckCsv($deck, includeStackIds: $isOwner);
    }
}
