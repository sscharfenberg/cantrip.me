<?php

namespace App\Http\Controllers\Collection;

use App\Enums\ContainerType;
use App\Enums\ContainerVisibility;
use App\Enums\Locale;
use App\Http\Controllers\Controller;
use App\Http\Requests\Collection\ContainerQrSvgRequest;
use App\Http\Requests\Collection\DeleteContainerRequest;
use App\Http\Requests\Collection\EditContainerRequest;
use App\Http\Requests\Collection\GenerateContainerQrRequest;
use App\Http\Requests\Collection\PruneContainerRequest;
use App\Http\Requests\Collection\ShowContainerRequest;
use App\Http\Requests\Collection\UpdateContainerRequest;
use App\Models\Container;
use App\Models\DefaultCard;
use App\Services\CardSearchParser;
use App\Services\CardStackClaimService;
use App\Services\ContainerService;
use App\Services\DataTableService;
use App\Services\QrCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ContainerController extends Controller
{
    /**
     * Display the containers list.
     *
     * Renders the "Listcontainers" page
     * with the current request context.
     */
    public function list(Request $request): Response
    {
        $containers = $request->user()->containers()
            ->with('defaultCard.set', 'defaultCard.artist')
            ->withSum('cardStacks', 'amount')
            ->addSelect([
                'total_price' => ContainerService::totalPriceSubquery($request->user()->currency),
            ])
            ->orderBy('sort_order')
            ->get();

        return Inertia::render('Collection/Containers/ContainersPage', [
            'containerTypes' => array_column(ContainerType::cases(), 'value'),
            'containersMax' => Container::MAX_CONTAINERS,
            'containersAmount' => $containers->count(),
            'canCreateNewContainer' => $containers->count() < Container::MAX_CONTAINERS,
            'containers' => $containers->map(fn ($c) => ContainerService::serializeContainer($c)),
        ]);
    }

    /**
     * Display the "new container" page.
     *
     * Renders the "new container" form
     * with the current request context.
     */
    public function create(Request $request): Response
    {
        return Inertia::render('Collection/Container/ContainerFormPage', [
            'containerTypes' => array_column(ContainerType::cases(), 'value'),
            'nameMax' => Container::NAME_MAX,
            'descriptionMax' => Container::DESCRIPTION_MAX,
            'customTypeMax' => Container::CUSTOM_TYPE_MAX,
        ]);
    }

    /**
     * Validate and store a newly created container.
     *
     * Wraps validation in a precognitive block so the frontend can perform
     * real-time field validation without triggering the actual store.
     * `other_type` is only required when `type` is set to "other".
     */
    public function store(Request $request): RedirectResponse
    {
        precognitive(function () use ($request) {
            $request->validate([
                'container_name' => ['required', 'string', 'max:'.Container::NAME_MAX],
                'container_description' => ['max:'.Container::DESCRIPTION_MAX],
                'container_type' => ['required', 'string', Rule::enum(ContainerType::class)],
                'container_type_other' => ['required_if:container_type,other', 'string', 'max:'.Container::CUSTOM_TYPE_MAX],
                'container_image' => ['nullable', Rule::exists(DefaultCard::class, 'id')],
                'container_visibility' => ['nullable', 'string', Rule::enum(ContainerVisibility::class)],
            ]);
        });

        $container = ContainerService::createContainer($request->user(), $request->only([
            'container_name', 'container_description', 'container_type', 'container_type_other', 'container_image', 'container_visibility',
        ]));

        $request->session()->flash('message', __('collection.container_created', ['name' => $container->name]));
        $request->session()->flash('type', 'success');

        return redirect(route('containers'));
    }

    /**
     * Display the "edit container" page.
     *
     * Loads the container with its default card and set so the form can be
     * pre-populated.
     */
    public function edit(EditContainerRequest $request, Container $container): Response
    {
        $container->load('defaultCard.set', 'defaultCard.artist');

        return Inertia::render('Collection/Container/ContainerFormPage', [
            'containerTypes' => array_column(ContainerType::cases(), 'value'),
            'nameMax' => Container::NAME_MAX,
            'descriptionMax' => Container::DESCRIPTION_MAX,
            'customTypeMax' => Container::CUSTOM_TYPE_MAX,
            'container' => ContainerService::serializeContainer($container),
        ]);
    }

    /**
     * Validate and update an existing container.
     *
     * Validation is identical to store and wrapped in a precognitive block
     * for real-time field feedback. Ownership is enforced by
     * {@see UpdateContainerRequest::authorize}.
     */
    public function update(UpdateContainerRequest $request, Container $container): RedirectResponse
    {
        precognitive(function () use ($request) {
            $request->validate([
                'container_name' => ['required', 'string', 'max:'.Container::NAME_MAX],
                'container_description' => ['max:'.Container::DESCRIPTION_MAX],
                'container_type' => ['required', 'string', Rule::enum(ContainerType::class)],
                'container_type_other' => ['required_if:container_type,other', 'string', 'max:'.Container::CUSTOM_TYPE_MAX],
                'container_image' => ['nullable', Rule::exists(DefaultCard::class, 'id')],
                'container_visibility' => ['nullable', 'string', Rule::enum(ContainerVisibility::class)],
            ]);
        });

        ContainerService::updateContainer($request->user(), $container, $request->only([
            'container_name', 'container_description', 'container_type', 'container_type_other', 'container_image', 'container_visibility',
        ]));

        $request->session()->flash('message', __('collection.container_updated', ['name' => $container->name]));
        $request->session()->flash('type', 'success');

        return redirect(route('container.show', $container));
    }

    /**
     * Persist a new sort order for the user's containers.
     *
     * Expects `order`: an array of container UUIDs in the desired order.
     * Only IDs that belong to the authenticated user are accepted —
     * any unknown or foreign IDs are silently ignored.
     */
    public function reorder(Request $request): JsonResponse
    {
        $request->validate([
            'order' => ['required', 'array'],
            'order.*' => ['uuid'],
        ]);

        ContainerService::reorder($request->user(), $request->order);

        return response()->json(['ok' => true]);
    }

    /**
     * Delete a container.
     *
     * Ownership is enforced by {@see DeleteContainerRequest::authorize}.
     * Redirects back to the containers list with a success flash message.
     */
    public function destroy(DeleteContainerRequest $request, Container $container): RedirectResponse
    {
        $name = ContainerService::deleteContainer($request->user(), $container);

        $request->session()->flash('message', __('collection.container_deleted', ['name' => $name]));
        $request->session()->flash('type', 'success');

        return redirect(route('containers'));
    }

    /**
     * Delete all card stacks from a container.
     *
     * Ownership is enforced by {@see PruneContainerRequest::authorize}.
     * Redirects back to the container page with a success flash message.
     */
    public function prune(PruneContainerRequest $request, Container $container): RedirectResponse
    {
        $result = ContainerService::pruneContainer($request->user(), $container);

        $request->session()->flash('message', __('collection.container_pruned', [
            'count' => $result['count'],
            'name' => $result['name'],
        ]));
        $request->session()->flash('type', 'success');

        return redirect(route('container.show', $container));
    }

    /**
     * Display a single container's detail page.
     *
     * This route is publicly accessible (outside the auth middleware group).
     * - Owner: full page with management actions.
     * - Non-owner + public visibility: read-only view.
     * - Non-owner + private visibility: 404 (existence-hiding) — see
     *   {@see ShowContainerRequest::failedAuthorization}.
     */
    public function show(ShowContainerRequest $request, Container $container): Response
    {
        $isOwner = $request->user()?->id === $container->user_id;

        $currency = $request->user()?->currency
            ?? Locale::from(app()->getLocale())->defaultCurrency();

        $container->load('defaultCard.set', 'defaultCard.artist');
        $container->loadSum('cardStacks', 'amount');

        $container->total_price = ContainerService::totalPrice($container, $currency);

        $unitPriceSql = ContainerService::unitPriceSql($currency);

        $query = $container->cardStacks()
            ->join('default_cards', 'card_stacks.default_card_id', '=', 'default_cards.id')
            ->leftJoin('sets', 'default_cards.set_id', '=', 'sets.id')
            ->select([
                'card_stacks.*',
                'default_cards.name as card_name',
                'default_cards.collector_number',
                'default_cards.art_crop',
                'default_cards.card_image_0',
                'sets.name as set_name',
                'sets.code as set_code',
                'sets.path as set_path',
            ])
            ->selectRaw("COALESCE({$unitPriceSql}, 0) as unit_price")
            ->selectRaw("COALESCE(card_stacks.amount * ({$unitPriceSql}), 0) as stack_price")
            // Phase 2.5: same correlated claim-count subquery as
            // CollectionController::list — drives the new "Claimed"
            // column's sort.
            ->selectRaw('(SELECT COUNT(*) FROM deck_card_card_stack WHERE deck_card_card_stack.card_stack_id = card_stacks.id) as claim_count');

        $table = DataTableService::buildResponse(
            query: $query,
            request: $request,
            sortable: ['name', 'set_name', 'collector_number', 'amount', 'condition', 'language', 'finish', 'price', 'total_price', 'updated_at', 'claims'],
            sortColumnMap: [
                'name' => 'default_cards.name',
                'set_name' => 'sets.name',
                'collector_number' => 'default_cards.collector_number',
                'price' => 'unit_price',
                'total_price' => 'stack_price',
                'updated_at' => 'card_stacks.updated_at',
                'claims' => 'claim_count',
            ],
            defaultSort: 'updated_at',
            searchCallback: function ($q, $search) {
                $parsed = CardSearchParser::parse($search);

                if (! $parsed) {
                    return;
                }

                if ($parsed['set_code']) {
                    $q->where('sets.code', $parsed['set_code']);
                }

                if ($parsed['collector_number']) {
                    $q->where('default_cards.collector_number', $parsed['collector_number']);
                }

                foreach ($parsed['name_segments'] as $segment) {
                    $q->where(function ($q) use ($segment) {
                        $q->where('default_cards.name', 'like', "%{$segment}%")
                            ->orWhere('sets.name', 'like', "%{$segment}%");
                    });
                }
            },
            rowMapper: function ($stack) {
                return [
                    'id' => $stack->id,
                    'name' => $stack->card_name,
                    'set_name' => $stack->set_name,
                    'set_code' => $stack->set_code,
                    'set_path' => $stack->set_path,
                    'collector_number' => $stack->collector_number,
                    'amount' => $stack->amount,
                    'condition' => $stack->condition?->value,
                    'finish' => $stack->finish?->label(),
                    'language' => $stack->language?->value ?? 'en',
                    'art_crop' => $stack->art_crop,
                    'card_image_0' => $stack->card_image_0,
                    'price' => (float) ($stack->unit_price ?? 0),
                    'total_price' => (float) ($stack->stack_price ?? 0),
                    'proxy' => (bool) $stack->proxy,
                    'created_at' => $stack->created_at?->toIso8601String(),
                    'updated_at' => $stack->updated_at?->toIso8601String(),
                ];
            },
            defaultDirection: 'desc',
        );

        // Phase 2.5: hydrate per-row deck claims for the owner only.
        // Non-owners viewing a public container don't see the
        // "Claimed" column on the frontend anyway — and even if they
        // did, the deck names belong to the *owner's* private deck
        // list (some of which may not be public), so we shouldn't
        // ship them to a foreign viewer. Owners get the same
        // one-batched-query treatment as `CollectionController::list`.
        $claimsByStack = $isOwner
            ? CardStackClaimService::bulkClaimsForStacks(array_column($table['rows'], 'id'))
            : [];
        foreach ($table['rows'] as &$row) {
            $row['claims'] = $claimsByStack[$row['id']] ?? [];
        }
        unset($row);

        $props = [
            'container' => ContainerService::serializeContainer($container),
            'table' => $table,
            'isOwner' => $isOwner,
        ];

        if ($isOwner) {
            $props['containers'] = Container::query()
                ->where('user_id', $request->user()->id)
                ->where('id', '!=', $container->id)
                ->orderBy('name')
                ->get(['id', 'name']);
        }

        $response = Inertia::render('Collection/Container/ContainerPage', $props);

        // Per-page OpenGraph metadata for public containers. Scrapers
        // don't execute JS and can only fetch publicly-reachable URLs,
        // so the static defaults stay in place for private containers.
        // Description is English-only — OG scrapers have no locale
        // context, so one language is more consistent than picking the
        // (unrelated) viewer/server locale.
        if ($container->visibility === ContainerVisibility::Public) {
            $cardCount = (int) $container->card_stacks_sum_amount;
            $type = ucfirst($container->type->value);
            $response->withViewData([
                'ogTitle' => "{$container->name} – cantrip.me",
                'ogDescription' => "{$type} with {$cardCount} cards on cantrip.me.",
            ]);
        }

        return $response;
    }

    /**
     * Display the QR code generation page.
     *
     * When a container is provided via route model binding, it is pre-selected
     * and ownership is verified. Otherwise the user can pick from all their
     * containers.
     *
     * @param  Container|null  $container  Pre-selected container, or null when accessed without an ID.
     */
    public function generateQr(GenerateContainerQrRequest $request, ?Container $container = null): Response
    {
        $containers = Container::query()
            ->where('user_id', $request->user()->id)
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('Collection/Container/ContainerQrCode', [
            'container' => $container ? ContainerService::serializeContainer($container) : null,
            'containers' => $containers,
        ]);
    }

    /**
     * Generate a QR code SVG for a container's detail page.
     *
     * Returns the SVG markup as JSON so the frontend can embed it inline.
     */
    public function qrSvg(ContainerQrSvgRequest $request, Container $container): JsonResponse
    {
        $url = route('container.show', $container);
        $svg = QrCodeService::generateSvg($url);

        return response()->json(['svg' => $svg]);
    }
}
