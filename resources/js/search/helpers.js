/**
 * The search helpers that build requests and handle plain data (never the DOM): request URLs,
 * the race-safe searcher, facet options, prices, active filters, page numbers. The Alpine
 * factories (alpine-search.js) and the archive component (archive.js) build on them.
 */

/**
 * @typedef {Object} SearchHit
 * @property {number} id
 * @property {string} title - plain text; render with x-text / textContent
 * @property {Object<string, string|string[]>} labels - display names of the hit's slug fields, in the
 *   same shape: `hit.brand` ["rosa-clara"] → `hit.labels.brand` ["Rosa Clará"]. Plain text.
 * @property {{title: string, snippet: string}} formatted - safe HTML: `title` with highlight
 *   tags (default <mark>…</mark>) around matched terms, `snippet` a cropped, highlighted excerpt
 *   of the first non-empty crop field. Render with x-html / innerHTML.
 * @property {*} [x] - every other stored field, as plain text (long text fields — content,
 *   description, excerpt, short_description — are left out; `formatted.snippet` covers them)
 */

/**
 * @typedef {Object} SearchResponse
 * @property {string} query
 * @property {number} total - total matching documents across all pages (a lower bound when `exhaustive` is false)
 * @property {number} offset - the effective offset actually used (after clamping)
 * @property {number} limit - the effective limit actually used
 * @property {SearchHit[]} results
 * @property {Object<string, Object<string, number>>} facets - per requested facet field: {value: count}
 * @property {Object<string, {min: number, max: number}>} facetStats - per requested numeric facet field: bounds over the result set
 * @property {Object<string, Object<string, string>>} labels - per slug field: value → display name, for every
 *   value in `facets` and in the request's filters
 * @property {boolean} exhaustive - false when a cap (max_docs) cut the candidate set, so total and counts are estimates
 * @property {string[]} approximateFacets - facet fields whose counts cover only part of the result set
 * @property {string[]} truncatedFacets - facet fields with more values than `facets` lists (the token's facet_values)
 * @property {string[]} warnings - options that were ignored or had no effect (an undeclared sort/filter/facet field, a cap that was hit)
 * @property {string} time - e.g. "12.34ms"
 */

/**
 * What a failed request rejects with (and what `gaffer-search:error`'s `detail` holds): an Error whose
 * `message` is the endpoint's own `error` text when it sent one.
 * @typedef {Error & {name: "GafferSearchError", status: number, code: string|null, body: Object|null}} GafferSearchError
 *   code — the endpoint's machine-readable code: invalid_token (e.g. after a token-secret
 *   rotation — the page needs a refresh), token_expired (a token with a ttl ran out — also
 *   a refresh), token_required, config_missing, index_missing,
 *   index_unknown, index_not_built, wrong_token_type, facet_name_missing,
 *   facet_name_unknown, search_failed.
 */

function appendFilterValue(parts, key, value) {
    if (value == null) return;
    if (Array.isArray(value)) {
        for (const v of value) {
            parts.push(`${key}[]=${encodeURIComponent(v)}`);
        }
    } else if (typeof value === "object") {
        if (!isBlank(value.min)) parts.push(`${key}[min]=${encodeURIComponent(value.min)}`);
        if (!isBlank(value.max)) parts.push(`${key}[max]=${encodeURIComponent(value.max)}`);
    } else {
        parts.push(`${key}=${encodeURIComponent(value)}`);
    }
}

// A value is a string, a list (OR), {min, max} (range), {not: <any of those>} (exclusion) or
// {all: [values]} (every one of them). The keys any and all hold a list of filters of this same
// shape, not a field: at least one / every one of them holds; not holds one filter that must
// not hold (the engine's filter_groups).
function appendFilters(parts, filters, prefix = "filter") {
    for (const [field, value] of Object.entries(filters ?? {})) {
        if ((field === "any" || field === "all") && Array.isArray(value)) {
            value.forEach((entry, i) => appendFilters(parts, entry, `${prefix}[${field}][${i}]`));
        } else if (field === "not" && value != null && typeof value === "object" && !Array.isArray(value)) {
            appendFilters(parts, value, `${prefix}[not]`);
        } else if (isExclusion(value)) {
            appendFilterValue(parts, `${prefix}[${field}][not]`, value.not);
        } else if (isAllOf(value)) {
            appendFilterValue(parts, `${prefix}[${field}][all]`, [].concat(value.all ?? []));
        } else {
            appendFilterValue(parts, `${prefix}[${field}]`, value);
        }
    }
}

function isExclusion(value) {
    return value != null && typeof value === "object" && !Array.isArray(value) && "not" in value;
}

function isAllOf(value) {
    return value != null && typeof value === "object" && !Array.isArray(value) && "all" in value;
}

/**
 * URL for search.php. Free params per search-shared.php: q, offset, sort[], filter[].
 * `sort` may be a single "field:dir" string or an array of them.
 * @returns {string}
 */
function buildSearchUrl({ endpoint, token, query = "", offset = 0, sort = [], filters = {} }) {
    const parts = [
        `q=${encodeURIComponent(query)}`,
        `token=${encodeURIComponent(token)}`,
    ];
    if (offset) parts.push(`offset=${offset}`);
    for (const s of [].concat(sort)) {
        if (s) parts.push(`sort[]=${encodeURIComponent(s)}`);
    }
    appendFilters(parts, filters);
    return `${endpoint}?${parts.join("&")}`;
}

/**
 * Creates an independent cancelable-fetch function: each call aborts this
 * searcher's own previous in-flight request before starting the next one,
 * so a slow response for an old query can never overwrite a newer one.
 *
 * Also caches successful responses in memory, keyed by the exact request URL,
 * for as long as this searcher instance lives — a plain object in the closure,
 * never localStorage/sessionStorage, so it never survives a page reload or
 * navigation, only repeated calls within the same page view. A request URL
 * already encodes every meaningful input (q, offset, sort[], filter[]), so
 * it's already a complete cache key on its own — no separate hashing needed.
 * This makes the common "backspace back to something you already typed" case
 * (or re-selecting a filter combination you just had) resolve instantly
 * instead of re-fetching. Only successful responses are cached — a superseded
 * (`null`) or rejected call never populates it.
 *
 * Call once per widget instance (not per request) and reuse the returned
 * function — a fresh searcher starts with an empty cache. Two widgets on the
 * same page should each get their own searcher so they don't cancel each
 * other's requests (and don't share a cache, though that's a side effect —
 * the same URL wouldn't mean something different across widgets anyway).
 *
 * Resolves `null` when a newer call superseded this one — also when that
 * happens while the response body is still downloading. Rejects with a
 * GafferSearchError for a non-2xx response, carrying the endpoint's own `error`
 * message and `code` (the JSON body is read either way), and with the
 * browser's own error for a network failure.
 *
 * @returns {(url: string) => Promise<SearchResponse|null>}
 */
function createSearcher() {
    let controller = null;
    const cache = new Map();

    return async function search(url) {
        controller?.abort();

        if (cache.has(url)) {
            controller = null;
            return cache.get(url);
        }

        controller = new AbortController();
        const { signal } = controller;

        let res;
        let body;
        try {
            res = await fetch(url, { signal });
            body = await readJson(res);
        } catch (err) {
            if (err.name === "AbortError") return null;
            throw err;
        }
        if (!res.ok || body === null) throw createGafferSearchError(res.status, body);

        cache.set(url, body);
        return body;
    };
}

// The response's JSON, or null when it has none (a proxy's HTML error page, say). An abort
// while the body is downloading still rejects, so the caller can tell "superseded" apart.
async function readJson(res) {
    try {
        return await res.json();
    } catch (err) {
        if (err.name === "AbortError") throw err;
        return null;
    }
}

function createGafferSearchError(status, body) {
    const err = new Error(body?.error || `HTTP ${status}`);
    err.name = "GafferSearchError";
    err.status = status;
    err.code = body?.code ?? null;
    err.body = body;
    return err;
}

/**
 * Turn a facets response entry ({value: count}) into an array of {value, label, count}: the
 * value to filter on (a slug for a taxonomy field) and its display name from the response's
 * `labels` for that field (the value itself when it has none).
 * @param {Object<string, number>} facetCounts
 * @param {"count"|"alpha"|"index"} [sort] - "count" (default): count descending.
 *   "alpha": label ascending, locale `document.documentElement.lang || "nl-NL"`
 *   (same convention as formatCurrency()). "index": as returned by the
 *   response (insertion order), unsorted.
 * @param {Object<string, string>} [labels] - value → name, e.g. `data.labels.brand`.
 * @returns {{value: string, label: string, count: number}[]}
 */
function getFacetOptions(facetCounts, sort = "count", labels = {}) {
    const options = Object.entries(facetCounts ?? {}).map(([value, count]) => ({ value, label: labels?.[value] ?? value, count }));
    if (sort === "alpha") {
        const locale = document.documentElement.lang || "nl-NL";
        options.sort((a, b) => a.label.localeCompare(b.label, locale));
    } else if (sort === "count") {
        options.sort((a, b) => b.count - a.count);
    }
    return options;
}

/** Format a numeric value as currency, e.g. for a price range filter. */
function formatCurrency(value, { locale, currency = "EUR", maximumFractionDigits = 2 } = {}) {
    if (value == null) return "";
    return new Intl.NumberFormat(
        locale || document.documentElement.lang || "nl-NL",
        { style: "currency", currency, maximumFractionDigits },
    ).format(value);
}

// An open end of a range: unset, or an empty input's "".
function isBlank(value) {
    return value == null || value === "";
}

/**
 * Flattens a `filters` object (the same shape `buildSearchUrl`
 * accept: `{field: value[] | {min, max} | scalar | {not: …} | {all: […]}}`) into a flat "active filter
 * chips" list — one entry per array or `all` value, one entry for a min/max range (if
 * either bound is set), one entry for a scalar, and one per excluded value
 * (`exclude: true`, default label "≠ value"). Groups (any/all/not) get no chips.
 *
 * @param {Object<string, *>} filterState
 * @param {Object<string, (field: string, value: *) => string>} [labels] - per-field label formatter; falls back to the raw value
 * @returns {{field: string, value: *, label: string, exclude?: true}[]}
 */
function getActiveFilters(filterState, labels = {}) {
    const chips = [];
    for (const [field, value] of Object.entries(filterState ?? {})) {
        // A single-select's "nothing chosen" sentinel is '', not null/undefined
        // (assigning null there breaks the <select>'s DOM binding) — treat
        // both as equally empty so scalar fields need no special-casing.
        if (value == null || value === "") continue;
        // A group (any/all/not) is the page's own: no chip.
        if (["any", "all", "not"].includes(field)) continue;
        const format = labels[field];
        if (isAllOf(value)) {
            for (const v of [].concat(value.all ?? [])) {
                if (v == null || v === "") continue;
                chips.push({ field, value: v, label: format ? format(field, v) : String(v) });
            }
        } else if (isExclusion(value)) {
            const excluded = value.not;
            if (excluded != null && typeof excluded === "object" && !Array.isArray(excluded)) {
                if (isBlank(excluded.min) && isBlank(excluded.max)) continue;
                chips.push({ field, value: excluded, exclude: true, label: format ? format(field, excluded) : `≠ ${excluded.min ?? ""}–${excluded.max ?? ""}` });
            } else {
                for (const v of [].concat(excluded ?? [])) {
                    if (v == null || v === "") continue;
                    chips.push({ field, value: v, exclude: true, label: format ? format(field, v) : `≠ ${v}` });
                }
            }
        } else if (Array.isArray(value)) {
            for (const v of value) {
                chips.push({ field, value: v, label: format ? format(field, v) : String(v) });
            }
        } else if (typeof value === "object") {
            if (isBlank(value.min) && isBlank(value.max)) continue;
            chips.push({ field, value, label: format ? format(field, value) : `${value.min ?? ""}–${value.max ?? ""}` });
        } else {
            chips.push({ field, value, label: format ? format(field, value) : String(value) });
        }
    }
    return chips;
}

/**
 * Removes one active filter from `filterState` in place (same convention as
 * the Alpine-owned reactive objects this codebase already mutates directly —
 * see form-search.js). For an array field, removes just `value` (or the whole
 * array if `value` is omitted); for an exclusion (`{not: […]}`), removes just
 * that excluded `value` (or the whole exclusion); for a range/scalar field,
 * clears it entirely.
 *
 * @param {Object<string, *>} filterState
 * @param {string} field
 * @param {*} [value] - only meaningful for array-valued fields
 */
function removeFilter(filterState, field, value) {
    const current = filterState?.[field];
    if (isAllOf(current) && Array.isArray(current.all) && value !== undefined) {
        const i = current.all.indexOf(value);
        if (i !== -1) current.all.splice(i, 1);
    } else if (isAllOf(current)) {
        filterState[field] = {};
    } else if (isExclusion(current) && Array.isArray(current.not) && value !== undefined) {
        const i = current.not.indexOf(value);
        if (i !== -1) current.not.splice(i, 1);
    } else if (isExclusion(current)) {
        filterState[field] = {};
    } else if (Array.isArray(current)) {
        if (value === undefined) {
            filterState[field] = [];
        } else {
            const i = current.indexOf(value);
            if (i !== -1) current.splice(i, 1);
        }
    } else if (current && typeof current === "object") {
        filterState[field] = {};
    } else if (field in (filterState ?? {})) {
        // '' (not null) — a <select>'s own "nothing chosen" sentinel, so
        // clearing a scalar field this way resets its DOM binding correctly.
        filterState[field] = "";
    }
}

/**
 * Numbered-pagination windowing: always includes page 1 and the last page,
 * the current page's immediate siblings (±1), and `"..."` for any gap larger
 * than 1 between kept pages — e.g. `total=200, limit=10, offset=40` (page 5
 * of 20) → `[1, "...", 4, 5, 6, "...", 20]`.
 *
 * @param {number} total - SearchResponse.total
 * @param {number} limit - the effective limit (SearchResponse.limit, per search.php)
 * @param {number} offset - the effective offset (SearchResponse.offset, per search.php)
 * @returns {(number|"...")[]}
 */
function getPageNumbers(total, limit, offset) {
    const perPage = Math.max(1, limit);
    const lastPage = Math.max(1, Math.ceil(total / perPage));
    const current = Math.min(lastPage, Math.floor(offset / perPage) + 1);

    const kept = [...new Set([1, current - 1, current, current + 1, lastPage])]
        .filter((p) => p >= 1 && p <= lastPage)
        .sort((a, b) => a - b);

    const pages = [];
    let prev;
    for (const p of kept) {
        if (prev != null && p - prev > 1) pages.push("...");
        pages.push(p);
        prev = p;
    }
    return pages;
}

export { buildSearchUrl, createSearcher, getFacetOptions, formatCurrency, getActiveFilters, removeFilter, getPageNumbers };
