# Collection ↔ Deck Integration — staging smoke tests

Companion to `collection-deck-integration-plan.todo.md`. One section per shipped step. Run on `staging.cantrip.me` against real Scryfall data.

When a step needs DB state that the UI doesn't reach yet, fall back to `php artisan tinker` on staging — `App\Models\Container::where('user_id', '...')->get(['id', 'name'])->toArray()` for ids, then `App\Models\Deck::find('uuid')->update(['container_id' => 'container-uuid'])`.
