<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Sends the site's Content-Security-Policy on every response.
 *
 * Everything the app loads comes from its own origin: Vite's bundles, the
 * self-hosted fonts, card images and icons, and `fetch` to its own routes.
 * So the policy allows `'self'` and little else:
 *
 * - `script-src` adds a per-request nonce, which Vite's tags carry
 *   (`Vite::useCspNonce()`) and which dev tools that inject a script read
 *   from `Vite::cspNonce()` (Laravel Boost does). Production pages have no
 *   inline script; Inertia's page data is a `type="application/json"` block,
 *   which never executes.
 * - `style-src` allows inline styles: the error pages carry a `<style>` block
 *   and the 2FA code input adds one at runtime. A nonce here would switch
 *   `'unsafe-inline'` off, so this directive carries none.
 * - `img-src` adds `blob:` for the PNG the QR code page draws for download.
 *   Card images are local files; one whose download failed keeps Scryfall's
 *   URL and is blocked rather than loaded from a third party.
 * - `frame-ancestors 'self'` matches the server's `X-Frame-Options`.
 *
 * Report-only until `cantrip.csp.enforce` is set: violations then show in the
 * browser console without breaking anything.
 *
 * Skipped while Vite's dev server runs (`public/hot`), whose scripts and
 * websocket come from another origin, and for the exception page in debug
 * mode, which renders with inline scripts. The prerendered maintenance page
 * never reaches the app, so it has no policy.
 */
class ContentSecurityPolicy
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Vite::isRunningHot()) {
            return $next($request);
        }

        $nonce = Vite::useCspNonce();
        $response = $next($request);

        if ($this->isDebugExceptionPage($response)) {
            return $response;
        }

        $header = config('cantrip.csp.enforce') ? 'Content-Security-Policy' : 'Content-Security-Policy-Report-Only';
        $response->headers->set($header, self::policy($nonce));

        return $response;
    }

    /**
     * Laravel renders its debug page for an exception that is not an HTTP
     * exception while `app.debug` is on; a 404 or 403 gets the error view
     * either way, and so the policy.
     */
    private function isDebugExceptionPage(Response $response): bool
    {
        return config('app.debug')
            && isset($response->exception)
            && ! $response->exception instanceof HttpExceptionInterface;
    }

    /**
     * The policy, with the request's script nonce.
     */
    public static function policy(string $nonce): string
    {
        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' blob:",
            "font-src 'self'",
            "connect-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'self'",
        ]);
    }
}
