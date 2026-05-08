<?php

namespace App\Services;

use App\Enums\ContainerVisibility;
use App\Enums\Currency;
use App\Enums\Finish;
use App\Models\CardStack;
use App\Models\Container;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class ContainerService
{
    /**
     * Serialize a container model to the standard Inertia response shape.
     *
     * The `defaultCard.set` and `defaultCard.artist` relationships must be
     * loaded on the model before calling this method
     * (eager-load via `->with('defaultCard.set', 'defaultCard.artist')`).
     *
     * When the `card_stacks_sum_amount` aggregate is loaded on the model
     * (via `withSum('cardStacks', 'amount')`), it is included as `totalCards`.
     *
     * @return array{id: string, name: string, description: string|null, type: string, custom_type: string|null, sort: int, defaultCard: array|null, totalCards: int, totalPrice: float}
     */
    public static function serializeContainer(Container $container): array
    {
        return [
            'id' => $container->id,
            'name' => $container->name,
            'description' => $container->description,
            'type' => $container->type,
            'custom_type' => $container->custom_type,
            'visibility' => $container->visibility?->value ?? 'private',
            'sort' => $container->sort_order,
            'defaultCard' => $container->defaultCard ? [
                'id' => $container->defaultCard->id,
                'name' => $container->defaultCard->name,
                'art_crop' => $container->defaultCard->art_crop,
                'artist' => $container->defaultCard->artist?->name,
                'set' => [
                    'name' => $container->defaultCard->set?->name,
                    'code' => $container->defaultCard->set?->code,
                    'path' => $container->defaultCard->set?->path,
                ],
            ] : null,
            'totalCards' => (int) ($container->card_stacks_sum_amount ?? 0),
            'totalPrice' => (float) ($container->total_price ?? 0),
        ];
    }

    /**
     * Create a new container for the user.
     *
     * Aborts with 422 if the user has reached the container limit.
     * Automatically assigns the next sort_order.
     *
     * @param  array{container_name: string, container_description?: string|null, container_type: string, container_type_other?: string|null, container_image?: string|null, container_visibility?: string|null}  $data
     */
    public static function createContainer(User $user, array $data): Container
    {
        abort_if($user->containers()->count() >= Container::MAX_CONTAINERS, 422);

        $nextSort = ($user->containers()->max('sort_order') ?? 0) + 1;

        return Container::create([
            'user_id' => $user->id,
            'name' => $data['container_name'],
            'description' => $data['container_description'] ?? null,
            'type' => $data['container_type'],
            'custom_type' => $data['container_type'] === 'other' ? ($data['container_type_other'] ?? null) : null,
            'default_card_id' => $data['container_image'] ?: null,
            'visibility' => ($data['container_visibility'] ?? null) ?: ContainerVisibility::Private,
            'sort_order' => $nextSort,
        ]);
    }

    /**
     * Update an existing container's attributes.
     *
     * Ownership is checked at the controller boundary by
     * {@see UpdateContainerRequest::authorize}.
     *
     * @param  array{container_name: string, container_description?: string|null, container_type: string, container_type_other?: string|null, container_image?: string|null, container_visibility?: string|null}  $data
     */
    public static function updateContainer(User $user, Container $container, array $data): void
    {
        $container->update([
            'name' => $data['container_name'],
            'description' => $data['container_description'] ?? null,
            'type' => $data['container_type'],
            'custom_type' => $data['container_type'] === 'other' ? ($data['container_type_other'] ?? null) : null,
            'default_card_id' => $data['container_image'] ?: null,
            'visibility' => ($data['container_visibility'] ?? null) ?: ContainerVisibility::Private,
        ]);
    }

    /**
     * Persist a new sort order for the user's containers.
     *
     * Only IDs that belong to the user are updated — unknown or
     * foreign IDs are silently ignored.
     *
     * @param  string[]  $order  Container UUIDs in desired order.
     */
    public static function reorder(User $user, array $order): void
    {
        $userContainerIds = $user->containers()->pluck('id')->flip();

        foreach ($order as $index => $id) {
            if (! $userContainerIds->has($id)) {
                continue;
            }
            Container::where('id', $id)->update(['sort_order' => $index + 1]);
        }
    }

    /**
     * Delete a container.
     *
     * Ownership is checked at the controller boundary by
     * {@see DeleteContainerRequest::authorize}.
     *
     * @return string The container name (for the flash message).
     */
    public static function deleteContainer(User $user, Container $container): string
    {
        $name = $container->name;
        $container->delete();

        return $name;
    }

    /**
     * Delete all card stacks from a container without deleting the container itself.
     *
     * Ownership is checked at the controller boundary by
     * {@see PruneContainerRequest::authorize}.
     *
     * @return array{name: string, count: int}
     */
    public static function pruneContainer(User $user, Container $container): array
    {
        $count = $container->cardStacks()->sum('amount');
        $container->cardStacks()->delete();

        return ['name' => $container->name, 'count' => $count];
    }

    /**
     * SQL CASE expression that resolves the unit price for a card stack row
     * based on its finish and the given currency. Proxies short-circuit to
     * 0 — they're print-outs, not real cards, and we don't claim a market
     * value for them anywhere in the UI.
     */
    public static function unitPriceSql(Currency $currency): string
    {
        $c = $currency->value;
        $nonfoil = Finish::Nonfoil->value;
        $foil = Finish::Foil->value;
        $etched = Finish::Etched->value;

        return "CASE
            WHEN card_stacks.proxy = 1 THEN 0
            WHEN card_stacks.finish = {$nonfoil} THEN default_cards.price_{$c}
            WHEN card_stacks.finish = {$foil} THEN default_cards.price_{$c}_foil
            WHEN card_stacks.finish = {$etched} THEN default_cards.price_{$c}_etched
            ELSE 0 END";
    }

    /**
     * Raw SQL expression that sums (amount × price) for each card stack,
     * choosing the correct price column based on finish and currency.
     */
    private static function totalPriceSql(Currency $currency): string
    {
        return 'COALESCE(SUM(card_stacks.amount * '.self::unitPriceSql($currency).'), 0)';
    }

    /**
     * Build a correlated subquery that sums the total price for a container's
     * card stacks. Intended for use with `addSelect()` on a containers query.
     *
     * @return Builder<CardStack>
     */
    public static function totalPriceSubquery(Currency $currency): Builder
    {
        return CardStack::query()
            ->selectRaw(self::totalPriceSql($currency))
            ->join('default_cards', 'card_stacks.default_card_id', '=', 'default_cards.id')
            ->whereColumn('card_stacks.container_id', 'containers.id');
    }

    /**
     * Calculate the total price for a single container.
     */
    public static function totalPrice(Container $container, Currency $currency): float
    {
        return (float) CardStack::query()
            ->selectRaw(self::totalPriceSql($currency).' as total')
            ->join('default_cards', 'card_stacks.default_card_id', '=', 'default_cards.id')
            ->where('card_stacks.container_id', $container->id)
            ->value('total');
    }
}
