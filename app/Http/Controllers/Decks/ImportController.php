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
use Illuminate\Support\Facades\DB;
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
            'nameMax' => Deck::NAME_MAX,
            'results' => null,
        ]);
    }

    /**
     * Process the uploaded CSV.
     *
     * Both source paths (cantrip + Archidekt) mint a brand-new deck
     * with the chosen format, then hand off to
     * {@see DeckCsvImportService::import} which walks the CSV. The
     * deck's final name is resolved after import so commander-based
     * auto-naming has access to the just-inserted commander rows.
     *
     * @throws ValidationException When the upload doesn't exist on the
     *                             tmp disk or the CSV is unparseable /
     *                             missing required headers.
     */
    public function store(StoreDeckImportRequest $request): Response
    {
        $validated = $request->validated();
        $source = DeckImportSource::from($validated['source']);
        $userSuppliedName = isset($validated['deck_name'])
            ? trim($validated['deck_name'])
            : '';

        if (! Storage::disk('tmp')->exists($validated['filename'])) {
            throw ValidationException::withMessages([
                'filename' => [__('validation.custom.file.not_found')],
            ]);
        }

        // Wrap the deck creation, the CSV walk, and the final name update
        // in a single transaction so a header / parser failure can't leave
        // an empty placeholder deck behind. The inner transaction inside
        // DeckCsvImportService::import becomes a savepoint, and any
        // exception bubbling out of import() rolls everything back.
        [$targetDeck, $results] = DB::transaction(function () use ($request, $validated, $source, $userSuppliedName) {
            $deck = self::createBlankDeck($request->user(), $validated['format']);

            $results = DeckCsvImportService::import(
                $request->user(),
                $deck,
                $validated['filename'],
                $source,
            );

            $finalName = $userSuppliedName !== ''
                ? $userSuppliedName
                : self::resolveAutoName($deck);
            $deck->update(['name' => $finalName]);

            return [$deck, $results];
        });

        return Inertia::render('Deck/Import/CsvImportPage', [
            'maxUploadBytes' => (int) config('cantrip.csv_upload.max_bytes'),
            'allowedTypes' => config('cantrip.csv_upload.allowed_types'),
            'sources' => array_column(DeckImportSource::cases(), 'value'),
            'formats' => array_column(CardFormat::cases(), 'value'),
            'nameMax' => Deck::NAME_MAX,
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
     * is created, when the import service walks the CSV. The name is
     * a placeholder; {@see store} replaces it once the import is done.
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
     * Resolve the deck's auto-name when the user didn't supply one.
     *
     * For commander-like formats: the name of the first non-partner
     * commander on the deck (Atraxa for partners, the planeswalker for
     * Oathbreaker, etc.). Falls back to the timestamped default when
     * the import didn't yield a primary commander.
     *
     * For all other formats: "Imported Deck {locale-formatted now()}".
     */
    private static function resolveAutoName(Deck $deck): string
    {
        if ($deck->format->rules()->requiresCommander()) {
            $primaryCommanderName = DB::table('deck_cards')
                ->join('oracle_cards', 'oracle_cards.id', '=', 'deck_cards.oracle_card_id')
                ->where('deck_cards.deck_id', $deck->id)
                ->where('deck_cards.role', 'commander')
                ->orderBy('deck_cards.created_at')
                ->orderBy('oracle_cards.name')
                ->value('oracle_cards.name');
            if (is_string($primaryCommanderName) && $primaryCommanderName !== '') {
                return mb_substr($primaryCommanderName, 0, Deck::NAME_MAX);
            }
        }

        $timestamp = now()
            ->locale(app()->getLocale())
            ->isoFormat('L LT');

        return mb_substr(
            __('decks.import.default_deck_name_with_timestamp', ['timestamp' => $timestamp]),
            0,
            Deck::NAME_MAX,
        );
    }
}
