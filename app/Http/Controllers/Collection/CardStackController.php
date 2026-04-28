<?php

namespace App\Http\Controllers\Collection;

use App\Enums\CardCondition;
use App\Enums\CardLanguage;
use App\Enums\Finish;
use App\Http\Controllers\Controller;
use App\Http\Requests\Collection\AddCardStackRequest;
use App\Http\Requests\Collection\DeleteCardStackRequest;
use App\Http\Requests\Collection\DestroySelectedCardStacksRequest;
use App\Http\Requests\Collection\EditCardStackRequest;
use App\Http\Requests\Collection\MassMoveCardStacksRequest;
use App\Http\Requests\Collection\MoveSelectedCardStacksRequest;
use App\Http\Requests\Collection\UpdateCardStackRequest;
use App\Models\CardStack;
use App\Models\Container;
use App\Models\DefaultCard;
use App\Services\CardStackService;
use App\Services\ContainerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CardStackController extends Controller
{
    /**
     * Display the "CardStack" page.
     *
     * Serves two use cases:
     * - Add cards to a specific container (when $container is provided via route)
     * - Add cards to the collection unsorted (when no container is specified)
     *
     * @param  Container|null  $container  Pre-selected container, or null for unsorted.
     */
    public function add(AddCardStackRequest $request, ?Container $container = null): Response
    {
        if ($container) {
            $container->load('defaultCard.set', 'defaultCard.artist');
        }

        $containers = $request->user()->containers()
            ->orderBy('sort_order')
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
            ]);

        return Inertia::render('Collection/CardStack/CardStackPage', [
            'container' => $container ? ContainerService::serializeContainer($container) : null,
            'containers' => $containers,
            'conditions' => array_column(CardCondition::cases(), 'value'),
            'finishes' => Finish::labels(),
            'languages' => array_column(CardLanguage::cases(), 'value'),
        ]);
    }

    /**
     * Validate and store a new card stack in the user's collection.
     *
     * Wraps validation in a precognitive block so the frontend can perform
     * real-time field validation without triggering the actual store.
     * The container_id is optional — when absent the card is added unsorted.
     */
    public function store(Request $request): RedirectResponse
    {
        precognitive(function () use ($request) {
            $request->validate([
                'default_card_id' => ['required', Rule::exists(DefaultCard::class, 'id')],
                'amount' => ['required', 'integer', 'min:1', 'max:65535'],
                'language' => ['required', Rule::enum(CardLanguage::class)],
                'container_id' => ['nullable', Rule::exists(Container::class, 'id')],
                'condition' => ['nullable', Rule::enum(CardCondition::class)],
                'finish' => ['required', Rule::in(Finish::labels())],
            ]);
        });

        $container = CardStackService::resolveOwnedContainer($request->user(), $request->container_id);

        $result = CardStackService::addToCollection($request->user(), $request->only([
            'default_card_id', 'amount', 'language', 'container_id', 'condition', 'finish',
        ]));

        $cardName = DefaultCard::find($request->default_card_id)->name;

        if ($result['merged']) {
            $message = __('collection.amount_changed', [
                'name' => $cardName,
                'amount' => $result['stack']->amount,
            ]);
        } elseif ($container) {
            $message = __('collection.card_added_to_container', [
                'name' => $cardName,
                'container' => $container->name,
            ]);
        } else {
            $message = __('collection.card_added', ['name' => $cardName]);
        }

        $request->session()->flash('message', $message);
        $request->session()->flash('type', 'success');

        if ($request->redirect === 'add_more') {
            if ($request->container_id) {
                return redirect(route('container.cards.add', $request->container_id));
            }

            return redirect(route('cards.add'));
        }

        if ($request->container_id) {
            return redirect(route('container.show', $request->container_id));
        }

        return redirect(route('collection'));
    }

    /**
     * Display the "edit card stack" page.
     *
     * Re-uses the CardStackPage component with the existing card stack data
     * pre-populated. The card is locked (not changeable) — only amount,
     * language, condition, finish and container can be edited.
     */
    public function edit(EditCardStackRequest $request, CardStack $cardStack): Response
    {
        $cardStack->load('defaultCard.set', 'defaultCard.artist', 'container');

        $containers = $request->user()->containers()
            ->orderBy('sort_order')
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
            ]);

        $defaultCard = $cardStack->defaultCard;

        return Inertia::render('Collection/CardStack/CardStackPage', [
            'container' => $cardStack->container
                ? ContainerService::serializeContainer($cardStack->container->load('defaultCard.set', 'defaultCard.artist'))
                : null,
            'containers' => $containers,
            'conditions' => array_column(CardCondition::cases(), 'value'),
            'finishes' => Finish::labels(),
            'languages' => array_column(CardLanguage::cases(), 'value'),
            'cardStack' => [
                'id' => $cardStack->id,
                'amount' => $cardStack->amount,
                'language' => $cardStack->language->value,
                'condition' => $cardStack->condition?->value ?? '',
                'finish' => $cardStack->finish?->label() ?? '',
                'container_id' => $cardStack->container_id,
                'default_card' => [
                    'id' => $defaultCard->id,
                    'name' => $defaultCard->name,
                    'card_image_0' => $defaultCard->card_image_0,
                    'card_image_1' => $defaultCard->card_image_1,
                    'artist' => $defaultCard->artist?->name,
                    'cn' => $defaultCard->collector_number,
                    'finishes' => Finish::labelsFromMask($defaultCard->finishes),
                    'set' => [
                        'name' => $defaultCard->set->name,
                        'code' => $defaultCard->set->code,
                        'path' => $defaultCard->set->path,
                    ],
                ],
            ],
        ]);
    }

    /**
     * Validate and update an existing card stack.
     *
     * The default_card_id cannot be changed — only amount, language,
     * condition, finish and container_id are editable. Ownership of the
     * stack itself is enforced by {@see UpdateCardStackRequest::authorize};
     * ownership of the target container_id form field is verified
     * separately via {@see CardStackService::resolveOwnedContainer}.
     */
    public function update(UpdateCardStackRequest $request, CardStack $cardStack): RedirectResponse
    {
        precognitive(function () use ($request) {
            $request->validate([
                'amount' => ['required', 'integer', 'min:1', 'max:65535'],
                'language' => ['required', Rule::enum(CardLanguage::class)],
                'container_id' => ['nullable', Rule::exists(Container::class, 'id')],
                'condition' => ['nullable', Rule::enum(CardCondition::class)],
                'finish' => ['required', Rule::in(Finish::labels())],
            ]);
        });

        CardStackService::resolveOwnedContainer($request->user(), $request->container_id);
        CardStackService::updateStack($request->user(), $cardStack, $request->only([
            'amount', 'language', 'condition', 'finish', 'container_id',
        ]));

        $cardName = $cardStack->defaultCard->name;
        $request->session()->flash('message', __('collection.card_updated', ['name' => $cardName]));
        $request->session()->flash('type', 'success');

        if ($cardStack->container_id) {
            return redirect(route('container.show', $cardStack->container_id));
        }

        return redirect(route('collection'));
    }

    /**
     * Move multiple card stacks to a different container.
     *
     * A null/empty container_id moves the stacks to "unsorted" (no container).
     * Stack ownership is enforced by
     * {@see MoveSelectedCardStacksRequest::authorize}; the service still
     * 404's on missing IDs (existence concern). Target container ownership
     * is verified by {@see CardStackService::resolveOwnedContainer}.
     */
    public function moveSelected(MoveSelectedCardStacksRequest $request): RedirectResponse
    {
        $request->validate([
            'card_stack_ids' => ['required', 'array', 'min:1'],
            'card_stack_ids.*' => ['required', 'uuid'],
            'container_id' => ['nullable', Rule::exists(Container::class, 'id')],
        ]);

        $targetContainer = CardStackService::resolveOwnedContainer(
            $request->user(),
            $request->container_id,
        );

        $stacks = CardStackService::moveToContainer(
            $request->user(),
            $request->card_stack_ids,
            $request->container_id ?: null,
        );

        $containerName = $targetContainer
            ? $targetContainer->name
            : __('collection.unsorted');

        $request->session()->flash('message', __('collection.cards_moved', [
            'number' => $stacks->count(),
            'container' => $containerName,
        ]));
        $request->session()->flash('type', 'success');

        if ($targetContainer) {
            return redirect(route('container.show', $targetContainer->id));
        }

        return redirect(route('collection'));
    }

    /**
     * Move all card stacks from one container to another (or to unsorted).
     *
     * Source container ownership is enforced by
     * {@see MassMoveCardStacksRequest::authorize}. Target container
     * ownership is verified by {@see CardStackService::resolveOwnedContainer}.
     * Redirects back to the previous page with a flash message.
     */
    public function massMove(MassMoveCardStacksRequest $request, Container $container): RedirectResponse
    {
        $request->validate([
            'container_id' => ['nullable', Rule::exists(Container::class, 'id')],
        ]);

        $targetContainer = CardStackService::resolveOwnedContainer(
            $request->user(),
            $request->container_id,
        );

        $count = CardStackService::massMove(
            $request->user(),
            $container,
            $request->container_id ?: null,
        );

        $targetName = $targetContainer
            ? $targetContainer->name
            : __('collection.unsorted');

        $request->session()->flash('message', __('collection.cards_mass_moved', [
            'number' => number_format($count, 0, ',', '.'),
            'source' => $container->name,
            'target' => $targetName,
        ]));
        $request->session()->flash('type', 'success');

        return redirect()->back();
    }

    /**
     * Delete an existing card stack from the user's collection.
     *
     * Ownership is enforced by {@see DeleteCardStackRequest::authorize}.
     * Redirects to the container page when the card stack belonged to one,
     * otherwise to the containers list.
     */
    public function destroy(DeleteCardStackRequest $request, CardStack $cardStack): RedirectResponse
    {
        $meta = CardStackService::deleteStack($request->user(), $cardStack);

        $request->session()->flash('message', __('collection.card_deleted', [
            'amount' => $meta['amount'],
            'name' => $meta['name'],
        ]));
        $request->session()->flash('type', 'success');

        if ($meta['container_id']) {
            return redirect(route('container.show', $meta['container_id']));
        }

        return redirect(route('collection'));
    }

    /**
     * Delete multiple selected card stacks from the user's collection.
     *
     * Stack ownership is enforced by
     * {@see DestroySelectedCardStacksRequest::authorize}; the service
     * still 404's on missing IDs (existence concern). Redirects back to
     * the container page when the stacks belonged to one, otherwise to
     * the containers list.
     */
    public function destroySelected(DestroySelectedCardStacksRequest $request): RedirectResponse
    {
        $request->validate([
            'card_stack_ids' => ['required', 'array', 'min:1'],
            'card_stack_ids.*' => ['required', 'uuid'],
        ]);

        $meta = CardStackService::deleteSelected(
            $request->user(),
            $request->card_stack_ids,
        );

        $request->session()->flash('message', __('collection.cards_deleted', [
            'stacks' => $meta['stacks'],
            'cards' => $meta['cards'],
        ]));
        $request->session()->flash('type', 'success');

        if ($meta['container_id']) {
            return redirect(route('container.show', $meta['container_id']));
        }

        return redirect(route('collection'));
    }
}
