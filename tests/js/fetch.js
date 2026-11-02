// A controllable fetch: every call is recorded, answered by `respond(url)` (default: an empty
// SearchResponse), and honours AbortSignal like the real one.
export function mockFetch(respond = () => response()) {
    const calls = [];
    globalThis.fetch = (url, { signal } = {}) => {
        calls.push(url);
        return new Promise((resolve, reject) => {
            const abort = () => reject(Object.assign(new Error("aborted"), { name: "AbortError" }));
            if (signal?.aborted) return abort();
            signal?.addEventListener("abort", abort);
            Promise.resolve(respond(url)).then(resolve, reject);
        });
    };
    return calls;
}

export function response(body = {}, status = 200) {
    return new Response(JSON.stringify({ query: "", total: 0, offset: 0, limit: 10, results: [], facets: {}, facetStats: {}, warnings: [], ...body }), {
        status,
        headers: { "Content-Type": "application/json" },
    });
}

/** A promise you resolve later, for responses that arrive out of order. */
export function deferred() {
    let resolve;
    const promise = new Promise((r) => { resolve = r; });
    return { promise, resolve };
}

export const tick = () => new Promise((r) => setTimeout(r, 0));
