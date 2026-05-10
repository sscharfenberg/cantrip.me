<?php

namespace App\Http\Middleware;

use App\Enums\Currency;
use App\Enums\DeckSort;
use App\Enums\DeckView;
use App\Enums\Locale;
use Illuminate\Http\Request;
use Inertia\Middleware;
use Laravel\Fortify\Features;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'csrfToken' => csrf_token(),
            'name' => config('app.name'),
            'version' => config('app.version'),
            'auth' => [
                'user' => fn () => $request->user()
                    ? [
                        ...$request->user()->only('id', 'name', 'email'),
                        'deck_view_default' => $request->user()->deck_view_default->value,
                        'deck_sort_default' => $request->user()->deck_sort_default->value,
                        'collection_integration_enabled' => $request->user()->collection_integration_enabled,
                    ]
                    : null,
            ],
            'supportedDeckViews' => array_column(DeckView::cases(), 'value'),
            'supportedDeckSorts' => array_column(DeckSort::cases(), 'value'),
            'locale' => app()->getLocale(),
            'supportedLocales' => array_column(Locale::cases(), 'value'),
            'currency' => fn () => $request->user()?->currency?->value
                ?? Locale::from(app()->getLocale())->defaultCurrency()->value,
            'supportedCurrencies' => array_column(Currency::cases(), 'value'),
            'features' => [
                'registration' => Features::enabled(Features::registration()),
                'resetPasswords' => Features::enabled(Features::resetPasswords()),
                'emailVerification' => Features::enabled(Features::emailVerification()),
            ],
            'flash' => fn () => [
                'message' => $request->session()->get('message'),
                'type' => $request->session()->get('type'),
                // Fresh per-response nonce so the Vue toast watcher always
                // sees a new reference, even when two consecutive submits
                // produce an identical message + type.
                'nonce' => $request->session()->has('message') ? uniqid('', true) : null,
            ],
        ];
    }
}
