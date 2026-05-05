<?php

namespace App\Http\Middleware;

use App\Enums\Locale;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

class ConfigureLocale
{
    /**
     * @function parse locale from browser header
     */
    private function parseHttpLocale(Request $request): string
    {
        $list = explode(',', $request->server('HTTP_ACCEPT_LANGUAGE'));
        $locales = Collection::make($list)->map(function ($locale) {
            $parts = explode(';', $locale);
            $mapping['locale'] = trim($parts[0]);
            $mapping['locale'] = explode('-', $mapping['locale'])[0];
            if (isset($parts[1])) {
                $factorParts = explode('=', $parts[1]);
                $mapping['factor'] = $factorParts[1];
            } else {
                $mapping['factor'] = 1;
            }

            return $mapping;
        })->sortByDesc(function ($locale) {
            return $locale['factor'];
        });
        $browserLocale = $locales->first()['locale'];
        if (Locale::tryFrom($browserLocale) !== null) {
            return $browserLocale;
        } else {
            return config('app.locale');
        }
    }

    /**
     * @function handle the request
     */
    public function handle(Request $request, Closure $next): mixed
    {
        $user = Auth::user();
        $sessionLocale = session('locale');
        $browserLocale = $this->parseHttpLocale($request);
        if ($user) { // use locale from database
            app()->setLocale($user->locale->value);
        } elseif ($sessionLocale) { // use session locale
            app()->setLocale($sessionLocale);
        } else { // use browser locale
            session(['locale' => $browserLocale]);
            app()->setLocale($browserLocale);
        }

        return $next($request);
    }
}
