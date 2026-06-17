<?php

namespace App\Http\Controllers\Decks;

use App\Http\Controllers\Controller;
use App\Http\Requests\Decks\DestroyDeckCategoryRequest;
use App\Http\Requests\Decks\StoreDeckCategoryRequest;
use App\Http\Requests\Decks\UpdateDeckCategoryRequest;
use App\Models\Deck;
use App\Models\DeckCard;
use App\Models\DeckCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class DeckCategoryController extends Controller
{
    /**
     * Create a new custom category for the given deck.
     *
     * Optionally assigns the deck card that was dragged onto the
     * "create group" drop target to the newly created category.
     */
    public function store(StoreDeckCategoryRequest $request, Deck $deck): RedirectResponse
    {
        $validated = $request->validated();

        $category = DeckCategory::create([
            'deck_id' => $deck->id,
            'name' => $validated['group_name'],
        ]);

        if (! empty($validated['card_id'])) {
            DeckCard::where('id', $validated['card_id'])
                ->where('deck_id', $deck->id)
                ->update(['category_id' => $category->id]);
        }

        $request->session()->flash('message', __('decks.category_created', [
            'group' => $category->name,
            'deck' => $deck->name,
        ]));
        $request->session()->flash('type', 'success');

        return redirect()->back();
    }

    /**
     * Rename a custom category.
     */
    public function update(UpdateDeckCategoryRequest $request, Deck $deck, DeckCategory $deckCategory): JsonResponse
    {
        $deckCategory->update([
            'name' => $request->validated()['name'],
        ]);

        return response()->json(['name' => $deckCategory->name], 200);
    }

    /**
     * Delete a custom category and return its cards to the default type groups.
     *
     * Nulls `category_id` on all cards that belonged to this category before
     * deleting the category itself, so cards revert to their natural type-based
     * grouping.
     */
    public function destroy(DestroyDeckCategoryRequest $request, Deck $deck, DeckCategory $deckCategory): JsonResponse
    {
        DeckCard::where('deck_id', $deck->id)
            ->where('category_id', $deckCategory->id)
            ->update(['category_id' => null]);

        $deckCategory->delete();

        return response()->json([], 200);
    }
}
