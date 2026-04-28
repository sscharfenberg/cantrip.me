<?php

namespace App\Http\Controllers\Decks;

use App\Http\Controllers\Controller;
use App\Http\Requests\Decks\SearchDeckOracleRequest;
use App\Http\Requests\Decks\SearchDeckPrintingsRequest;
use App\Models\Deck;
use App\Services\DeckCardSearchService;
use Illuminate\Http\JsonResponse;

class DeckCardSearchController extends Controller
{
    /**
     * Oracle-level card search for the deck (quick-add input).
     *
     * Returns distinct oracle cards with their newest printing auto-resolved.
     * Any `set:` / `cn:` tokens in the query are ignored — this endpoint is
     * for picking a card by name, not by printing.
     */
    public function oracle(SearchDeckOracleRequest $request, Deck $deck): JsonResponse
    {
        $results = DeckCardSearchService::searchOracleForDeck(
            $deck,
            trim((string) $request->query('q', ''))
        );

        return response()->json($results);
    }

    /**
     * Quick-add oracle card search.
     *
     * Returns oracle cards (one row per card, no printings) shaped like the
     * commander search — name, color identity, and per-face type line +
     * mana cost. Honors `set:` / `cn:` tokens so the user can narrow by
     * printing provenance even though the response is oracle-level.
     */
    public function oracleCards(SearchDeckOracleRequest $request, Deck $deck): JsonResponse
    {
        $results = DeckCardSearchService::searchOracleCardsForDeck(
            $deck,
            trim((string) $request->query('q', ''))
        );

        return response()->json($results);
    }

    /**
     * Printing-level card search for the deck (full card-add modal).
     *
     * Honors `set:` / `cn:` tokens so the user can pin results to a specific
     * printing, and can return multiple printings of the same oracle card.
     * When `include_non_legal=1`, the format-legality filter is dropped but
     * color identity is still enforced — the Rule 0 escape hatch.
     */
    public function printings(SearchDeckPrintingsRequest $request, Deck $deck): JsonResponse
    {
        $results = DeckCardSearchService::searchPrintingsForDeck(
            $deck,
            trim((string) $request->query('q', '')),
            $request->boolean('include_non_legal'),
        );

        return response()->json($results);
    }
}
