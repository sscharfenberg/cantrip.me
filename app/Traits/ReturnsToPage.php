<?php

namespace App\Traits;

/**
 * For form requests whose action should send the user back to the page
 * they acted from rather than to a fixed target. The page passes its own
 * path and query as `return` (`/collection?search=sol+ring&page=2`) — in
 * the query string, or as a form field — and the action redirects there,
 * so a search, its filters, sort order and page survive the round trip.
 *
 * Only a path on this site is taken. Anything that could lead off it — a
 * scheme, `//host`, a backslash (`/\host` reads as `//host` to browsers)
 * or control characters and spaces (stripped by browsers before they
 * resolve the URL) — makes {@see returnPath()} null, and the action falls
 * back to its default target. A bad `return` is ignored, never refused.
 */
trait ReturnsToPage
{
    /** The page to send the user back to, or null for the action's default. */
    public function returnPath(): ?string
    {
        $path = $this->input('return');

        if (! is_string($path) || $path === '' || strlen($path) > 2048) {
            return null;
        }
        if ($path[0] !== '/' || str_starts_with($path, '//') || str_contains($path, '\\')) {
            return null;
        }
        if (preg_match('/[\x00-\x20\x7F]/', $path) === 1) {
            return null;
        }

        return $path;
    }
}
