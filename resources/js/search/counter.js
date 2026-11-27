import { buildSearchUrl, createSearcher } from "./helpers.js";

/**
 * createCounter(element) — a count that follows the visitor's choices, from the attributes
 * `{{ counter.attrs() }}` (Search::counter()) wrote on the element. (An object `{ endpoint, token }`,
 * as templates passed before Counter::attrs(), still works.) The returned function takes a filter
 * (the shape buildSearchUrl() takes) and resolves to:
 *
 *   a number    the total for that filter
 *   null        no count: the token is stale, the index isn't built, the network failed
 *   undefined   a newer call replaced this one: keep what's shown, that call will answer
 *
 * A count is an extra on a page that works without it, so a failure is never an exception.
 * A filter the engine ignored (a field not in `facets`) gives a total that's too high: it's
 * in the console, as doctor can't see a filter built here.
 */
export function createCounter(source) {
    const { endpoint, token } = source instanceof Element
        ? { endpoint: source.dataset.gafferSearchEndpoint, token: source.dataset.gafferSearchToken }
        : (source ?? {});
    if (!endpoint || !token) return async () => null; // without the plugin: nothing to count
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
