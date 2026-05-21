<?php

namespace App\Services;

use App\Enums\Finish;
use App\Models\CardStack;
use App\Models\Container;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CardStackService
{
    /**
     * Verify that a container belongs to the given user.
     *
     * Aborts with 403 if ownership does not match.
     * Returns the loaded container, or null when no container_id is given.
     */
    public static function resolveOwnedContainer(User $user, ?string $containerId): ?Container
    {
        if (! $containerId) {
            return null;
        }

        $container = Container::findOrFail($containerId);
        abort_if($container->user_id !== $user->id, 403);

        return $container;
    }

    /**
     * Add cards to the user's collection, merging into an existing stack when
     * one already exists with the same identifying attributes.
     *
     * A stack is uniquely identified by user_id + default_card_id + language +
     * condition + finish + container_id (including null matches) + proxy.
     * Proxies are kept on their own stacks so a real-card pile and a proxy
     * pile of the same printing never collapse into one — they're different
     * physical objects with different value semantics.
     *
     * @param  array{default_card_id: string, amount: int, language: string, container_id?: string|null, condition?: string|null, finish: string, proxy?: bool}  $data  finish is a Finish label string.
     * @return array{stack: CardStack, merged: bool}
     */
    public static function addToCollection(User $user, array $data): array
    {
        $attributes = [
            'user_id' => $user->id,
            'default_card_id' => $data['default_card_id'],
            'language' => $data['language'],
            'condition' => $data['condition'] ?? null,
            'finish' => Finish::fromLabel($data['finish']),
            'container_id' => $data['container_id'] ?? null,
            'proxy' => (bool) ($data['proxy'] ?? false),
        ];

        $existing = CardStack::where($attributes)->first();

        if ($existing) {
            $existing->increment('amount', (int) $data['amount']);

            return ['stack' => $existing, 'merged' => true];
        }

        return [
            'stack' => CardStack::create([
                ...$attributes,
                'amount' => $data['amount'],
            ]),
            'merged' => false,
        ];
    }

    /**
     * Update a card stack's editable attributes.
     *
     * Ownership is checked at the controller boundary by
     * {@see UpdateCardStackRequest::authorize}.
     *
     * @param  array{amount: int, language: string, condition?: string|null, finish: string, container_id?: string|null, proxy?: bool}  $data
     */
    public static function updateStack(User $user, CardStack $cardStack, array $data): void
    {
        $cardStack->update([
            'amount' => $data['amount'],
            'language' => $data['language'],
            'condition' => $data['condition'] ?? null,
            'finish' => Finish::fromLabel($data['finish']),
            'container_id' => $data['container_id'] ?? null,
            'proxy' => (bool) ($data['proxy'] ?? false),
        ]);
    }

    /**
     * Delete a card stack from the user's collection.
     *
     * Ownership is checked at the controller boundary by
     * {@see DeleteCardStackRequest::authorize}.
     *
     * @return array{name: string, amount: int, container_id: string|null} Metadata for the flash message.
     */
    public static function deleteStack(User $user, CardStack $cardStack): array
    {
        $meta = [
            'name' => $cardStack->defaultCard->name,
            'amount' => $cardStack->amount,
            'container_id' => $cardStack->container_id,
        ];

        $cardStack->delete();

        return $meta;
    }

    /**
     * Delete multiple card stacks belonging to the given user.
     *
     * Ownership is checked at the controller boundary by
     * {@see DestroySelectedCardStacksRequest::authorize}. The 404 below
     * stays in the service because it's an existence check (some IDs
     * don't resolve), not an authorisation one.
     *
     * @param  string[]  $cardStackIds
     * @return array{stacks: int, cards: int, container_id: string|null} Metadata for the flash message.
     */
    public static function deleteSelected(User $user, array $cardStackIds): array
    {
        $stacks = CardStack::whereIn('id', $cardStackIds)->get();
        abort_if($stacks->count() !== count($cardStackIds), 404);

        $meta = [
            'stacks' => $stacks->count(),
            'cards' => $stacks->sum('amount'),
            'container_id' => $stacks->first()?->container_id,
        ];

        CardStack::whereIn('id', $cardStackIds)->delete();

        return $meta;
    }

    /**
     * Verify existence and move card stacks to a different container.
     *
     * Ownership is checked at the controller boundary by
     * {@see MoveSelectedCardStacksRequest::authorize}. The 404 below
     * stays in the service because it's an existence check (some IDs
     * don't resolve), not an authorisation one.
     *
     * @param  string[]  $cardStackIds
     * @return Collection<int, CardStack> The affected stacks.
     */
    public static function moveToContainer(User $user, array $cardStackIds, ?string $containerId): Collection
    {
        $stacks = CardStack::whereIn('id', $cardStackIds)->get();
        abort_if($stacks->count() !== count($cardStackIds), 404);

        CardStack::whereIn('id', $cardStackIds)
            ->update(['container_id' => $containerId]);

        return $stacks;
    }

    /**
     * Split a stack into two — decrement the source's `amount` and create
     * a new stack carrying the split-off amount, with otherwise identical
     * attributes (printing, container, language, condition, finish).
     *
     * Used by the deck finalize wizard when a stack's `amount` exceeds the
     * deck card's `quantity`: the wizard splits off only the cards being
     * claimed so the leftover copies stay free.
     *
     * Mirrors the pattern of {@see DeckCardController::split}.
     */
    public static function splitStack(CardStack $stack, int $amountToSplit): CardStack
    {
        return DB::transaction(function () use ($stack, $amountToSplit): CardStack {
            $stack->decrement('amount', $amountToSplit);

            return CardStack::create([
                'user_id' => $stack->user_id,
                'default_card_id' => $stack->default_card_id,
                'container_id' => $stack->container_id,
                'amount' => $amountToSplit,
                'condition' => $stack->condition,
                'finish' => $stack->finish,
                'language' => $stack->language,
            ]);
        });
    }

    /**
     * Move all card stacks from one container to another.
     *
     * Source ownership is checked at the controller boundary by
     * {@see MassMoveCardStacksRequest::authorize}.
     *
     * @return int Total number of cards (sum of amounts) moved.
     */
    public static function massMove(User $user, Container $sourceContainer, ?string $targetContainerId): int
    {
        $query = CardStack::where('user_id', $user->id)
            ->where('container_id', $sourceContainer->id);

        $totalCards = (int) $query->sum('amount');

        $query->update(['container_id' => $targetContainerId]);

        return $totalCards;
    }
}
