<?php

namespace App\Http\Controllers\Decks;

use App\Companions\CompanionRegistry;
use App\Enums\CardFormat;
use App\Enums\ContainerType;
use App\Enums\ContainerVisibility;
use App\Enums\DeckCardRole;
use App\Enums\DeckState;
use App\Enums\DeckZone;
use App\Enums\Finish;
use App\Enums\Locale;
use App\Enums\Scryfall\ScryfallRelatedComponent;
use App\Formats\FormatProfile;
use App\Http\Controllers\Controller;
use App\Http\Requests\Decks\AddAllToCollectionRequest;
use App\Http\Requests\Decks\BulkClaimRequest;
use App\Http\Requests\Decks\BuyUnclaimedCardsRequest;
use App\Http\Requests\Decks\DeckQrSvgRequest;
use App\Http\Requests\Decks\DeleteDeckRequest;
use App\Http\Requests\Decks\EditDeckRequest;
use App\Http\Requests\Decks\GenerateDeckQrRequest;
use App\Http\Requests\Decks\SetDeckCollectionModeRequest;
use App\Http\Requests\Decks\SetDeckHeroImageRequest;
use App\Http\Requests\Decks\SetDeckStateRequest;
use App\Http\Requests\Decks\SetDeckVisibilityRequest;
use App\Http\Requests\Decks\ShowDeckRequest;
use App\Http\Requests\Decks\UnclaimedCardsRequest;
use App\Models\CardStack;
use App\Models\Container;
use App\Models\Deck;
use App\Models\DeckCard;
use App\Models\DeckCategory;
use App\Models\DefaultCard;
use App\Models\DefaultCardRelation;
use App\Models\OracleCard;
use App\Services\BracketSuggestionService;
use App\Services\CommandZoneService;
use App\Services\DeckBulkAddCollectionService;
use App\Services\DeckCollectionModeService;
use App\Services\DeckCollectionStatusService;
use App\Services\DeckFinalizeService;
use App\Services\DeckService;
use App\Services\DeckValidator;
use App\Services\QrCodeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DecksController extends Controller
{
    /**
     * Display the user decks page (active decks only).
     *
     * Archived decks live on a separate `/decks/archived` page so they
     * don't clutter the main list. Also ships a `hasArchived` flag so the
     * page can decide whether to render the "Archived decks" link.
     */
    public function list(Request $request): Response
    {
        $userId = $request->user()->id;

        return Inertia::render('Decks/DecksPage', [
            'decksByFormat' => $this->groupedDecksForUser(
                $request,
                fn ($query) => $query->where('state', '!=', DeckState::Archived->value),
            ),
            'hasArchived' => Deck::query()
                ->where('user_id', $userId)
                ->where('state', DeckState::Archived->value)
                ->exists(),
        ]);
    }

    /**
     * Display the archived-decks page.
     *
     * Same format-folder layout as the main list, but scoped to archived
     * decks only — keeps the active list clean while still letting users
     * browse / restore archived decks with the familiar UI.
     */
    public function archived(Request $request): Response
    {
        return Inertia::render('Decks/ArchivedDecksPage', [
            'decksByFormat' => $this->groupedDecksForUser(
                $request,
                fn ($query) => $query->where('state', DeckState::Archived->value),
            ),
        ]);
    }

    /**
     * Build the format-grouped deck-row payload for the deck list pages.
     *
     * Shared by `list()` (active decks) and `archived()` (archived decks);
     * the caller passes a state filter so we don't duplicate the loading +
     * worth-aggregation pipeline.
     *
     * Post-consolidation, every card in the deck (mainboard, sideboard,
     * command zone, companion) lives in `deck_cards`. `card_count` is
     * shipped as a `{ main, companion, side }` object so the badge can
     * render "main + companion / side" instead of one opaque total;
     * `total_worth` aggregates the same rows (excluding maybeboard).
     *
     * @param  callable(Builder): Builder  $stateFilter
     * @return Collection<string, Collection<int, array<string, mixed>>>
     */
    private function groupedDecksForUser(Request $request, callable $stateFilter): Collection
    {
        // Three subqueries instead of one withSum: the badge wants the
        // counts split by zone (main + companion / side). Mainboard
        // bundles `main` and `command` together so the legal-deck total
        // (e.g. 100 = 99 + commander) shows on the badge. Maybeboard is
        // intentionally excluded — it's a scratch pile, not part of the
        // deck.
        $zoneSum = fn (array $zones) => DB::table('deck_cards')
            ->selectRaw('COALESCE(SUM(quantity), 0)')
            ->whereColumn('deck_cards.deck_id', 'decks.id')
            ->whereIn('deck_cards.zone', $zones);

        $query = Deck::query()
            ->where('user_id', $request->user()->id)
            ->addSelect([
                'main_count' => $zoneSum([DeckZone::Main->value, DeckZone::Command->value]),
                'companion_count' => $zoneSum([DeckZone::Companion->value]),
                'side_count' => $zoneSum([DeckZone::Side->value]),
                'last_card_update' => DB::table('deck_cards')
                    ->selectRaw('MAX(deck_cards.updated_at)')
                    ->whereColumn('deck_cards.deck_id', 'decks.id'),
                'has_companion_count' => DB::table('deck_cards')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('deck_cards.deck_id', 'decks.id')
                    ->where('deck_cards.role', DeckCardRole::Companion->value),
            ]);

        $decks = $stateFilter($query)
            ->get()
            ->each(function (Deck $deck) {
                $deck->card_count = [
                    'main' => (int) $deck->main_count,
                    'companion' => (int) $deck->companion_count,
                    'side' => (int) $deck->side_count,
                ];
                $deck->last_activity = max(array_filter([
                    $deck->updated_at,
                    $deck->last_card_update,
                ]));
            })
            ->sortByDesc('last_activity');

        // Per-deck total worth in the user's currency. One aggregate query
        // now that command-zone + companion live in deck_cards too.
        $deckIds = $decks->pluck('id')->all();
        $currency = $request->user()->currency
            ?? Locale::from(app()->getLocale())->defaultCurrency();
        $priceColumn = 'price_'.$currency->value;
        // Each deck_card's contribution drops by the count of slots
        // covered by proxy stacks (clamped at the deck_card's own
        // quantity so a flag-only / merge-anomaly stack can't push the
        // contribution below zero). Real-card claims, multi-claim, and
        // unclaimed slots all keep their full printing-price.
        $proxyClaims = DB::table('deck_card_card_stack')
            ->join('card_stacks', 'card_stacks.id', '=', 'deck_card_card_stack.card_stack_id')
            ->where('card_stacks.proxy', true)
            ->groupBy('deck_card_card_stack.deck_card_id')
            ->select('deck_card_card_stack.deck_card_id', DB::raw('SUM(card_stacks.amount) as proxy_amount'));

        $worthByDeck = $deckIds === [] ? collect() : DB::table('deck_cards')
            ->join('default_cards', 'default_cards.id', '=', 'deck_cards.default_card_id')
            ->leftJoinSub($proxyClaims, 'proxy_claims', 'proxy_claims.deck_card_id', '=', 'deck_cards.id')
            ->whereIn('deck_cards.deck_id', $deckIds)
            ->groupBy('deck_cards.deck_id')
            ->selectRaw(
                'deck_cards.deck_id, COALESCE(SUM('
                .'(CASE WHEN deck_cards.quantity > COALESCE(proxy_claims.proxy_amount, 0) '
                .'THEN deck_cards.quantity - COALESCE(proxy_claims.proxy_amount, 0) ELSE 0 END) '
                ."* default_cards.{$priceColumn}"
                .'), 0) AS total'
            )
            ->pluck('total', 'deck_id');

        return $decks
            ->groupBy(fn (Deck $deck) => $deck->format->value)
            ->sortKeys()
            ->map(fn ($formatDecks) => $formatDecks->map(fn (Deck $deck) => [
                'id' => $deck->id,
                'name' => $deck->name,
                'state' => $deck->state->value,
                'visibility' => $deck->visibility->value,
                'colors' => $deck->colors,
                'bracket' => $deck->bracket,
                'card_count' => $deck->card_count,
                'total_worth' => round((float) ($worthByDeck[$deck->id] ?? 0), 2),
                'last_activity' => $deck->last_activity,
                // Non-destructive flags: the delete-confirm modal uses these
                // to decide whether to prompt (deck has content worth losing)
                // or delete immediately (deck is effectively empty).
                'has_description' => $deck->description !== null && $deck->description !== '',
                'has_image' => $deck->default_card_id !== null,
                'has_companion' => (int) $deck->has_companion_count > 0,
            ])->values());
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
            $usesGameChangerList = $profile !== null && $profile->usesGameChangerList();

            $request->validate([
                'format' => ['required', 'string', Rule::enum(CardFormat::class)],
                'deck_name' => ['required', 'string', 'max:'.Deck::NAME_MAX],
                'deck_description' => ['nullable', 'string', 'max:'.Deck::DESCRIPTION_MAX],
                'bracket' => $usesGameChangerList
                    ? ['nullable', 'integer', 'between:1,5']
                    : ['prohibited'],
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
            'format', 'deck_name', 'deck_description', 'bracket',
            'commander_id', 'companion_id', 'signature_spell_id',
        ]));

        $request->session()->flash('message', __('decks.deck_created', ['name' => $deck->name]));
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
        // Post-consolidation, command-zone + companion live in `deck_cards`.
        // The eager-load chain therefore folds into a single `deckCards.*`
        // path; the prior split between `commanders.*` / `companion.*`
        // and the now-removed `companionDefaultCard` is gone.
        $deck->load([
            'defaultCard:id,name,art_crop,artist_id,set_id',
            'defaultCard.set:id,name,code,path',
            'defaultCard.artist:id,name',
            'deckCards.oracleCard',
            'deckCards.oracleCard.faces:oracle_card_id,face_index,type_line,mana_cost,oracle_text',
            'deckCards.oracleCard.legalities' => fn ($q) => $q->where('format', $deck->format->value),
            'deckCards.defaultCard:id,name,card_image_0,card_image_1,set_id,oracle_id',
            'deckCards.defaultCard.set:id,name,code',
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

        // `firstWhere` does loose equality, but PHP enum cases never
        // loose-compare equal to their backing string value — so we
        // compare against the enum case itself, not `->value`.
        // The full `$companion` array is built later, after the
        // collection-integration statuses have been resolved, so the
        // claim badge can be folded in alongside the rest of the shape.
        $companionRow = $deck->deckCards->firstWhere('role', DeckCardRole::Companion);

        // Collection-integration mode + per-card status. Owners only —
        // viewers never see collection state for someone else's deck. Per-row
        // status badges are mode-C-only by design: mode B decks (the user has
        // a collection but hasn't engaged with this deck via pivot) get a
        // count-based display in Phase 2.2 instead, and mode A is silent.
        $collectionMode = DeckCollectionStatusService::MODE_A;
        $collectionBadgeMode = DeckCollectionStatusService::MODE_A;
        $collectionStatuses = [];
        $collectionImplicitStatuses = [];
        $collectionModeContext = null;
        $containers = collect();
        $hasUnclaimedCards = false;
        if ($request->user()?->id === $deck->user_id) {
            $collectionMode = DeckCollectionStatusService::effectiveMode($request->user(), $deck);
            if ($collectionMode === DeckCollectionStatusService::MODE_C) {
                $collectionStatuses = DeckCollectionStatusService::statusForDeck($deck);
            } elseif ($collectionMode === DeckCollectionStatusService::MODE_B && $deck->container_id !== null) {
                // Mode B's per-row "in this deckbox / elsewhere" partition
                // needs `decks.container_id` as the anchor. Without one,
                // the deck still resolves to mode B (so the planned→built
                // wizard fires correctly — that's where the user picks a
                // container), but per-card badges stay silent until an
                // anchor exists.
                $collectionImplicitStatuses = DeckCollectionStatusService::implicitStatusForDeck($deck);
            }

            // Badge presents the user's explicitly chosen mode — no
            // longer downgraded to A when mode B has no container_id,
            // since the user actively picked B and the badge should
            // reflect their choice.
            $collectionBadgeMode = $collectionMode;

            // Context for the collection-mode badge popover: just the
            // master switch flag. When off, the parent hides the badge
            // entirely (no popover trigger surface).
            $collectionModeContext = [
                'master_switch_enabled' => (bool) $request->user()->collection_integration_enabled,
            ];

            // Container picker options for the owner-only "Add all to
            // collection" modal — same shape as the finalize wizard ships
            // (deckboxes pinned to the top via `is_deckbox`).
            $containers = $request->user()->containers()
                ->orderBy('sort_order')
                ->get(['id', 'name', 'type'])
                ->map(fn (Container $c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'type' => $c->type->value,
                    'is_deckbox' => $c->type === ContainerType::Deckbox,
                ])
                ->sortByDesc('is_deckbox')
                ->values();

            // Gate for the "Unclaimed cards" entry in DeckActionsMenu —
            // visible only when coverage is incomplete in modes B/C.
            if ($collectionMode === DeckCollectionStatusService::MODE_B
                || $collectionMode === DeckCollectionStatusService::MODE_C) {
                $hasUnclaimedCards = $this->collectUnclaimedRows($deck, $collectionMode)->isNotEmpty();
            }
        }

        // Build the `$companion` shape now that collection statuses are
        // available — the claim badge looks up by `deck_card_id`, so it
        // depends on `$collectionStatuses` / `$collectionImplicitStatuses`
        // having been populated above.
        $companion = null;
        if ($companionRow !== null && $companionRow->oracleCard !== null) {
            $companionOracle = $companionRow->oracleCard;
            $companionDefault = $companionRow->defaultCard
                ?? $rosterDefaults[$companionOracle->id]
                ?? null;

            $companion = [
                // `deck_card_id` is new: powers the unified hero-image
                // endpoint from the companion-actions menu.
                'deck_card_id' => $companionRow->id,
                'oracle_card_id' => $companionOracle->id,
                'name' => $companionOracle->name,
                'color_identity' => $companionOracle->color_identity,
                'produced_mana' => $companionOracle->produced_mana ? str_split($companionOracle->produced_mana) : null,
                'cmc' => $companionOracle->cmc,
                'type_line' => $companionOracle->faces->firstWhere('face_index', 0)?->type_line ?? '',
                'mana_cost' => $companionOracle->faces->sortBy('face_index')->pluck('mana_cost')->values()->all(),
                'collection_status' => $collectionStatuses[$companionRow->id] ?? null,
                'collection_implicit_status' => $collectionImplicitStatuses[$companionRow->id] ?? null,
                'default_card' => [
                    'id' => $companionDefault->id ?? null,
                    'card_image_0' => $companionDefault->card_image_0 ?? null,
                    'card_image_1' => $companionDefault->card_image_1 ?? null,
                ],
            ];
        }

        // Three-part card count for the badge ("main + companion / side").
        // The mainboard total includes the command zone (commanders /
        // partners / signature spell), since that's part of the legal
        // deck size — a Commander deck reads "100" for 99 mainboard + 1
        // commander. Companion and sideboard get their own slots; the
        // maybeboard is intentionally excluded (not part of the deck).
        $cardCount = [
            'main' => (int) $deck->deckCards
                ->whereIn('zone', [DeckZone::Main, DeckZone::Command])
                ->sum('quantity'),
            'companion' => (int) $deck->deckCards
                ->where('zone', DeckZone::Companion)
                ->sum('quantity'),
            'side' => (int) $deck->deckCards
                ->where('zone', DeckZone::Side)
                ->sum('quantity'),
        ];
        $cardCountTotal = $cardCount['main'] + $cardCount['companion'] + $cardCount['side'];
        $lastActivity = max(array_filter([
            $deck->updated_at?->toIso8601String(),
            $deck->deckCards->max('updated_at')?->toIso8601String(),
        ]));

        // Total deck worth in the request user's currency (or the locale
        // default for guests viewing a public deck). Aggregated against
        // `default_cards.price_{eur,usd}` rather than the eager-loaded
        // page payload so the existing `select(...)` lists stay narrow.
        $currency = $request->user()?->currency
            ?? Locale::from(app()->getLocale())->defaultCurrency();
        $priceColumn = 'price_'.$currency->value;
        // Single aggregate now that command-zone + companion live in
        // `deck_cards`. quantity is 1 for those rows so the sum is
        // identical to the prior `deckCards + commanders + companion`
        // composition. Proxy-claimed slots drop out of the sum (a
        // physical proxy isn't worth the printing's market price).
        $proxyClaims = DB::table('deck_card_card_stack')
            ->join('card_stacks', 'card_stacks.id', '=', 'deck_card_card_stack.card_stack_id')
            ->where('card_stacks.proxy', true)
            ->groupBy('deck_card_card_stack.deck_card_id')
            ->select('deck_card_card_stack.deck_card_id', DB::raw('SUM(card_stacks.amount) as proxy_amount'));

        $totalWorth = (float) (DB::table('deck_cards')
            ->join('default_cards', 'default_cards.id', '=', 'deck_cards.default_card_id')
            ->leftJoinSub($proxyClaims, 'proxy_claims', 'proxy_claims.deck_card_id', '=', 'deck_cards.id')
            ->where('deck_cards.deck_id', $deck->id)
            ->selectRaw(
                'COALESCE(SUM('
                .'(CASE WHEN deck_cards.quantity > COALESCE(proxy_claims.proxy_amount, 0) '
                .'THEN deck_cards.quantity - COALESCE(proxy_claims.proxy_amount, 0) ELSE 0 END) '
                ."* default_cards.{$priceColumn}"
                .'), 0) AS total'
            )
            ->value('total') ?? 0);
        $totalWorth = round($totalWorth, 2);

        // Command-zone deck_cards, ordered with the primary commander
        // first and the secondary slot (partner / signature spell)
        // second. `is_partner` retains the legacy meaning ("any
        // non-primary command-zone slot") so the frontend's existing
        // commander block doesn't have to learn the role taxonomy.
        $commanderRows = $deck->deckCards
            ->where('zone', DeckZone::Command)
            ->sortBy(fn (DeckCard $dc): int => $dc->role === DeckCardRole::Commander ? 0 : 1)
            ->values();
        $commanders = $commanderRows->map(function (DeckCard $dc) use ($illegalDeckCardIds, $collectionStatuses, $collectionImplicitStatuses) {
            $oracle = $dc->oracleCard;
            $printing = $dc->defaultCard;

            return [
                // `deck_card_id` is new: the unified hero-image endpoint
                // takes a deck_card id, so the frontend menu needs the
                // command-zone row's id alongside the oracle-level fields
                // it already consumed.
                'deck_card_id' => $dc->id,
                'oracle_card_id' => $oracle->id,
                'name' => $oracle->name,
                'color_identity' => $oracle->color_identity,
                'produced_mana' => $oracle->produced_mana ? str_split($oracle->produced_mana) : null,
                'cmc' => $oracle->cmc,
                'type_line' => $oracle->faces->firstWhere('face_index', 0)?->type_line ?? '',
                'mana_cost' => $oracle->faces->sortBy('face_index')->pluck('mana_cost')->values()->all(),
                'is_partner' => $dc->role !== DeckCardRole::Commander,
                // True when this command-zone row appears in any per-card
                // violation — today that's the banned-as-commander overlay
                // surfacing through the rule-0 escape hatch.
                'is_illegal' => isset($illegalDeckCardIds[$dc->id]),
                // Same per-row collection-integration statuses we ship
                // for mainboard rows — the finalize wizard, bulk-add and
                // per-card picker can all attach pivots to command-zone
                // and companion deck_cards post-consolidation, so the
                // badges have to track them.
                'collection_status' => $collectionStatuses[$dc->id] ?? null,
                'collection_implicit_status' => $collectionImplicitStatuses[$dc->id] ?? null,
                'default_card' => [
                    'id' => $printing?->id,
                    'card_image_0' => $printing?->card_image_0,
                    'card_image_1' => $printing?->card_image_1,
                ],
            ];
        })->values();

        // `cards` only carries the non-special rows (mainboard / sideboard
        // / maybeboard). Command-zone + companion get their own props so
        // the frontend's existing per-section rendering stays unchanged.
        $cards = $deck->deckCards
            ->whereNotIn('zone', [DeckZone::Command, DeckZone::Companion])
            ->map(fn (DeckCard $dc) => [
                'id' => $dc->id,
                'oracle_card_id' => $dc->oracle_card_id,
                'name' => $dc->oracleCard->name,
                'color_identity' => $dc->oracleCard->color_identity,
                'produced_mana' => $dc->oracleCard->produced_mana ? str_split($dc->oracleCard->produced_mana) : null,
                'fetch_pattern' => $dc->oracleCard->fetch_pattern,
                'cmc' => $dc->oracleCard->cmc,
                'type_line' => $dc->oracleCard->faces->firstWhere('face_index', 0)?->type_line ?? '',
                'mana_cost' => $dc->oracleCard->faces->sortBy('face_index')->pluck('mana_cost')->values()->all(),
                'is_basic_land' => in_array($dc->oracleCard->name, FormatProfile::BASIC_LANDS, true),
                'is_unlimited' => $dc->oracleCard->hasUnlimitedCopiesRule(),
                'is_illegal' => isset($illegalDeckCardIds[$dc->id]),
                'is_game_changer' => (bool) $dc->oracleCard->game_changer,
                'is_mld' => (bool) $dc->oracleCard->mld,
                'zone' => $dc->zone->value,
                'quantity' => $dc->quantity,
                'category_id' => $dc->category_id,
                'collection_status' => $collectionStatuses[$dc->id] ?? null,
                'collection_implicit_status' => $collectionImplicitStatuses[$dc->id] ?? null,
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

        // Hero image fallback: in commander-like formats, decks frequently
        // don't have an explicit `default_card_id` set — pick the primary
        // commander's printing so the banner shows the commander's art by
        // default rather than rendering a blank header. Loaded inline so
        // the result carries art_crop / set / artist, which the eager-load
        // chain on deckCards omits.
        $heroCard = $deck->defaultCard;
        if ($heroCard === null && $profile->requiresCommander()) {
            $primaryCommander = $commanderRows->firstWhere('role', DeckCardRole::Commander);
            if ($primaryCommander !== null && $primaryCommander->default_card_id !== null) {
                $heroCard = DefaultCard::query()
                    ->with(['set:id,name,code,path', 'artist:id,name'])
                    ->find(
                        $primaryCommander->default_card_id,
                        ['id', 'name', 'art_crop', 'artist_id', 'set_id']
                    );
            }
        }

        // Tokens (and other `all_parts` printing edges) for every card
        // in the deck — commanders, companion, deck cards. Captured at
        // the printing layer so a deck running MM2 Bitterblossom shows
        // the matching MM2 Faerie Rogue token, not a random reprint.
        // Deduped on the related printing id so the same token only
        // appears once even if multiple deck cards reference it.
        // Single source: every card in the deck (mainboard, sideboard,
        // command zone, companion) lives in `deck_cards` and has a
        // `default_card_id`, so one pluck covers them all.
        $sourceDefaultCardIds = $deck->deckCards
            ->pluck('default_card_id')
            ->filter()
            ->unique()
            ->values();

        // Group by token printing id; ship the source `default_card_id`s
        // alongside each token so the panel can render a "Needed for: …"
        // tooltip without an extra eager-load — names get resolved
        // client-side from props the deck page already holds.
        $tokens = DefaultCardRelation::query()
            ->where('component', ScryfallRelatedComponent::Token->value)
            ->whereIn('source_default_card_id', $sourceDefaultCardIds)
            ->with([
                'relatedCard:id,oracle_id,name,card_image_0,card_image_1,collector_number,finishes,artist_id,set_id',
                'relatedCard.oracle:id,color_identity',
                'relatedCard.set:id,name,code,path',
                'relatedCard.artist:id,name',
            ])
            ->get()
            ->groupBy(fn (DefaultCardRelation $rel) => $rel->relatedCard->id)
            ->map(function ($group) {
                $token = $group->first()->relatedCard;

                return [
                    'id' => $token->id,
                    'name' => $token->name,
                    'card_image_0' => $token->card_image_0,
                    'card_image_1' => $token->card_image_1,
                    'artist' => $token->artist?->name,
                    'cn' => $token->collector_number,
                    'finishes' => Finish::labelsFromMask($token->finishes),
                    'color_identity' => $token->oracle?->color_identity,
                    'set' => $token->set ? [
                        'name' => $token->set->name,
                        'code' => $token->set->code,
                        'path' => $token->set->path,
                    ] : null,
                    'source_default_card_ids' => $group
                        ->pluck('source_default_card_id')
                        ->unique()
                        ->values()
                        ->all(),
                ];
            })
            ->values();

        $response = Inertia::render('Deck/DeckPage', [
            'isOwner' => $request->user()?->id === $deck->user_id,
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
                'total_worth' => $totalWorth,
                'max_deck_size' => $profile->maxDeckSize(),
                'max_sideboard_size' => $profile->maxSideboardSize(),
                'max_copies' => $profile->maxCopies(),
                'is_singleton' => $profile->maxCopies() === 1,
                'enforces_color_identity' => $profile->enforcesColorIdentity(),
                'allows_companion' => $allowsCompanion,
                'banned_as_companion' => $profile->bannedAsCompanion(),
                'uses_game_changer_list' => $profile->usesGameChangerList(),
                'last_activity' => $lastActivity,
                'hero_card' => $heroCard ? [
                    'id' => $heroCard->id,
                    'name' => $heroCard->name,
                    'art_crop' => $heroCard->art_crop,
                    'artist' => $heroCard->artist?->name,
                    'set' => $heroCard->set ? [
                        'name' => $heroCard->set->name,
                        'code' => $heroCard->set->code,
                        'path' => $heroCard->set->path,
                    ] : null,
                ] : null,
            ],
            'commanders' => $commanders,
            'companion' => $companion,
            'companionRoster' => $companionRoster,
            'cards' => $cards,
            'categories' => $categories,
            'categoryNameMax' => DeckCategory::NAME_MAX,
            'violations' => $violations,
            'collectionMode' => $collectionMode,
            'collectionBadgeMode' => $collectionBadgeMode,
            'collectionModeContext' => $collectionModeContext,
            'hasUnclaimedCards' => $hasUnclaimedCards,
            'containers' => $containers,
            'tokens' => $tokens,
        ]);

        // Per-page OpenGraph metadata for public decks. Scrapers don't
        // execute JS and can only fetch publicly-reachable URLs, so we
        // only override the static defaults when visibility is Public.
        // Private decks fall back to the site-wide title/description in
        // app.blade.php. Prefer the user's own description when set —
        // OG previews allow ~200 chars, longer copy gets truncated by
        // the social platform anyway. Falls back to a synthesised
        // format/count line when the description is empty.
        if ($deck->visibility === ContainerVisibility::Public) {
            $userDescription = trim((string) $deck->description);
            if ($userDescription !== '') {
                $ogDescription = Str::limit(preg_replace('/\s+/', ' ', $userDescription), 200);
            } else {
                $format = ucfirst($deck->format->value);
                $ogDescription = "{$format} deck with {$cardCountTotal} cards on cantrip.me.";
            }
            $response->withViewData([
                'ogTitle' => "{$deck->name} – cantrip.me",
                'ogDescription' => $ogDescription,
            ]);
        }

        return $response;
    }

    /**
     * Render the BulkClaim page (route `/decks/{deck}/bulk-claim`).
     *
     * Owner-only AND gated to mode C via {@see BulkClaimRequest}. Each
     * unclaimed deck_card row is partitioned into one of three sections:
     *
     *  - §1 `exact` — the user owns at least one eligible stack of the
     *    same `default_card_id` as the deck_card.
     *  - §2 `alt`   — the user owns at least one eligible stack of a
     *    different printing of the same oracle card. Picking one swaps
     *    `deck_cards.default_card_id` to the picked printing.
     *  - §3 `missing` — the user owns no eligible stacks of any printing
     *    of this oracle card.
     *
     * "Eligible stack" = owned by the user + no pivot row in
     * `deck_card_card_stack` + not living in another deck's deckbox
     * container (cross-deck poaching guard).
     */
    public function bulkClaim(BulkClaimRequest $request, Deck $deck): Response
    {
        $deck->load([
            'deckCards.oracleCard:id,name',
            'deckCards.defaultCard:id,name,card_image_0,card_image_1,collector_number,set_id,oracle_id',
            'deckCards.defaultCard.set:id,name,code',
        ]);

        // Deck cards still needing a claim — already-claimed rows hide
        // here (they're surfaced by the per-card status badges instead).
        $unclaimedDeckCards = $deck->deckCards->filter(
            fn (DeckCard $dc) => $dc->cardStacks()->count() === 0
        )->values();

        $oracleIds = $unclaimedDeckCards->pluck('oracle_card_id')->unique()->values();
        if ($oracleIds->isEmpty()) {
            return Inertia::render('Deck/BulkClaim/BulkClaimCardsPage', [
                'deck' => [
                    'id' => $deck->id,
                    'name' => $deck->name,
                    'container_id' => $deck->container_id,
                ],
                'cards' => [],
                'containers' => $this->containerPickerOptions($request->user()),
            ]);
        }

        // Containers that act as another deck's deckbox — used to skip
        // stacks the other deck has visually allocated, even though the
        // pivot table doesn't bind them.
        $otherDecksDeckboxes = Deck::query()
            ->where('user_id', $request->user()->id)
            ->where('id', '!=', $deck->id)
            ->whereNotNull('container_id')
            ->whereHas('container', fn ($q) => $q->where('type', ContainerType::Deckbox->value))
            ->pluck('container_id');

        // All eligible stacks of any printing of any oracle card in the
        // deck — one query, partitioned in PHP.
        $stacksQuery = CardStack::query()
            ->where('user_id', $request->user()->id)
            ->whereNotIn('id', DB::table('deck_card_card_stack')->select('card_stack_id'))
            ->whereHas('defaultCard', fn ($q) => $q->whereIn('oracle_id', $oracleIds))
            ->with([
                'container:id,name,type',
                'defaultCard:id,collector_number,set_id,oracle_id,card_image_0',
                'defaultCard.set:id,code',
            ]);
        if ($otherDecksDeckboxes->isNotEmpty()) {
            $stacksQuery->whereNotIn('container_id', $otherDecksDeckboxes);
        }
        $stacks = $stacksQuery->get(['id', 'default_card_id', 'container_id', 'amount']);

        $stacksByOracle = $stacks->groupBy(fn (CardStack $s) => $s->defaultCard->oracle_id);

        $cards = $unclaimedDeckCards->map(function (DeckCard $dc) use ($stacksByOracle) {
            $matching = $stacksByOracle->get($dc->oracle_card_id, collect());

            $exactStacks = $matching
                ->filter(fn (CardStack $s) => $s->default_card_id === $dc->default_card_id)
                ->map(fn (CardStack $s) => $this->shapeExactStack($s))
                ->values();

            $altStacks = $matching
                ->reject(fn (CardStack $s) => $s->default_card_id === $dc->default_card_id)
                ->map(fn (CardStack $s) => $this->shapeAltStack($s))
                ->values();

            $section = match (true) {
                $exactStacks->isNotEmpty() => 'exact',
                $altStacks->isNotEmpty() => 'alt',
                default => 'missing',
            };

            return [
                'id' => $dc->id,
                'name' => $dc->oracleCard->name,
                'quantity' => $dc->quantity,
                'set_code' => $dc->defaultCard?->set?->code,
                'collector_number' => $dc->defaultCard?->collector_number,
                'card_image_0' => $dc->defaultCard?->card_image_0,
                'section' => $section,
                'exact_stacks' => $exactStacks,
                'alt_stacks' => $altStacks,
            ];
        })->values();

        return Inertia::render('Deck/BulkClaim/BulkClaimCardsPage', [
            'deck' => [
                'id' => $deck->id,
                'name' => $deck->name,
                'container_id' => $deck->container_id,
            ],
            'cards' => $cards,
            'containers' => $this->containerPickerOptions($request->user()),
        ]);
    }

    /**
     * Persist the BulkClaim submission. Always routes through
     * {@see DeckFinalizeService::persistAssignments}; an empty body is
     * still a valid submit (user just opened the page, picked nothing,
     * clicked "Claim cards" — no-op).
     *
     * State is NOT touched here — state transitions are decoupled from
     * claim activity since PR 1. After persisting, redirects to the
     * UnclaimedCardStacks page so the user can immediately see what's
     * still missing and either mark "just bought" or download the CSV.
     */
    public function storeBulkClaim(BulkClaimRequest $request, Deck $deck): RedirectResponse
    {
        $validated = $request->validated();
        /** @var array<string, array<int, string>> $assignments */
        $assignments = $validated['assignments'] ?? [];
        /** @var array<string, bool> $buyNew */
        $buyNew = $validated['buy_new'] ?? [];
        $containerId = $validated['container_id'] ?? null;

        if ($assignments !== [] || array_filter($buyNew) !== [] || $containerId !== null) {
            DeckFinalizeService::persistAssignments($deck, $assignments, $buyNew, $containerId);
        }

        $request->session()->flash('message', __('decks.bulk_claim.flash', ['name' => $deck->name]));
        $request->session()->flash('type', 'success');

        return redirect(route('decks.unclaimed', $deck->id));
    }

    /**
     * Render the UnclaimedCardStacks page (route
     * `/decks/{deck}/unclaimed`). Lists every deck slot that isn't
     * covered yet:
     *
     *  - Mode B: a slot is uncovered when the card isn't in the deck's
     *    `container_id` deckbox. The page is read-only; the user is
     *    expected to physically buy / move cards into the deckbox.
     *  - Mode C: a slot is uncovered when the deck_card's pivot
     *    coverage is less than its quantity. The page surfaces a per-
     *    row "I just bought this" checkbox plus a master "I bought all
     *    of these" toggle that submits {@see buyUnclaimed} to mint
     *    fresh stacks and claim them.
     *
     * Both modes get a "Download CSV of unclaimed stacks" link
     * pointing at {@see exportUnclaimed}.
     */
    public function unclaimed(UnclaimedCardsRequest $request, Deck $deck): Response
    {
        $mode = DeckCollectionStatusService::effectiveMode($request->user(), $deck);
        $cards = $this->collectUnclaimedRows($deck, $mode);

        return Inertia::render('Deck/Unclaimed/UnclaimedCardStacksPage', [
            'deck' => [
                'id' => $deck->id,
                'name' => $deck->name,
                'container_id' => $deck->container_id,
            ],
            'mode' => $mode,
            'cards' => $cards->values(),
        ]);
    }

    /**
     * Persist the mode-C "just bought these" submission from the
     * UnclaimedCardStacks page. For each ticked deck_card, mints a
     * fresh stack of the row's outstanding `unclaimed` count and
     * attaches it via the pivot. The new stack lands in the deck's
     * `container_id` (if set) so bought cards are physically in the
     * deckbox.
     */
    public function buyUnclaimed(BuyUnclaimedCardsRequest $request, Deck $deck): RedirectResponse
    {
        $boughtIds = (array) $request->validated('bought');
        $buyNew = [];
        foreach ($boughtIds as $deckCardId) {
            $buyNew[$deckCardId] = true;
        }

        DeckFinalizeService::persistAssignments($deck, [], $buyNew, $deck->container_id);

        $request->session()->flash('message', __('decks.bulk_claim.flash', ['name' => $deck->name]));
        $request->session()->flash('type', 'success');

        return redirect(route('decks.unclaimed', $deck->id));
    }

    /**
     * Stream a CSV of the deck's unclaimed slots (one row per uncovered
     * slot). Columns: name, edition, collector_number, qty, scryfall id,
     * zone, role. Triggered by the same-page "Download CSV" link on the
     * UnclaimedCardStacks page.
     */
    public function exportUnclaimed(UnclaimedCardsRequest $request, Deck $deck): StreamedResponse
    {
        $mode = DeckCollectionStatusService::effectiveMode($request->user(), $deck);
        $cards = $this->collectUnclaimedRows($deck, $mode);

        $filename = Str::slug($deck->name).'-unclaimed-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($cards): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['name', 'edition', 'collector_number', 'qty', 'scryfall_id', 'zone', 'role']);
            foreach ($cards as $card) {
                fputcsv($handle, [
                    $card['name'],
                    $card['set_code'],
                    $card['collector_number'],
                    $card['unclaimed'],
                    $card['default_card_id'],
                    $card['zone'],
                    $card['role'] ?? '',
                ]);
            }
            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Collect uncovered deck_card rows per mode. Mode B counts in-
     * deckbox coverage from `card_stacks.container_id`; mode C counts
     * pivot coverage from `deck_card_card_stack.amount` summed across
     * attached stacks.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function collectUnclaimedRows(Deck $deck, string $mode): Collection
    {
        $deck->load([
            'deckCards.oracleCard:id,name',
            'deckCards.defaultCard:id,collector_number,set_id,oracle_id',
            'deckCards.defaultCard.set:id,code',
            'deckCards.cardStacks:id,amount',
        ]);

        $inDeckboxByPrinting = [];
        if ($mode === DeckCollectionStatusService::MODE_B && $deck->container_id !== null) {
            $inDeckboxByPrinting = DB::table('card_stacks')
                ->where('user_id', $deck->user_id)
                ->where('container_id', $deck->container_id)
                ->whereIn('default_card_id', $deck->deckCards->pluck('default_card_id')->unique()->values())
                ->selectRaw('default_card_id, SUM(amount) as total')
                ->groupBy('default_card_id')
                ->pluck('total', 'default_card_id')
                ->all();
        }

        return $deck->deckCards
            ->map(function (DeckCard $dc) use ($mode, $inDeckboxByPrinting): ?array {
                $needed = (int) $dc->quantity;
                if ($needed <= 0) {
                    return null;
                }

                $covered = $mode === DeckCollectionStatusService::MODE_C
                    ? (int) $dc->cardStacks->sum('amount')
                    : (int) ($inDeckboxByPrinting[$dc->default_card_id] ?? 0);
                $unclaimed = max(0, $needed - $covered);
                if ($unclaimed === 0) {
                    return null;
                }

                return [
                    'id' => $dc->id,
                    'name' => $dc->oracleCard?->name ?? '',
                    'set_code' => $dc->defaultCard?->set?->code,
                    'collector_number' => $dc->defaultCard?->collector_number,
                    'default_card_id' => $dc->default_card_id,
                    'unclaimed' => $unclaimed,
                    'zone' => $dc->zone->value,
                    'role' => $dc->role?->value,
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * Container-picker payload reused by both the GET (initial render)
     * and the empty-deck early-return branch. Deckboxes pinned to the
     * top so the implicit-tracking anchor is the obvious pick.
     *
     * @return Collection<int, array{id: string, name: string, type: string, is_deckbox: bool}>
     */
    private function containerPickerOptions($user): Collection
    {
        return $user->containers()
            ->orderBy('sort_order')
            ->get(['id', 'name', 'type'])
            ->map(fn (Container $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'type' => $c->type->value,
                'is_deckbox' => $c->type === ContainerType::Deckbox,
            ])
            ->sortByDesc('is_deckbox')
            ->values();
    }

    /**
     * Shape an exact-printing stack option for the §1 dropdown.
     *
     * @return array{id: string, amount: int, container: array{id: string, name: string, type: string}|null}
     */
    private function shapeExactStack(CardStack $stack): array
    {
        return [
            'id' => $stack->id,
            'amount' => (int) $stack->amount,
            'container' => $stack->container ? [
                'id' => $stack->container->id,
                'name' => $stack->container->name,
                'type' => $stack->container->type->value,
            ] : null,
        ];
    }

    /**
     * Shape an alternate-printing stack option for the §2 dropdown.
     * Includes the printing's set + collector number + thumbnail so the
     * dropdown can render which printing the user owns.
     *
     * @return array{id: string, amount: int, container: array{id: string, name: string, type: string}|null, default_card_id: string, set_code: string|null, collector_number: string|null, card_image_0: string|null}
     */
    private function shapeAltStack(CardStack $stack): array
    {
        return [
            'id' => $stack->id,
            'amount' => (int) $stack->amount,
            'container' => $stack->container ? [
                'id' => $stack->container->id,
                'name' => $stack->container->name,
                'type' => $stack->container->type->value,
            ] : null,
            'default_card_id' => $stack->default_card_id,
            'set_code' => $stack->defaultCard?->set?->code,
            'collector_number' => $stack->defaultCard?->collector_number,
            'card_image_0' => $stack->defaultCard?->card_image_0,
        ];
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
            $usesGameChangerList = $profile->usesGameChangerList();

            $request->validate([
                'deck_name' => ['required', 'string', 'max:'.Deck::NAME_MAX],
                'deck_description' => ['nullable', 'string', 'max:'.Deck::DESCRIPTION_MAX],
                'deck_visibility' => ['required', Rule::enum(ContainerVisibility::class)],
                'bracket' => $usesGameChangerList
                    ? ['nullable', 'integer', 'between:1,5']
                    : ['prohibited'],
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
                // Hero image: must be a default_card_id attached to this
                // deck in any role — main + sideboard, command zone, or
                // companion — so users can't point the banner at a
                // printing the deck doesn't actually carry.
                'default_card_id' => [
                    'nullable',
                    'string',
                    function (string $attribute, mixed $value, \Closure $fail) use ($deck): void {
                        $valid = DeckCard::query()
                            ->where('deck_id', $deck->id)
                            ->where('default_card_id', $value)
                            ->exists()
                            || DB::table('commanders')
                                ->where('deck_id', $deck->id)
                                ->where('default_card_id', $value)
                                ->exists()
                            || $deck->companion_default_card_id === $value;

                        if (! $valid) {
                            $fail("The selected {$attribute} is invalid.");
                        }
                    },
                ],
                // Container picker (Phase 2.3). Nullable so the user can
                // unset the deck's deckbox; existence + ownership checked
                // inline so a foreign container id can't be smuggled in.
                'container_id' => [
                    'nullable',
                    'string',
                    Rule::exists('containers', 'id')->where('user_id', $request->user()->id),
                ],
            ]);
        });

        $bracket = $request->input('bracket');
        $deck->update([
            'name' => $request->input('deck_name'),
            'description' => $request->input('deck_description'),
            'visibility' => ContainerVisibility::from($request->input('deck_visibility')),
            'bracket' => $bracket === null || $bracket === '' ? null : (int) $bracket,
            'default_card_id' => $request->input('default_card_id') ?: null,
            'container_id' => $request->input('container_id') ?: null,
        ]);

        // Compare the requested command zone against the current pivot rows
        // and only re-set when something differs. setCommandZone() detaches
        // every existing commander and re-attaches with the newest default
        // card — calling it for a no-op edit would silently overwrite a
        // printing choice the user made elsewhere.
        $newCommanderId = $request->input('commander_id');
        $newCompanionId = $request->input('companion_id');
        $newSignatureSpellId = $request->input('signature_spell_id');

        // Compare against the current command-zone deck_card rows. Post-
        // consolidation, command-zone cards live in `deck_cards` with
        // `zone=command` and a discriminating `role`, so the diff reads
        // the role column instead of the old `commanders.is_partner`
        // pivot column.
        $deck->load('commanders');
        $profile = $deck->format->rules();
        $primaryRow = $deck->commanders->firstWhere('role', DeckCardRole::Commander);
        $secondaryRow = $deck->commanders->first(
            fn (DeckCard $row): bool => $row->role !== DeckCardRole::Commander
        );
        $currentCommander = $primaryRow?->oracle_card_id;
        $currentSpell = $profile->hasSignatureSpell() ? $secondaryRow?->oracle_card_id : null;
        $currentCompanion = $profile->hasSignatureSpell() ? null : $secondaryRow?->oracle_card_id;

        if (
            $newCommanderId !== $currentCommander
            || $newCompanionId !== $currentCompanion
            || $newSignatureSpellId !== $currentSpell
        ) {
            DeckService::setCommandZone($deck, $newCommanderId, $newCompanionId, $newSignatureSpellId);
        }

        $request->session()->flash('message', __('decks.deck_updated', ['name' => $deck->name]));
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
    public function edit(EditDeckRequest $request, Deck $deck): Response
    {
        // Post-consolidation, every card in the deck (mainboard / sideboard
        // / command zone / companion) lives in `deck_cards`. The eager-load
        // chain therefore folds into one path; the hero-image picker reads
        // its options from the same source.
        $deck->load([
            'deckCards:id,deck_id,default_card_id,oracle_card_id,zone,role',
            'deckCards.oracleCard.faces:oracle_card_id,face_index,type_line,mana_cost,oracle_text',
            'defaultCard:id,name,art_crop,artist_id,set_id',
            'defaultCard.set:id,name,code,path',
            'defaultCard.artist:id,name',
        ]);

        $profile = $deck->format->rules();

        // Shape the command zone + companion in the form the existing
        // CreateEditDeckPage expects. `commander` = primary command-zone
        // slot. `companion` here is the pre-existing variable name used
        // both for the partner-type companion (Commander format secondary
        // slot) and the Magic-keyword companion — the form picker treats
        // them as different fields, so we set whichever one applies.
        $commander = null;
        $companion = null;
        $signatureSpell = null;
        foreach ($deck->deckCards as $row) {
            if ($row->zone !== DeckZone::Command && $row->role !== DeckCardRole::Companion) {
                continue;
            }
            $oracle = $row->oracleCard;
            if ($oracle === null) {
                continue;
            }
            $shaped = CommandZoneService::mapCommanderCard($oracle);
            match ($row->role) {
                DeckCardRole::Commander => $commander = $shaped,
                DeckCardRole::SignatureSpell => $signatureSpell = $shaped,
                DeckCardRole::Partner, DeckCardRole::Companion => $companion = $shaped,
                default => null,
            };
        }

        // Card options for the deck-hero-image picker — every printing
        // attached to the deck in any role. Single source post-consolidation;
        // shaped as DefaultCardArtCrop on the frontend so ArtCropImage can
        // render the thumbnail directly.
        $shapeArtCrop = fn (DefaultCard $card) => [
            'id' => $card->id,
            'name' => $card->name,
            'art_crop' => $card->art_crop,
            'artist' => $card->artist?->name,
            'set' => $card->set ? [
                'name' => $card->set->name,
                'code' => $card->set->code,
                'path' => $card->set->path,
            ] : null,
        ];

        $optionIds = $deck->deckCards
            ->pluck('default_card_id')
            ->filter()
            ->unique()
            ->values();

        $cardOptions = DefaultCard::query()
            ->with(['set:id,name,code,path', 'artist:id,name'])
            ->whereIn('id', $optionIds)
            ->get(['id', 'name', 'art_crop', 'artist_id', 'set_id'])
            ->map($shapeArtCrop)
            ->values()
            ->all();

        $heroCard = $deck->defaultCard ? $shapeArtCrop($deck->defaultCard) : null;

        $capabilities = [];
        foreach (CardFormat::cases() as $format) {
            $capabilities[$format->value] = $format->rules()->toArray();
        }

        // User containers for the optional deckbox picker. Same shape as
        // the finalize wizard ships (`is_deckbox` flag + deckbox-sorting)
        // so the picker UX stays consistent across the two surfaces.
        $containers = $request->user()->containers()
            ->orderBy('sort_order')
            ->get(['id', 'name', 'type'])
            ->map(fn (Container $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'type' => $c->type->value,
                'is_deckbox' => $c->type === ContainerType::Deckbox,
            ])
            ->sortByDesc('is_deckbox')
            ->values();

        return Inertia::render('Deck/Create/CreateEditDeckPage', [
            'mode' => 'edit',
            'formats' => array_column(CardFormat::cases(), 'value'),
            'capabilities' => $capabilities,
            'nameMax' => Deck::NAME_MAX,
            'descriptionMax' => Deck::DESCRIPTION_MAX,
            'containers' => $containers,
            'existingDeck' => [
                'id' => $deck->id,
                'name' => $deck->name,
                'description' => $deck->description,
                'format' => $deck->format->value,
                'visibility' => $deck->visibility->value,
                'bracket' => $deck->bracket,
                // Auto-suggested *minimum* bracket based on the cards
                // currently in the deck (game changers + MLD). Null when
                // there's nothing to suggest. Surfaced as a hint, never a
                // forced value — see {@see BracketSuggestionService}.
                'suggestedBracket' => BracketSuggestionService::suggest($deck),
                'container_id' => $deck->container_id,
                'commander' => $commander,
                'companion' => $companion,
                'signatureSpell' => $signatureSpell,
                'cards' => $cardOptions,
                'heroCard' => $heroCard,
            ],
        ]);
    }

    /**
     * Toggle the deck's visibility between public and private.
     *
     * Triggered from the deck actions menu — a one-click flip without
     * going through the full edit-deck form. Owner check lives in
     * {@see SetDeckVisibilityRequest::authorize}.
     */
    public function setVisibility(SetDeckVisibilityRequest $request, Deck $deck): RedirectResponse
    {
        $visibility = ContainerVisibility::from($request->input('visibility'));

        $deck->update(['visibility' => $visibility]);

        $request->session()->flash('message', __(
            'decks.deck_visibility_'.$visibility->value,
            ['name' => $deck->name]
        ));
        $request->session()->flash('type', 'success');

        return redirect(route('decks.show', $deck));
    }

    /**
     * Set the deck's `collection_mode` to one of A / B / C, chosen by
     * the owner via the collection-mode modal's radio + submit.
     *
     * Transition semantics — including the C → B/A cascade-delete of
     * every `deck_card_card_stack` pivot row attached to this deck —
     * live in {@see DeckCollectionModeService::setMode}. No-op when the
     * requested mode equals the current one.
     */
    public function setCollectionMode(SetDeckCollectionModeRequest $request, Deck $deck): RedirectResponse
    {
        $mode = (string) $request->validated('mode');

        DeckCollectionModeService::setMode($deck, $mode);

        $request->session()->flash('message', __(
            'decks.collection_mode.set_flash',
            [
                'name' => $deck->name,
                'mode' => __('decks.collection_mode.modes.'.$mode),
            ],
        ));
        $request->session()->flash('type', 'success');

        return redirect(route('decks.show', $deck));
    }

    /**
     * Bulk-add every deck card without an existing pivot row to the user's
     * collection — owner-only "Add all cards to collection" action.
     *
     * For each deck_card with no claimed stacks, mints a fresh card_stack
     * (language en / nonfoil / no condition, optional container_id) and
     * attaches it via the pivot. Pins the deck to mode C as a side effect
     * whenever any pivot row is written. With `set_built` true, also flips
     * planned → built.
     */
    public function addAllToCollection(AddAllToCollectionRequest $request, Deck $deck): RedirectResponse
    {
        $validated = $request->validated();
        $containerId = $validated['container_id'] ?? null;
        $setBuilt = (bool) ($validated['set_built'] ?? false);

        DeckBulkAddCollectionService::addAll($deck, $containerId, $setBuilt);

        $request->session()->flash('message', __(
            'decks.add_all_to_collection.flash_success',
            ['name' => $deck->name],
        ));
        $request->session()->flash('type', 'success');

        return redirect(route('decks.show', $deck));
    }

    /**
     * Set the deck's lifecycle state to one of `planned` / `built` /
     * `archived`. Free flip — any state can transition to any other
     * via the deck-actions menu's "Set to X" entries.
     *
     * State changes are independent of collection-integration mode and
     * of pivot rows: claims survive across state transitions. Users
     * who want to clear claims do it via the collection-tracking badge
     * (C → B/A cascade-delete).
     */
    public function setState(SetDeckStateRequest $request, Deck $deck): RedirectResponse
    {
        $state = DeckState::from($request->input('state'));

        $deck->update(['state' => $state->value]);

        $request->session()->flash('message', __(
            'decks.deck_state_'.$state->value,
            ['name' => $deck->name],
        ));
        $request->session()->flash('type', 'success');

        return redirect(route('decks.show', $deck));
    }

    /**
     * Set the deck's hero/banner image to a given deck card's printing.
     *
     * Triggered from the per-card actions menu — a one-click way to swap
     * the banner art without going through the full edit-deck form. The
     * deck's `default_card_id` is updated to the deck card's chosen
     * printing; deck-card-belongs-to-deck and ownership checks live in
     * {@see SetDeckHeroImageRequest::authorize}.
     */
    public function setHeroImage(SetDeckHeroImageRequest $request, Deck $deck, DeckCard $deckCard): RedirectResponse
    {
        $deckCard->loadMissing('defaultCard:id,name');

        $deck->update(['default_card_id' => $deckCard->default_card_id]);

        $request->session()->flash('message', __('decks.deck_hero_changed', [
            'name' => $deck->name,
            'card' => $deckCard->defaultCard?->name ?? '',
        ]));
        $request->session()->flash('type', 'success');

        return redirect(route('decks.show', $deck));
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

        return to_route('decks')
            ->with('message', __('decks.deck_deleted', ['name' => $name]))
            ->with('type', 'success');
    }

    /**
     * Display the QR code generation page.
     *
     * When a deck is provided via route model binding, it is pre-selected
     * and ownership is verified by {@see GenerateDeckQrRequest}. Otherwise
     * the user can pick from all their decks.
     *
     * @param  Deck|null  $deck  Pre-selected deck, or null when accessed without an ID.
     */
    public function generateQr(GenerateDeckQrRequest $request, ?Deck $deck = null): Response
    {
        $decks = Deck::query()
            ->where('user_id', $request->user()->id)
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('Deck/DeckQrCode', [
            'deck' => $deck ? ['id' => $deck->id, 'name' => $deck->name] : null,
            'decks' => $decks,
        ]);
    }

    /**
     * Generate a QR code SVG for a deck's detail page.
     *
     * Returns the SVG markup as JSON so the frontend can embed it inline.
     */
    public function qrSvg(DeckQrSvgRequest $request, Deck $deck): JsonResponse
    {
        $url = route('decks.show', $deck);
        $svg = QrCodeService::generateSvg($url);

        return response()->json(['svg' => $svg]);
    }
}
