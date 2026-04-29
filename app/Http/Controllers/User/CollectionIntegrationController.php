<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Services\DeckCollectionStatusService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CollectionIntegrationController extends Controller
{
    /**
     * Update the authenticated user's collection-integration master switch.
     *
     * The flag is the user-level opt-out — when off, every deck for this
     * user resolves to mode A in {@see DeckCollectionStatusService},
     * silencing all collection-aware UI regardless of how many stacks the
     * user has. Default is `true`.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'collection_integration_enabled' => ['required', 'boolean'],
        ]);

        $user = $request->user();
        $user->collection_integration_enabled = $validated['collection_integration_enabled'];
        $user->save();

        $request->session()->flash('message', __('decks.collection_integration.flash.success'));
        $request->session()->flash('type', 'success');

        return back();
    }
}
