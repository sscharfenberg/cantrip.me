<?php

namespace App\Services;

use App\Enums\Finish;
use App\Models\CardStack;
use App\Models\Container;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CardStackService
{
    /**
     * How many copies of each given printing sit in the user's collection,
     * per finish, keyed by `default_card_id`.
     *
     * Feeds the ownership badge on `CardFaceImage`'s panel. The global
     * collection-integration master switch is honoured here rather than at
     * each of the half-dozen payload producers that call this: with the
     * switch off — or for a guest — the map comes back empty, every payload
     * ships `owned => null`, and nothing renders.
     *
     * Printings the user owns none of are absent from the map rather than
     * present as 0, so a caller's `?? null` collapses "owns none" and
     * "nothing to show" into the same nullish value. The badge is a
     * positive signal only; it never announces a zero.
     *
     * Proxies count. A proxy is a card in a sleeve as far as "do I have
     * this to hand" goes, and every other collection count in the app
     * treats it the same way.
     *
     * Split by finish because a foil and a nonfoil of one printing are
     * different cards to own — a deck planned foil needs the foil. All three
     * finishes are always present, 0 where the user owns none of that one.
     *
     * @param  list<string>  $defaultCardIds
     * @return array<string, array{nonfoil: int, foil: int, etched: int}> Printing id → copies owned per finish.
     */
    public static function ownedAmountsFor(?User $user, array $defaultCardIds): array
    {
        if ($user === null || ! $user->collection_integration_enabled || $defaultCardIds === []) {
            return [];
        }

        $owned = [];
        CardStack::query()
            ->where('user_id', $user->id)
            ->whereIn('default_card_id', array_values(array_unique($defaultCardIds)))
            ->groupBy('default_card_id', 'finish')
            ->selectRaw('default_card_id, finish, SUM(amount) as total')
            ->toBase()
            ->get()
            ->each(function (object $row) use (&$owned): void {
                $finish = Finish::tryFrom((int) $row->finish);
                if ($finish === null) {
                    return;
                }
                $owned[$row->default_card_id] ??= ['nonfoil' => 0, 'foil' => 0, 'etched' => 0];
                $owned[$row->default_card_id][$finish->label()] += (int) $row->total;
            });

        return $owned;
    }

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
     * Change a stack's amount by `$delta` and return the new amount — the
     * +/- buttons in the collection and container tables.
     *
     * The row is re-read under a lock and the bounds are checked there, so
     * two overlapping requests add up instead of the later one overwriting
     * the earlier, and cannot together take the stack to 0. Taking the
     * last card is a delete, with its confirmation, so the result has to
     * stay within 1…{@see CardStack::MAX_AMOUNT}.
     *
     * Ownership is checked at the controller boundary by
     * {@see UpdateCardStackAmountRequest::authorize}.
     *
     * @throws ValidationException when the result would leave those bounds.
     */
    public static function changeAmount(CardStack $cardStack, int $delta): int
    {
        return DB::transaction(function () use ($cardStack, $delta): int {
            $locked = CardStack::query()->whereKey($cardStack->id)->lockForUpdate()->firstOrFail();
            $amount = $locked->amount + $delta;
            if ($amount < 1 || $amount > CardStack::MAX_AMOUNT) {
                throw ValidationException::withMessages([
                    'delta' => __('collection.errors.amount_out_of_range', ['max' => CardStack::MAX_AMOUNT]),
                ]);
            }
            $locked->update(['amount' => $amount]);

            return $amount;
        });
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
     * attributes (printing, container, language, condition, finish, proxy).
     * Dropping `proxy` here once turned the claimed copy of a proxy stack
     * into a real card, priced at full value on the deck.
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
                'proxy' => (bool) $stack->proxy,
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
