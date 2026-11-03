import { createSearcher } from "./helpers.js";

/**
 * search(form) — the request layer under createAlpineSearch(): the form is the single source
 * of truth for query and filter state. Its own name="filter[price][min]" serialization is what
 * search-shared.php parses from $_GET, so the same <form method="get"> submitted without
 * JavaScript gives a correct, shareable, server-renderable URL; this reads its fields, fetches
 * JSON instead of navigating, keeps the address bar in step (data-gaffer-search-history) and announces
 * the result on the form (gaffer-search:results / gaffer-search:error). It never touches the results markup.
 *
 */

const formSearchers = new WeakMap(); // form -> cancelable searcher (auto race-safety, no caller bookkeeping)
const formEndpoints = new WeakMap(); // form -> last explicit endpoint override, remembered for popstate re-fetches
const historyBoundForms = new WeakSet(); // forms with a popstate listener already attached
const formLastParams = new WeakMap(); // form -> params string of the last successfully completed request

function resolveForm(formOrSelector) {
    const form = typeof formOrSelector === "string" ? document.querySelector(formOrSelector) : formOrSelector;
    if (!(form instanceof HTMLFormElement)) {
        throw new Error(
            `Gaffer Search: expected a <form> element or selector, got ${form === null ? "no match" : typeof form}.`,
        );
    }
    return form;
}

function formToParams(form) {
    const params = new URLSearchParams();
    for (const [name, value] of new FormData(form)) {
        // A <select>/checkbox sitting on its own empty default contributes an
        // empty string to FormData regardless — skip it so a page with
        // nothing actually chosen doesn't grow noise like `sort[]=` or
        // `filter[brand]=` in the request/address bar. Mirrors
        // FilterQuery::parse()'s existing `!== ''` guard server-side.
        if (value === "") continue;
        params.append(name, value);
    }
    return params;
}

// The distinct field names this form itself owns (including its hidden
// "token" input) — used by syncUrl() to know which existing address-bar
// params belong to this form and are safe to overwrite, as opposed to a
// different search form's params living in the same location.search.
function formFieldNames(form) {
    const names = new Set();
    for (const el of form.elements) {
        if (el.name) names.add(el.name);
    }
    return names;
}

// Per-field history rules, read from attributes on the form's own controls:
//   data-gaffer-search-history="ignore" — never written to the address bar (a forced
//     filter that duplicates what the page's path already says, say)
//   data-gaffer-search-default="0"      — left out of the address bar while the field
//     holds this value (a hidden `offset` input on its first page), and put
//     back to it on back/forward when the address bar doesn't mention the field
function historyRules(form) {
    const ignored = new Set();
    const defaults = new Map();
    for (const el of form.elements) {
        if (!el.name) continue;
        if (el.dataset.gafferSearchHistory === "ignore") ignored.add(el.name);
        if (el.dataset.gafferSearchDefault !== undefined) defaults.set(el.name, el.dataset.gafferSearchDefault);
    }
    return { ignored, defaults };
}

// Repopulates a form's fields from parsed URL params — the inverse of
// formToParams(), used to restore filter state on popstate (back/forward).
// Ignored fields keep their value; a field with a default that the URL
// doesn't mention goes back to that default.
function applyParamsToForm(form, params) {
    const { ignored, defaults } = historyRules(form);
    for (const el of form.elements) {
        if (ignored.has(el.name)) continue;
        if (el.type === "checkbox" || el.type === "radio") el.checked = false;
    }
    for (const [name, value] of defaults) {
        if (ignored.has(name) || params.has(name)) continue;
        const target = form.elements.namedItem(name);
        if (target && !(target instanceof RadioNodeList) && target.type !== "checkbox" && target.type !== "radio") {
            target.value = value;
        }
    }
    for (const [name, value] of params) {
        if (name === "token" || ignored.has(name)) continue;
        const target = form.elements.namedItem(name);
        if (!target) continue;
        if (target instanceof RadioNodeList) {
            for (const el of target) {
                if ((el.type === "checkbox" || el.type === "radio") && el.value === value) el.checked = true;
            }
        } else if (target.type === "checkbox" || target.type === "radio") {
            if (target.value === value) target.checked = true;
        } else {
            target.value = value;
        }
    }
}

// Back/forward: restore the form from the URL, announce it (gaffer-search:popstate —
// the Alpine helpers re-read their filter state from it, and a consumer
// re-reads any extra state it keeps in the URL, like sort or offset), then
// search again without writing history. A failure there has already been
// announced as gaffer-search:error; there is no caller to hand the rejection to.
function ensurePopstateHandler(form) {
    if (historyBoundForms.has(form)) return;
    historyBoundForms.add(form);
    window.addEventListener("popstate", () => {
        const params = new URLSearchParams(location.search);
        applyParamsToForm(form, params);
        form.dispatchEvent(new CustomEvent("gaffer-search:popstate", { detail: { params }, bubbles: true }));
        performSearch(form, { endpoint: formEndpoints.get(form), commit: false, writeHistory: false }).catch(() => {});
    });
}

// data-gaffer-search-history="push"   — commit:true -> pushState, commit:false -> replaceState (live preview)
// data-gaffer-search-history="replace" — always replaceState, regardless of commit
// absent / "off"               — no history writes
function syncUrl(form, params, commit) {
    const mode = form.dataset.gafferSearchHistory;
    if (!mode || mode === "off") return;

    const { ignored, defaults } = historyRules(form);

    // Merge into the existing address bar instead of rebuilding it from only
    // this form's own fields — otherwise two independent search forms
    // on the same page (a header searchbar plus an archive filter form, say)
    // wipe out each other's params on every write. Only the keys this form
    // itself owns are cleared and rewritten; everything else in
    // location.search (another form's params) is left untouched.
    const merged = new URLSearchParams(location.search);
    for (const name of formFieldNames(form)) {
        merged.delete(name);
    }
    // The signed token is transport, not visible application state — a fresh
    // page load re-derives its own token server-side, so it's never written
    // back (formFieldNames() already cleared any stale copy above).
    for (const [name, value] of params) {
        if (name === "token" || ignored.has(name)) continue;
        if (defaults.has(name) && defaults.get(name) === value) continue;
        merged.append(name, value);
    }
    const query = merged.toString();
    const url = query ? `${location.pathname}?${query}` : location.pathname;

    if (mode === "push" && commit) {
        history.pushState({ gafferSearch: true }, "", url);
    } else {
        history.replaceState({ gafferSearch: true }, "", url);
    }
    ensurePopstateHandler(form);
}

async function performSearch(form, { endpoint, commit, writeHistory }) {
    const params = formToParams(form);
    const paramString = params.toString();

    // A repeat call with the exact same params as the last completed
    // request — e.g. a native `change` on <input type=search> firing on
    // blur right after an identical debounced @input already searched — is
    // a guaranteed no-op. Treat it the same as a superseded call so callers
    // don't re-toggle loading/results state or re-dispatch gaffer-search:results
    // for nothing.
    if (formLastParams.get(form) === paramString) return null;

    let searcher = formSearchers.get(form);
    if (!searcher) {
        searcher = createSearcher();
        formSearchers.set(form, searcher);
    }

    // data-gaffer-search-endpoint: the form's action stays the page itself (a no-JS submit renders it).
    const base = endpoint ?? form.dataset.gafferSearchEndpoint ?? form.action;
    const url = `${base}${base.includes("?") ? "&" : "?"}${paramString}`;

    let data;
    try {
        data = await searcher(url);
    } catch (err) {
        form.dispatchEvent(new CustomEvent("gaffer-search:error", { detail: err, bubbles: true }));
        throw err;
    }
    if (data !== null) {
        // History follows the results, not the request: a failed or
        // superseded search never leaves the address bar describing results
        // that aren't on screen.
        if (writeHistory) syncUrl(form, params, commit);
        formLastParams.set(form, paramString);
        form.dispatchEvent(new CustomEvent("gaffer-search:results", { detail: data, bubbles: true }));
    }
    return data;
}

/**
 * @param {HTMLFormElement|string} formOrSelector
 * @param {{ endpoint?: string, commit?: boolean }} [options]
 *   endpoint — defaults to the form's data-gaffer-search-endpoint, else its `action`. Lets one form serve two
 *     purposes: a self-submitting archive form's action="" (current page,
 *     for the no-JS fallback) and its JS-enhanced fetch targeting
 *     search.php are the same markup, with the enhancement script passing
 *     an `endpoint` explicitly.
 *   commit — true (default) for a settled choice (checkbox toggle, sort
 *     change, slider release, submit/pagination click): syncs history via
 *     pushState if `data-gaffer-search-history="push"`. false for a live preview
 *     (a debounced keystroke, a slider mid-drag): syncs via replaceState
 *     instead, so typing doesn't spam back-button history. History is
 *     written once the response has arrived.
 * @returns {Promise<SearchResponse|null>} null when
 *   superseded; rejects with a GafferSearchError (also dispatched on the form as
 *   `gaffer-search:error`) when the request fails.
 */
function search(formOrSelector, { endpoint, commit = true } = {}) {
    const form = resolveForm(formOrSelector);
    if (form.method !== "get") {
        throw new Error(
            `Gaffer Search: the search form must use method="get" (found "${form.method}") — search.php only reads ` +
            `$_GET, so a GET-only contract is required for the plain-HTML no-JS fallback to work at all.`,
        );
    }
    if (endpoint !== undefined) formEndpoints.set(form, endpoint);
    return performSearch(form, { endpoint: formEndpoints.get(form), commit, writeHistory: true });
}

export { search };
