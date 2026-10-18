/******************************************************************************
 * "Send me back here": the page a card-stack change is made from passes its
 * own path and query as `return`, and the server redirects there once the
 * change is saved — so a collection search keeps its term, filters, sort
 * order and page. The server only accepts a path on this site
 * (`App\Traits\ReturnsToPage`).
 *****************************************************************************/

/** The current page's path and query — everything the server sees of it. */
export function currentPath(): string {
    return window.location.pathname + window.location.search;
}

/**
 * `url` with `return` set to `path` — the current page unless given —
 * keeping any query `url` already has.
 */
export function withReturn(url: string, path: string = currentPath()): string {
    const [base, query = ""] = url.split("?", 2);
    const params = new URLSearchParams(query);
    params.set("return", path);
    return `${base}?${params}`;
}
