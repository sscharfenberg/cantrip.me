<?php

namespace App\Http\Controllers\Collection;

use App\Enums\Scryfall\ScryfallRarity;
use App\Http\Controllers\Controller;
use App\Models\CardStack;
use App\Models\Container;
use App\Services\CardSearchParser;
use App\Services\CardStackClaimService;
use App\Services\ContainerService;
use App\Services\DataTableService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CollectionController extends Controller
{
    /**
     * Display the user collection page.
     *
     * Renders the main collection view with collection-wide statistics
     * and a searchable DataTable of all card stacks across all containers.
     */
    public function list(Request $request): Response
    {
        $user = $request->user();
        $currency = $user->currency;
        $unitPriceSql = ContainerService::unitPriceSql($currency);

        $statsQuery = CardStack::query()
            ->join('default_cards', 'card_stacks.default_card_id', '=', 'default_cards.id')
            ->where('card_stacks.user_id', $user->id);

        $stats = $statsQuery
            ->selectRaw('COALESCE(SUM(card_stacks.amount), 0) as total_cards')
            ->selectRaw('COUNT(*) as total_stacks')
            ->selectRaw("COALESCE(SUM(card_stacks.amount * ({$unitPriceSql})), 0) as total_price")
            ->selectRaw('COALESCE(SUM(CASE WHEN default_cards.rarity = ? THEN card_stacks.amount ELSE 0 END), 0) as commons', [ScryfallRarity::Common->value])
            ->selectRaw('COALESCE(SUM(CASE WHEN default_cards.rarity = ? THEN card_stacks.amount ELSE 0 END), 0) as uncommons', [ScryfallRarity::Uncommon->value])
            ->selectRaw('COALESCE(SUM(CASE WHEN default_cards.rarity = ? THEN card_stacks.amount ELSE 0 END), 0) as rares', [ScryfallRarity::Rare->value])
            ->selectRaw('COALESCE(SUM(CASE WHEN default_cards.rarity = ? THEN card_stacks.amount ELSE 0 END), 0) as mythics', [ScryfallRarity::Mythic->value])
            ->first();

        $containerCount = $user->containers()->count();

        $tableQuery = CardStack::query()
            ->join('default_cards', 'card_stacks.default_card_id', '=', 'default_cards.id')
            ->leftJoin('sets', 'default_cards.set_id', '=', 'sets.id')
            ->leftJoin('containers', 'card_stacks.container_id', '=', 'containers.id')
            ->where('card_stacks.user_id', $user->id)
            ->select([
                'card_stacks.*',
                'default_cards.name as card_name',
                'default_cards.collector_number',
                'default_cards.art_crop',
                'default_cards.card_image_0',
                'sets.name as set_name',
                'sets.code as set_code',
                'sets.path as set_path',
                'containers.name as container_name',
            ])
            ->selectRaw("COALESCE({$unitPriceSql}, 0) as unit_price")
            ->selectRaw("COALESCE(card_stacks.amount * ({$unitPriceSql}), 0) as stack_price")
            // Phase 2.5: a sortable claim-count expression. Correlated
            // subquery counts pivot rows for the current stack — 0 for
            // unclaimed, ≥ 1 for claimed. Sorting DESC clusters claimed
            // rows at the top (multi-claims first); ASC clusters
            // unclaimed at the top. The full claim payload is still
            // hydrated post-build via `CardStackClaimService`; this
            // column exists only to drive the sort.
            ->selectRaw('(SELECT COUNT(*) FROM deck_card_card_stack WHERE deck_card_card_stack.card_stack_id = card_stacks.id) as claim_count');

        $table = DataTableService::buildResponse(
            query: $tableQuery,
            request: $request,
            sortable: ['name', 'set_name', 'container_name', 'amount', 'condition', 'language', 'finish', 'price', 'total_price', 'updated_at', 'claims'],
            sortColumnMap: [
                'name' => 'default_cards.name',
                'set_name' => 'sets.name',
                'container_name' => 'containers.name',
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

                // DataTable search keeps raw-segment / raw-column matching so
                // the compound "card OR set OR container name" semantics stay
                // intact. The utf8mb4_unicode_ci collation already folds
                // accents and case for LIKE comparisons, and the table has
                // user-driven column sort, so relevance ranking isn't needed.
                foreach ($parsed['name_segments'] as $segment) {
                    $q->where(function ($q) use ($segment) {
                        $q->where('default_cards.name', 'like', "%{$segment}%")
                            ->orWhere('sets.name', 'like', "%{$segment}%")
                            ->orWhere('containers.name', 'like', "%{$segment}%");
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
                    'container_name' => $stack->container_name,
                    'container_id' => $stack->container_id,
                    'created_at' => $stack->created_at?->toIso8601String(),
                    'updated_at' => $stack->updated_at?->toIso8601String(),
                ];
            },
            defaultDirection: 'desc',
        );

        // Phase 2.5: hydrate per-row deck claims. One batched query per
        // visible page; rows the user doesn't see (other pages) skip the
        // lookup entirely. Stacks not pivoted anywhere default to `[]`.
        $claimsByStack = CardStackClaimService::bulkClaimsForStacks(array_column($table['rows'], 'id'));
        foreach ($table['rows'] as &$row) {
            $row['claims'] = $claimsByStack[$row['id']] ?? [];
        }
        unset($row);

        return Inertia::render('Collection/CollectionPage', [
            'stats' => [
                'totalCards' => (int) $stats->total_cards,
                'totalStacks' => (int) $stats->total_stacks,
                'totalPrice' => (float) $stats->total_price,
                'containers' => $containerCount,
                'commons' => (int) $stats->commons,
                'uncommons' => (int) $stats->uncommons,
                'rares' => (int) $stats->rares,
                'mythics' => (int) $stats->mythics,
            ],
            'table' => $table,
            'canCreateNewContainer' => $containerCount < Container::MAX_CONTAINERS,
        ]);
    }

    /**
     * Delete the entire collection of the authenticated user.
     *
     * Removes all card stacks and containers, then redirects back
     * with a warning flash message.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();

        CardStack::where('user_id', $user->id)->delete();
        Container::where('user_id', $user->id)->delete();

        $request->session()->flash('message', __('collection.collection_deleted'));
        $request->session()->flash('type', 'warning');

        return redirect(route('collection'));
    }
}
