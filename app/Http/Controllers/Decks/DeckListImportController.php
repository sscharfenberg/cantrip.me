<?php

namespace App\Http\Controllers\Decks;

use App\Enums\CardFormat;
use App\Http\Controllers\Controller;
use App\Http\Requests\Decks\ParseDeckListRequest;
use App\Http\Requests\Decks\SearchDeckListCardsRequest;
use App\Http\Requests\Decks\StoreDeckListImportRequest;
use App\Models\Deck;
use App\Services\DeckList\DeckListImportService;
use App\Services\DeckList\DeckListParser;
use App\Services\DeckList\DeckListResolver;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Create a deck from a pasted deck list.
 *
 * Three steps, one page: the paste form, a review of what the paste
 * resolved to, and the confirm that creates the deck. Parse and search are
 * read-only — nothing exists until confirm, so abandoning the review page
 * leaves nothing behind.
 */
class DeckListImportController extends Controller
{
    /** The paste form. */
    public function show(): Response
    {
        return Inertia::render('Deck/Import/DeckListImportPage', [
            'formats' => array_column(CardFormat::cases(), 'value'),
            'nameMax' => Deck::NAME_MAX,
            'maxChars' => ParseDeckListRequest::MAX_CHARS,
            'maxLines' => ParseDeckListRequest::MAX_LINES,
        ]);
    }

    /** Parse and resolve a paste for the review page. Writes nothing. */
    public function parse(ParseDeckListRequest $request): JsonResponse
    {
        $validated = $request->validated();

        return response()->json(DeckListResolver::resolve(
            $request->user(),
            CardFormat::from($validated['format']),
            DeckListParser::parse($validated['text']),
        ));
    }

    /** Find a card to replace an unresolved line with. */
    public function search(SearchDeckListCardsRequest $request): JsonResponse
    {
        $validated = $request->validated();

        return response()->json(DeckListResolver::search(
            $request->user(),
            CardFormat::from($validated['format']),
            $validated['q'],
            (int) ($validated['quantity'] ?? 1),
        ));
    }

    /**
     * Create the deck the review page confirmed.
     *
     * Answers with the deck's URL rather than a redirect: the page submits
     * with `fetch()` so validation errors come back as JSON, then visits
     * the URL itself, which also shows the flash set here.
     */
    public function store(StoreDeckListImportRequest $request): JsonResponse
    {
        $deck = DeckListImportService::import($request->user(), $request->validated());

        $request->session()->flash('message', __('decks.deck_created', ['name' => $deck->name]));
        $request->session()->flash('type', 'success');

        return response()->json(['redirect' => route('decks.show', $deck)]);
    }
}
