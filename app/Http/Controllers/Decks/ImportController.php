<?php

namespace App\Http\Controllers\Decks;

use App\Enums\CardFormat;
use App\Enums\DeckImportSource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Decks\ShowDeckImportRequest;
use App\Http\Requests\Decks\StoreDeckImportRequest;
use App\Models\Deck;
use App\Models\User;
use App\Services\DeckCsvImportService;
use App\Services\DeckService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ImportController extends Controller
{
    /**
     * Show the deck CSV import page.
     *
     * The route is deck-agnostic ({@see /decks/import}) because both
     * source paths sidestep the URL deck: cantrip mints a fresh deck
     * from the form's format dropdown, Archidekt requires an explicit
     * target chosen via the "Import to" select.
     */
    public function show(ShowDeckImportRequest $request): Response
    {
        return Inertia::render('Deck/Import/CsvImportPage', [
            'decks' => self::deckOptions($request->user()),
            'maxUploadBytes' => (int) config('cantrip.csv_upload.max_bytes'),
            'allowedTypes' => config('cantrip.csv_upload.allowed_types'),
            'sources' => array_column(DeckImportSource::cases(), 'value'),
            'formats' => array_column(CardFormat::cases(), 'value'),
            'results' => null,
        ]);
    }

    /**
     * Process the uploaded CSV.
     *
     * Routes to the cantrip path (creates a fresh deck) or the
     * Archidekt path (loads into the chosen empty deck). Both flow
     * through {@see DeckCsvImportService::import}, which is now purely
     * additive — it never wipes data.
     *
     * @throws ValidationException When the upload doesn't exist on the
     *                             tmp disk or the CSV is unparseable /
     *                             missing required headers.
     */
    public function store(StoreDeckImportRequest $request): Response
    {
        $validated = $request->validated();
        $source = DeckImportSource::from($validated['source']);

        if (! Storage::disk('tmp')->exists($validated['filename'])) {
            throw ValidationException::withMessages([
                'filename' => [__('validation.custom.file.not_found')],
            ]);
        }

        $targetDeck = $source === DeckImportSource::Cantrip
            ? self::createBlankDeck($request->user(), $validated['format'])
            : Deck::query()
                ->where('user_id', $request->user()->id)
                ->findOrFail($validated['deck']);

        $results = DeckCsvImportService::import(
            $request->user(),
            $targetDeck,
            $validated['filename'],
            $source,
        );

        return Inertia::render('Deck/Import/CsvImportPage', [
            'decks' => self::deckOptions($request->user()),
            'maxUploadBytes' => (int) config('cantrip.csv_upload.max_bytes'),
            'allowedTypes' => config('cantrip.csv_upload.allowed_types'),
            'sources' => array_column(DeckImportSource::cases(), 'value'),
            'formats' => array_column(CardFormat::cases(), 'value'),
            'results' => $results + [
                'deck' => [
                    'id' => $targetDeck->id,
                    'name' => $targetDeck->name,
                ],
            ],
        ]);
    }

    /**
     * Create a brand-new empty deck for a cantrip-path import.
     *
     * The deck is intentionally minted directly via Eloquent rather
     * than {@see DeckService::createDeck}, because that
     * service enforces "format X requires a commander" — for an
     * import, the commanders arrive *after* the deck is created, when
     * the import service walks the CSV's `Role=commander` rows.
     */
    private static function createBlankDeck(User $user, string $format): Deck
    {
        return Deck::create([
            'user_id' => $user->id,
            'name' => __('decks.import.default_deck_name'),
            'format' => CardFormat::from($format),
            'colors' => null,
            'bracket' => null,
        ]);
    }

    /**
     * Build the "Import to" dropdown payload: every deck owned by the
     * user with id, name, format, and combined card count
     * (deck_cards.quantity sum + commanders count). Sorted card_count
     * ascending then name ascending so empty decks float to the top —
     * those are the natural Archidekt-import targets.
     *
     * @return array<int, array{id: string, name: string, format: string, card_count: int}>
     */
    private static function deckOptions($user): array
    {
        return Deck::query()
            ->where('user_id', $user->id)
            ->withCount(['commanders'])
            ->withSum('deckCards as deck_cards_quantity', 'quantity')
            ->get(['id', 'name', 'format'])
            ->map(fn (Deck $d) => [
                'id' => $d->id,
                'name' => $d->name,
                'format' => $d->format->value,
                'card_count' => (int) $d->deck_cards_quantity + (int) $d->commanders_count,
            ])
            ->sortBy([
                ['card_count', 'asc'],
                ['name', 'asc'],
            ])
            ->values()
            ->all();
    }
}
