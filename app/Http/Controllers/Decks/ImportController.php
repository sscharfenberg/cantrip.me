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
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ImportController extends Controller
{
    /**
     * Show the deck CSV import page.
     *
     * The route is deck-agnostic ({@see /decks/import}). Both source
     * paths mint a fresh deck from the form's format dropdown — there
     * is no longer an "Import to" target, so no deck listing is sent.
     */
    public function show(ShowDeckImportRequest $request): Response
    {
        return Inertia::render('Deck/Import/CsvImportPage', [
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
     * Both source paths (cantrip + Archidekt) mint a brand-new deck
     * with the chosen format, then hand off to
     * {@see DeckCsvImportService::import} which walks the CSV.
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

        $targetDeck = self::createBlankDeck($request->user(), $validated['format']);

        $results = DeckCsvImportService::import(
            $request->user(),
            $targetDeck,
            $validated['filename'],
            $source,
        );

        return Inertia::render('Deck/Import/CsvImportPage', [
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
     * Create a brand-new empty deck to receive the import.
     *
     * Minted directly via Eloquent rather than {@see DeckService::createDeck},
     * because that service enforces "format X requires a commander" —
     * for an import, the commanders (if any) arrive *after* the deck
     * is created, when the import service walks the CSV.
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
}
