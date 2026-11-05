import { buildSearchUrl, createSearcher } from "./helpers.js";

/**
 * createCounter({ endpoint, token }) — a count that follows the visitor's choices, from the
 * endpoint and token of Search::counter(). The returned function takes a filter (the shape
 * buildSearchUrl() takes) and resolves to:
 *
 *   a number    the total for that filter
 *   null        no count: the token is stale, the index isn't built, the network failed
 *   undefined   a newer call replaced this one: keep what's shown, that call will answer
 *
 * A count is an extra on a page that works without it, so a failure is never an exception.
 * A filter the engine ignored (a field not in `facets`) gives a total that's too high: it's
 * in the console, as doctor can't see a filter built here.
 */
export function createCounter({ endpoint, token }) {
    const search = createSearcher();

    return async function count(filters = {}) {
        let response;
        try {
            response = await search(buildSearchUrl({ endpoint, token, filters }));
        } catch {
            return null;
        }
        if (response === null) return undefined;
        for (const warning of response.warnings ?? []) console.warn(`Gaffer Search: ${warning}`);

        return Number.isInteger(response.total) ? response.total : null;
    };
}
