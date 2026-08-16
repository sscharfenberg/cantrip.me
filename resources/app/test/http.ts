import { vi } from "vitest";

/**
 * A `fetch` double for the composables that talk to the backend directly.
 *
 * Those composables use bare `fetch` rather than Inertia's router precisely so
 * they can inspect the status code — 422 for validation, 423 for an expired
 * password confirmation — so the double hands back real `Response` objects
 * (Node ships undici's) instead of a hand-rolled shape. Anything the code under
 * test reads off a response therefore behaves as it would in a browser.
 *
 * ```ts
 * const http = installFetchMock();
 * http.json("/login", { two_factor: true });
 *
 * await submit();
 *
 * expect(http.lastCall("/login")?.body).toEqual({ name: "…", password: "…" });
 * ```
 */

/** One recorded call, with the request body already parsed when it was JSON. */
export interface FetchCall {
    url: string;
    method: string;
    headers: Record<string, string>;
    /** Parsed JSON body, the raw string when it wasn't JSON, or undefined. */
    body: unknown;
    signal?: AbortSignal | null;
}

type Responder = (call: FetchCall) => Response | Promise<Response>;

export interface FetchMock {
    /** Every call made so far, in order. */
    calls: FetchCall[];
    /** The underlying spy, for `toHaveBeenCalledTimes` and friends. */
    spy: ReturnType<typeof vi.fn>;
    /** Respond to `pattern` with a JSON body. */
    json: (pattern: string, body: unknown, status?: number, method?: string) => FetchMock;
    /** Respond to `pattern` with a status and no body. */
    status: (pattern: string, status: number, method?: string) => FetchMock;
    /** Respond to `pattern` with an unparseable body. */
    malformed: (pattern: string, status?: number) => FetchMock;
    /** Reject the call, as a dropped connection would. */
    reject: (pattern: string, error?: Error) => FetchMock;
    /** Never settle until the request's own AbortSignal fires. */
    hang: (pattern: string) => FetchMock;
    /** Full control: build the `Response` from the call itself. */
    on: (pattern: string, responder: Responder, method?: string) => FetchMock;
    /** The most recent call, optionally filtered to `pattern`. */
    lastCall: (pattern?: string) => FetchCall | undefined;
    /** Calls matching `pattern`. */
    callsTo: (pattern: string) => FetchCall[];
    /** Patterns registered but never hit — usually a typo in the stubbed URL. */
    unusedRoutes: () => string[];
}

/**
 * Whether a request URL is the endpoint `pattern` names.
 *
 * Deliberately not a substring test: `/api/decks/d/cards/c` is a prefix of
 * `/api/decks/d/cards/c/quantity`, so `includes` would let a stub for the
 * delete endpoint answer the quantity call as well. A query string is the one
 * thing allowed to follow.
 */
const matches = (url: string, pattern: string): boolean => url === pattern || url.startsWith(`${pattern}?`);

/** Parse a request body back into whatever the caller passed to JSON.stringify. */
const parseBody = (body: BodyInit | null | undefined): unknown => {
    if (typeof body !== "string") return body ?? undefined;
    try {
        return JSON.parse(body);
    } catch {
        return body;
    }
};

/** Normalise the several shapes `HeadersInit` can take into a plain object. */
const plainHeaders = (headers: HeadersInit | undefined): Record<string, string> => {
    if (!headers) return {};
    if (headers instanceof Headers) return Object.fromEntries(headers.entries());
    if (Array.isArray(headers)) return Object.fromEntries(headers);
    return { ...headers };
};

/**
 * Replace the global `fetch` for the current test.
 *
 * Call it from `beforeEach`, never `beforeAll`: `unstubGlobals` in
 * `vitest.config.ts` restores the real `fetch` after every test, so a stub
 * installed once would only cover the first one.
 *
 * An unregistered URL resolves to a 200 with an empty JSON object, so a spec
 * only has to stub the request it is actually about. The cost is that a typo
 * in a stubbed URL is not itself an error — the stub simply never fires and
 * the call falls through to the empty default. {@link FetchMock.unusedRoutes}
 * exists to catch exactly that.
 */
export function installFetchMock(): FetchMock {
    const calls: FetchCall[] = [];
    const routes: { pattern: string; method?: string; responder: Responder; hits: number }[] = [];

    const spy = vi.fn(async (input: RequestInfo | URL, init?: RequestInit): Promise<Response> => {
        const call: FetchCall = {
            url: String(input),
            method: init?.method ?? "GET",
            headers: plainHeaders(init?.headers),
            body: parseBody(init?.body),
            signal: init?.signal
        };
        calls.push(call);

        // Searched newest-first, so re-registering a pattern overrides the
        // earlier one. That is what lets a test set up a happy path in a helper
        // and then replace one leg of it for a single case. (Hand-rolled rather
        // than `findLast`, which needs a newer `lib` than tsconfig targets.)
        const route = [...routes]
            .reverse()
            .find(r => matches(call.url, r.pattern) && (r.method === undefined || r.method === call.method));
        if (!route) {
            return new Response("{}", { status: 200, headers: { "Content-Type": "application/json" } });
        }

        route.hits += 1;

        return route.responder(call);
    });

    vi.stubGlobal("fetch", spy);

    const mock: FetchMock = {
        calls,
        spy,

        on(pattern, responder, method) {
            routes.push({ pattern, method, responder, hits: 0 });
            return mock;
        },

        json(pattern, body, status = 200, method) {
            return mock.on(
                pattern,
                () => new Response(JSON.stringify(body), { status, headers: { "Content-Type": "application/json" } }),
                method
            );
        },

        status(pattern, status, method) {
            return mock.on(pattern, () => new Response(null, { status }), method);
        },

        malformed(pattern, status = 200) {
            // A Fortify redirect to an HTML page, or a truncated body: `.json()`
            // rejects, and several composables lean on `.catch(() => ({}))`.
            return mock.on(pattern, () => new Response("<!doctype html>", { status }));
        },

        reject(pattern, error = new TypeError("Failed to fetch")) {
            return mock.on(pattern, () => Promise.reject(error));
        },

        hang(pattern) {
            const aborted = (): DOMException => new DOMException("The operation was aborted.", "AbortError");

            return mock.on(pattern, call => {
                // A signal that was already aborted rejects straight away, as
                // real `fetch` does — otherwise this promise would never settle
                // and the failure would surface as a test timeout.
                if (call.signal?.aborted) return Promise.reject(aborted());

                return new Promise<Response>((_resolve, rejectPromise) => {
                    call.signal?.addEventListener("abort", () => rejectPromise(aborted()));
                });
            });
        },

        lastCall(pattern) {
            const matching = pattern === undefined ? calls : mock.callsTo(pattern);
            return matching[matching.length - 1];
        },

        callsTo(pattern) {
            return calls.filter(call => matches(call.url, pattern));
        },

        unusedRoutes() {
            return routes.filter(route => route.hits === 0).map(route => route.pattern);
        }
    };

    return mock;
}
