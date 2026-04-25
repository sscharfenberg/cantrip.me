<?php

namespace App\Http\Controllers\Decks;

use App\Companions\CompanionRegistry;
use App\Enums\CardFormat;
use App\Formats\FormatProfile;
use App\Http\Controllers\Controller;
use App\Http\Requests\Decks\DeleteDeckRequest;
use App\Http\Requests\Decks\ShowDeckRequest;
use App\Models\Deck;
use App\Models\DeckCard;
use App\Models\DeckCategory;
use App\Models\OracleCard;
use App\Services\CommandZoneService;
use App\Services\DeckService;
use App\Services\DeckValidator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DecksController extends Controller
{
    /**
     * Display the user decks page.
     *
     * Queries all decks for the authenticated user with card counts and
     * last-activity timestamps, then groups them by format (alphabetical).
     * Decks within each format are sorted by last activity descending.
     */
    public function list(Request $request): Response
    {
        $decks = Deck::query()
            ->where('user_id', $request->user()->id)
            ->withCount(['deckCards', 'commanders'])
            ->addSelect([
                'last_card_update' => DB::table('deck_cards')
                    ->selectRaw('MAX(deck_cards.updated_at)')
                    ->whereColumn('deck_cards.deck_id', 'decks.id'),
                'last_commander_update' => DB::table('commanders')
                    ->selectRaw('MAX(commanders.updated_at)')
                    ->whereColumn('commanders.deck_id', 'decks.id'),
            ])
            ->get()
            ->each(function (Deck $deck) {
                $deck->card_count = $deck->deck_cards_count + $deck->commanders_count;
                $deck->last_activity = max(array_filter([
                    $deck->updated_at,
                    $deck->last_card_update,
                    $deck->last_commander_update,
                ]));
            })
            ->sortByDesc('last_activity');

        $grouped = $decks
            ->groupBy(fn (Deck $deck) => $deck->format->value)
            ->sortKeys()
            ->map(fn ($formatDecks) => $formatDecks->map(fn (Deck $deck) => [
                'id' => $deck->id,
                'name' => $deck->name,
                'state' => $deck->state->value,
                'visibility' => $deck->visibility->value,
                'colors' => $deck->colors,
                'card_count' => (int) $deck->card_count,
                'last_activity' => $deck->last_activity,
                // Non-destructive flags: the delete-confirm modal uses these
                // to decide whether to prompt (deck has content worth losing)
                // or delete immediately (deck is effectively empty).
                'has_description' => $deck->description !== null && $deck->description !== '',
                'has_image' => $deck->default_card_id !== null,
                'has_companion' => $deck->companion_oracle_card_id !== null,
            ])->values());

        return Inertia::render('Decks/Decks', [
            'decksByFormat' => $grouped,
        ]);
    }

    /**
     * Validate and store a newly created deck.
     *
     * Wraps validation in a precognitive block so the frontend can perform
     * real-time field validation without triggering the actual store.
     * Command zone cards are validated structurally here (exists in oracle_cards),
     * domain validation (legal commander, valid pairing) is handled by DeckService.
     */
    public function store(Request $request): RedirectResponse
    {
        precognitive(function () use ($request) {
            // Resolve the format's profile once so "is a commander required?"
            // stays centralised in the format rules rather than a hardcoded
            // list of format names here. New commander-like formats added to
            // CardFormat automatically inherit the required-field behavior.
            $format = CardFormat::tryFrom((string) $request->input('format', ''));
            $profile = $format?->rules();
            $requiresCommander = $profile !== null && $profile->requiresCommander();
            $requiresSignatureSpell = $requiresCommander && $profile->hasSignatureSpell();

            $request->validate([
                'format' => ['required', 'string', Rule::enum(CardFormat::class)],
                'deck_name' => ['required', 'string', 'max:'.Deck::NAME_MAX],
                'deck_description' => ['nullable', 'string', 'max:'.Deck::DESCRIPTION_MAX],
                'commander_id' => [
                    $requiresCommander ? 'required' : 'nullable',
                    'string',
                    Rule::exists(OracleCard::class, 'id'),
                ],
                'companion_id' => ['nullable', 'string', Rule::exists(OracleCard::class, 'id')],
                'signature_spell_id' => [
                    $requiresSignatureSpell ? 'required' : 'nullable',
                    'string',
                    Rule::exists(OracleCard::class, 'id'),
                ],
            ]);
        });

        $deck = DeckService::createDeck($request->user(), $request->only([
            'format', 'deck_name', 'deck_description', 'commander_id', 'companion_id', 'signature_spell_id',
        ]));

        $request->session()->flash('message', __('auth.deck_created', ['name' => $deck->name]));
        $request->session()->flash('type', 'success');

        return redirect(route('decks.show', $deck));
    }

    /**
     * Display a single deck with all its data.
     *
     * Loads commanders (with default card image), deck cards (with default card),
     * and categories. Computes card count and last-activity timestamp the same way
     * the list does.
     */
    public function show(ShowDeckRequest $request, Deck $deck): Response
    {
        $deck->load([
            'defaultCard:id,card_image_0,card_image_1',
            'commanders.defaults' => fn ($q) => $q
                ->select('id', 'oracle_id', 'card_image_0', 'card_image_1'),
            'deckCards.oracleCard',
            'commanders.faces:oracle_card_id,face_index,mana_cost',
            'deckCards.oracleCard.faces:oracle_card_id,face_index,type_line,mana_cost,oracle_text',
            'deckCards.oracleCard.legalities' => fn ($q) => $q->where('format', $deck->format->value),
            'deckCards.defaultCard:id,name,card_image_0,card_image_1,set_id,oracle_id',
            'deckCards.defaultCard.set:id,name,code',
            'companion.faces:oracle_card_id,face_index,type_line,mana_cost,oracle_text',
            'companionDefaultCard:id,oracle_id,card_image_0,card_image_1',
            'categories',
        ]);

        $violations = DeckValidator::validate($deck);
        $illegalDeckCardIds = DeckValidator::illegalDeckCardIds($violations);

        $profile = $deck->format->rules();
        $allowsCompanion = $profile->allowsCompanion();
        $rosterOracles = $allowsCompanion ? CompanionRegistry::all() : collect();
        $rosterDefaults = $rosterOracles->isEmpty()
            ? collect()
            : DB::table('default_cards')
                ->join('sets', 'sets.id', '=', 'default_cards.set_id')
                ->whereIn('default_cards.oracle_id', $rosterOracles->pluck('id'))
                ->orderByDesc('sets.released_at')
                ->get(['default_cards.id', 'default_cards.oracle_id', 'default_cards.card_image_0', 'default_cards.card_image_1'])
                ->groupBy('oracle_id')
                ->map(fn ($group) => $group->first());

        $companionRoster = $rosterOracles->map(fn (OracleCard $oracle) => [
            'oracle_card_id' => $oracle->id,
            'name' => $oracle->name,
            'color_identity' => $oracle->color_identity,
            'cmc' => $oracle->cmc,
            'mana_cost' => $oracle->faces->sortBy('face_index')->pluck('mana_cost')->values()->all(),
            'default_card' => [
                'id' => $rosterDefaults[$oracle->id]->id ?? null,
                'card_image_0' => $rosterDefaults[$oracle->id]->card_image_0 ?? null,
                'card_image_1' => $rosterDefaults[$oracle->id]->card_image_1 ?? null,
            ],
        ])->values();

        $companion = null;
        if ($deck->companion !== null) {
            $companionOracle = $deck->companion;
            $companionDefault = $deck->companionDefaultCard
                ?? $rosterDefaults[$companionOracle->id]
                ?? DB::table('default_cards')
                    ->join('sets', 'sets.id', '=', 'default_cards.set_id')
                    ->where('default_cards.oracle_id', $companionOracle->id)
                    ->orderByDesc('sets.released_at')
                    ->first(['default_cards.id', 'default_cards.card_image_0', 'default_cards.card_image_1']);

            $companion = [
                'oracle_card_id' => $companionOracle->id,
                'name' => $companionOracle->name,
                'color_identity' => $companionOracle->color_identity,
                'cmc' => $companionOracle->cmc,
                'mana_cost' => $companionOracle->faces->sortBy('face_index')->pluck('mana_cost')->values()->all(),
                'default_card' => [
                    'id' => $companionDefault->id ?? null,
                    'card_image_0' => $companionDefault->card_image_0 ?? null,
                    'card_image_1' => $companionDefault->card_image_1 ?? null,
                ],
            ];
        }

        $cardCount = $deck->deckCards->count() + $deck->commanders->count();
        $lastActivity = max(array_filter([
            $deck->updated_at?->toIso8601String(),
            $deck->deckCards->max('updated_at')?->toIso8601String(),
            $deck->commanders->max(fn ($c) => $c->pivot->updated_at)?->toIso8601String(),
        ]));

        $commanders = $deck->commanders->map(fn (OracleCard $oracle) => [
            'oracle_card_id' => $oracle->id,
            'name' => $oracle->name,
            'color_identity' => $oracle->color_identity,
            'cmc' => $oracle->cmc,
            'mana_cost' => $oracle->faces->sortBy('face_index')->pluck('mana_cost')->values()->all(),
            'is_partner' => (bool) $oracle->pivot->is_partner,
            'default_card' => [
                'id' => $oracle->pivot->default_card_id,
                'card_image_0' => $oracle->defaults
                    ->firstWhere('id', $oracle->pivot->default_card_id)?->card_image_0,
                'card_image_1' => $oracle->defaults
                    ->firstWhere('id', $oracle->pivot->default_card_id)?->card_image_1,
            ],
        ])->values();

        $cards = $deck->deckCards->map(fn (DeckCard $dc) => [
            'id' => $dc->id,
            'oracle_card_id' => $dc->oracle_card_id,
            'name' => $dc->oracleCard->name,
            'color_identity' => $dc->oracleCard->color_identity,
            'cmc' => $dc->oracleCard->cmc,
            'type_line' => $dc->oracleCard->faces->firstWhere('face_index', 0)?->type_line ?? '',
            'mana_cost' => $dc->oracleCard->faces->sortBy('face_index')->pluck('mana_cost')->values()->all(),
            'is_basic_land' => in_array($dc->oracleCard->name, FormatProfile::BASIC_LANDS, true),
            'is_unlimited' => $dc->oracleCard->hasUnlimitedCopiesRule(),
            'is_illegal' => isset($illegalDeckCardIds[$dc->id]),
            'zone' => $dc->zone->value,
            'quantity' => $dc->quantity,
            'finish' => $dc->finish->value,
            'language' => $dc->language->value,
            'category_id' => $dc->category_id,
            'card_stack_id' => $dc->card_stack_id,
            'default_card' => [
                'id' => $dc->defaultCard?->id,
                'name' => $dc->defaultCard?->name,
                'card_image_0' => $dc->defaultCard?->card_image_0,
                'card_image_1' => $dc->defaultCard?->card_image_1,
                'set' => $dc->defaultCard?->set ? [
                    'name' => $dc->defaultCard->set->name,
                    'code' => $dc->defaultCard->set->code,
                ] : null,
            ],
        ])->values();

        $categories = $deck->categories->sortBy('name')->map(fn (DeckCategory $cat) => [
            'id' => $cat->id,
            'name' => $cat->name,
        ])->values();

        return Inertia::render('Deck/DeckPage', [
            'deck' => [
                'id' => $deck->id,
                'name' => $deck->name,
                'description' => $deck->description,
                'format' => $deck->format->value,
                'state' => $deck->state->value,
                'visibility' => $deck->visibility->value,
                'colors' => $deck->colors,
                'bracket' => $deck->bracket,
                'card_count' => $cardCount,
                'max_deck_size' => $profile->maxDeckSize(),
                'max_sideboard_size' => $profile->maxSideboardSize(),
                'max_copies' => $profile->maxCopies(),
                'is_singleton' => $profile->maxCopies() === 1,
                'enforces_color_identity' => $profile->enforcesColorIdentity(),
                'allows_companion' => $allowsCompanion,
                'banned_as_companion' => $profile->bannedAsCompanion(),
                'last_activity' => $lastActivity,
                'default_card_image' => $deck->defaultCard ? [
                    'card_image_0' => $deck->defaultCard->card_image_0,
                    'card_image_1' => $deck->defaultCard->card_image_1,
                ] : null,
            ],
            'commanders' => $commanders,
            'companion' => $companion,
            'companionRoster' => $companionRoster,
            'cards' => $cards,
            'categories' => $categories,
            'categoryNameMax' => DeckCategory::NAME_MAX,
            'violations' => $violations,
        ]);
    }

    /**
     * Display the "create deck" page.
     */
    public function create(Request $request): Response
    {
        $capabilities = [];
        foreach (CardFormat::cases() as $format) {
            $capabilities[$format->value] = $format->rules()->toArray();
        }

        return Inertia::render('Deck/Create/CreateEditDeckPage', [
            'mode' => 'create',
            'formats' => array_column(CardFormat::cases(), 'value'),
            'capabilities' => $capabilities,
            'nameMax' => Deck::NAME_MAX,
            'descriptionMax' => Deck::DESCRIPTION_MAX,
        ]);
    }

    /**
     * Persist edited deck settings.
     *
     * Format is locked in edit mode (the form's MonoSelect is disabled);
     * even if the request carries a different format we ignore it and
     * keep the deck's existing one. Name and description update directly.
     * The command zone is delegated to {@see DeckService::setCommandZone}
     * — but only when something actually changed there, so users who
     * edited only the name/description don't get their commander's
     * chosen printing reset to the newest one.
     */
    public function update(Request $request, Deck $deck): RedirectResponse
    {
        abort_unless($deck->user_id === $request->user()->id, 403);

        precognitive(function () use ($request, $deck): void {
            $profile = $deck->format->rules();
            $requiresCommander = $profile->requiresCommander();
            $requiresSignatureSpell = $requiresCommander && $profile->hasSignatureSpell();

            $request->validate([
                'deck_name' => ['required', 'string', 'max:'.Deck::NAME_MAX],
                'deck_description' => ['nullable', 'string', 'max:'.Deck::DESCRIPTION_MAX],
                'commander_id' => [
                    $requiresCommander ? 'required' : 'nullable',
                    'string',
                    Rule::exists(OracleCard::class, 'id'),
                ],
                'companion_id' => ['nullable', 'string', Rule::exists(OracleCard::class, 'id')],
                'signature_spell_id' => [
                    $requiresSignatureSpell ? 'required' : 'nullable',
                    'string',
                    Rule::exists(OracleCard::class, 'id'),
                ],
            ]);
        });

        $deck->update([
            'name' => $request->input('deck_name'),
            'description' => $request->input('deck_description'),
        ]);

        // Compare the requested command zone against the current pivot rows
        // and only re-set when something differs. setCommandZone() detaches
        // every existing commander and re-attaches with the newest default
        // card — calling it for a no-op edit would silently overwrite a
        // printing choice the user made elsewhere.
        $newCommanderId = $request->input('commander_id');
        $newCompanionId = $request->input('companion_id');
        $newSignatureSpellId = $request->input('signature_spell_id');

        $deck->load('commanders');
        $profile = $deck->format->rules();
        $currentCommander = $deck->commanders->firstWhere('pivot.is_partner', false)?->id;
        $currentPartner = $deck->commanders->firstWhere('pivot.is_partner', true)?->id;
        $currentCompanion = $profile->hasSignatureSpell() ? null : $currentPartner;
        $currentSpell = $profile->hasSignatureSpell() ? $currentPartner : null;

        if (
            $newCommanderId !== $currentCommander
            || $newCompanionId !== $currentCompanion
            || $newSignatureSpellId !== $currentSpell
        ) {
            DeckService::setCommandZone($deck, $newCommanderId, $newCompanionId, $newSignatureSpellId);
        }

        $request->session()->flash('message', __('auth.deck_updated', ['name' => $deck->name]));
        $request->session()->flash('type', 'success');

        return redirect(route('decks.show', $deck));
    }

    /**
     * Display the deck-settings edit page — same Vue component as the
     * create flow, just rendered in `mode: "edit"` with the deck's current
     * values pre-filled (name, description, format, command-zone cards).
     *
     * The format option in the form is rendered as disabled when in edit
     * mode (changing format on an existing deck would invalidate every
     * card and isn't supported yet); changing the command zone uses the
     * same picker modals as the create flow.
     *
     * Commanders are routed through {@see CommandZoneService::mapCommanderCard}
     * so the pre-fill payload matches the `CommanderResult` shape the rest
     * of the form already understands.
     */
    public function edit(ShowDeckRequest $request, Deck $deck): Response
    {
        $deck->load([
            'commanders.faces:oracle_card_id,face_index,type_line,mana_cost,oracle_text',
        ]);

        $profile = $deck->format->rules();

        $commander = null;
        $companion = null;
        $signatureSpell = null;
        foreach ($deck->commanders as $oracle) {
            $shaped = CommandZoneService::mapCommanderCard($oracle);
            if (! $oracle->pivot->is_partner) {
                $commander = $shaped;
            } elseif ($profile->hasSignatureSpell()) {
                $signatureSpell = $shaped;
            } else {
                $companion = $shaped;
            }
        }

        $capabilities = [];
        foreach (CardFormat::cases() as $format) {
            $capabilities[$format->value] = $format->rules()->toArray();
        }

        return Inertia::render('Deck/Create/CreateEditDeckPage', [
            'mode' => 'edit',
            'formats' => array_column(CardFormat::cases(), 'value'),
            'capabilities' => $capabilities,
            'nameMax' => Deck::NAME_MAX,
            'descriptionMax' => Deck::DESCRIPTION_MAX,
            'existingDeck' => [
                'id' => $deck->id,
                'name' => $deck->name,
                'description' => $deck->description,
                'format' => $deck->format->value,
                'commander' => $commander,
                'companion' => $companion,
                'signatureSpell' => $signatureSpell,
            ],
        ]);
    }

    /**
     * Delete the deck and all its dependent rows.
     *
     * Commanders, deck_cards and deck_categories cascade on FK delete, so a
     * single `$deck->delete()` wipes the deck and all its attached content.
     * Authorisation (owner check) is handled by {@see DeleteDeckRequest}.
     */
    public function destroy(DeleteDeckRequest $request, Deck $deck): RedirectResponse
    {
        $name = $deck->name;
        $deck->delete();

        $request->session()->flash('message', __('auth.deck_deleted', ['name' => $name]));
        $request->session()->flash('type', 'success');

        return redirect(route('decks'));
    }
}
