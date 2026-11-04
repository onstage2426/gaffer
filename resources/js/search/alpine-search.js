import { search } from "./form-search.js";
import { getActiveFilters, getFacetOptions, getPageNumbers, removeFilter } from "./helpers.js";

/**
 * createAlpineSearch(options?) — the search-request lifecycle shared by
 * every search Alpine component (a dropdown searchbar, a full archive
 * page, ...), meant to be spread into x-data. Deliberately excludes anything
 * about how a particular consumer displays results — no `open`/`close`, no
 * range-slider glue — those stay in each component's own x-data, layered on
 * top of `run()` via plain method borrowing (`base.run.call(this, opts)`);
 * see searchbar.twig for the pattern.
 */

/**
 * @param {{ minQueryLength?: number, initial?: object, form?: HTMLFormElement|string, onBeforeRun?: (this: object, opts: *, component: object) => void, onSuccess?: (this: object, data: object, component: object) => void }} [options]
 *   minQueryLength — below this, run() resets state and skips the request
 *   entirely instead of firing an empty-query "browse everything" search.
 *   Default 0: every query, including "", is a real request — right for a
 *   consumer with a display mode for an unfiltered result set (an archive
 *   page). A dropdown searchbar, which has no display mode for an empty
 *   query at all, sets this to 1.
 *   initial — the response the page was rendered with (`Search::archive()`'s
 *     `initial()`, a SearchResponse): the component starts from it (results,
 *     facets, query, offset) instead of fetching the first page again.
 *   form — the <form> to search with (an element, or a selector looked up
 *     inside the component's root first, then in the document), when the
 *     x-data sits on a wrapper around the form, its results and pagination
 *     instead of on the form itself: results with forms of their own (add to
 *     cart) can't sit inside the search form, the HTML parser drops nested
 *     <form> tags. Default: the component's root element, which must then be
 *     the form.
 *   onBeforeRun(opts, component) — called (with `this` bound to the component) right
 *     before a real request fires; not called when skipped by
 *     minQueryLength. For consumer-owned display state that reacts to a
 *     search starting (e.g. opening a dropdown).
 *   onSuccess(data, component) — called (with `this` bound to the component) after a
 *     successful response has updated results/total/facets/etc.: every one,
 *     run()'s and back/forward's alike (that one happens outside run()), so
 *     whatever it renders always follows what is on screen. Not called for
 *     `initial`, or a skipped (undefined), superseded, or failed (null) run().
 *   The component is also the last argument, for arrow functions (which
 *   ignore the bound `this`).
 *   These are the preferred way to layer consumer-specific behavior onto
 *   run() without reimplementing it. run() itself stays on
 *   the returned object either way, so the older `base.run.call(this,
 *   opts)` method-borrowing pattern (for anything these two hooks don't
 *   cover) still works unchanged.
 */
// The search form of a component: its root, or the `form` option. Checked
// here, at init(), so a component on the wrong element fails at once instead
// of on its first search.
function resolveComponentForm(root, form) {
    let el = form ?? root;
    if (typeof form === "string") {
        el = root.matches(form) ? root : (root.querySelector(form) ?? document.querySelector(form));
    }
    if (!(el instanceof HTMLFormElement)) {
        throw new Error(
            form === undefined
                ? "Gaffer Search: createAlpineSearch() must sit on the search <form>, or be given it with the `form` option " +
                  "(createAlpineSearch({ form: '#archive-form' })) when its x-data sits on a wrapper around the form and its results."
                : `Gaffer Search: createAlpineSearch()'s \`form\` option found ${el === null ? "no element" : `a <${el.tagName.toLowerCase()}>`}, not a <form>.`,
        );
    }
    return el;
}

function createAlpineSearch({ minQueryLength = 0, initial, form, onBeforeRun, onSuccess } = {}) {
    return {
        query: "",
        searchedQuery: "",
        results: [],
        total: 0,
        facets: {},
        facetStats: {},
        // value → display name per field, kept from every response (a value seen once keeps
        // its name, so a selected filter's chip still has it after its count drops to 0).
        labels: {},
        // From the last response: the page shown and the page size. The form
        // sends `offset` through its own field (<input type="hidden"
        // name="offset" :value="offset">), so goToPage() only has to set it.
        offset: 0,
        limit: 0,
        loading: false,
        // null, or { message, code, status } from the last failed request —
        // code is the endpoint's own (invalid_token after a token-secret
        // rotation, token_expired after a token's ttl: the page needs a
        // refresh). Cleared by the next run().
        error: null,
        _runSeq: 0,
        _keepOffset: false,

        // Captured once here, in a proper init() lifecycle hook, rather than
        // re-resolving this.$root inside run() on every call — a consumer
        // extending run() via base.run.call(this, opts) may
        // do so from a nested x-data (a filter drawer/modal with its own
        // local state); $root inside a borrowed/rebound `this` doesn't
        // reliably resolve to the form in that case, but a plain data
        // property read does, regardless of nesting depth.
        init() {
            this._form = resolveComponentForm(this.$root, form);

            // Browser back/forward restores this form's own fields and
            // re-searches entirely inside form-search.js's popstate handler
            // — outside run(), which never gets called. gaffer-search:results,
            // dispatched there on every successful resolution regardless of
            // caller, is the only way this component ever hears about it, so
            // listening here (not just relying on run()'s own return value)
            // is what makes back/forward actually update the page instead of
            // leaving results/total/facets frozen at whatever was on screen
            // before the user pressed back.
            // onSuccess runs here too, not in run(), for the same reason.
            this._form.addEventListener("gaffer-search:results", (e) => {
                this._applyResult(e.detail);
                onSuccess?.call(this, e.detail, this);
            });

            // A failed back/forward re-search also happens outside run().
            this._form.addEventListener("gaffer-search:error", (e) => this._applyError(e.detail));

            if (initial) {
                this.query = initial.query ?? "";
                this._applyResult(initial);
            }
        },

        _applyError(err) {
            this.error = {
                message: err?.message ?? "Search failed.",
                code: err?.code ?? null,
                status: err?.status ?? null,
            };
        },

        _applyResult(data) {
            this.error = null;
            this.results = data.results ?? [];
            this.total = data.total ?? 0;
            this.facets = data.facets ?? {};
            this.facetStats = data.facetStats ?? {};
            for (const [field, names] of Object.entries(data.labels ?? {})) {
                this.labels[field] = { ...this.labels[field], ...names };
            }
            this.offset = data.offset ?? 0;
            this.limit = data.limit ?? this.limit;
            this.searchedQuery = data.query ?? this.query;

            // A misconfiguration signal (an undeclared facet, filter, or sort
            // field, a cap that was hit), not a query error — the search
            // library reports these exactly; dev-console-only so they're
            // never silently dropped.
            data.warnings?.forEach((w) => console.warn("[Gaffer Search]", w));
        },

        /**
         * Never rejects.
         * @returns the response on success, `undefined` if skipped (below
         * minQueryLength — nothing was sent), `null` if superseded by a
         * newer call on this form (a newer call already owns state) or if
         * the request failed (see `error`).
         */
        async run(opts) {
            // Numbered, so only the newest call touches loading/error: an
            // older one that resolves late (superseded, or answered from the
            // duplicate-request check) must not switch the spinner off while
            // the newest is still in flight.
            const seq = ++this._runSeq;

            // Any change other than a page click (a filter, the sort, the
            // query) starts again at the first page.
            if (this._keepOffset) {
                this._keepOffset = false;
            } else {
                this.offset = 0;
            }

            if (this.query.trim().length < minQueryLength) {
                this.results = [];
                this.total = 0;
                this.facets = {};
                this.facetStats = {};
                this.searchedQuery = "";
                this.error = null;
                this.loading = false;
                return undefined;
            }

            // Alpine's x-model/:value writes land on the next reactive-effect
            // flush, not synchronously with the property assignment that
            // triggered this call — reading the form (FormData, inside
            // search()) before that flush would see stale values.
            // $nextTick is a plain reference to Alpine's global scheduler
            // (unlike $root, it isn't resolved per-component), so this is
            // safe even under a borrowed/rebound `this`.
            await this.$nextTick();

            onBeforeRun?.call(this, opts, this);

            this.loading = true;
            try {
                const data = await search(this._form, opts);
                if (data === null) return null;

                // _applyResult() and onSuccess already ran synchronously above,
                // via the gaffer-search:results listener registered in init() —
                // dispatchEvent() is synchronous, and search() dispatches it
                // before this await resolves. Not repeated here.
                return data;
            } catch {
                // Already applied to `error` by the gaffer-search:error listener.
                return null;
            } finally {
                if (seq === this._runSeq) this.loading = false;
            }
        },

        // Methods, not getters: object spread (the extension pattern) would
        // freeze a getter's first value.
        currentPage() {
            return Math.floor(this.offset / (this.limit || 1)) + 1;
        },

        pageNumbers() {
            return getPageNumbers(this.total, this.limit, this.offset);
        },

        /** Search page `page` (1-based) of the current results. */
        goToPage(page) {
            this.offset = (page - 1) * this.limit;
            this._keepOffset = true;
            return this.run();
        },
    };
}

/**
 * createFilterForm(fields, searchOptions?) — createAlpineSearch() plus
 * the generic filter-state plumbing every multi-filter consumer needs:
 * per-field state read from the URL (on load and on back/forward), options
 * for a checkbox/select list, active-filter "chips", and clearing one or all
 * filters. Also keeps `sort` (the first sort[] in the URL). Meant to be
 * spread into x-data exactly like createAlpineSearch().
 *
 * `fields` is plain data — however a consumer produces it (a JS literal,
 * JSON from a data attribute, server-rendered into an inline <script>,
 * whatever) — this function never assumes a template, a config-file
 * convention, or any particular visual widget. It only needs to know, per
 * field, the shape of its state; everything about how a field is *rendered*
 * is entirely the consumer's concern.
 *
 * @param {{ field: string, type?: "single"|"multi"|"range"|"toggle", multi?: boolean, value?: string, label?: string, sort?: "count"|"alpha"|"index" }[]} fields
 *   field — the *indexed* field name (the facets/filter[] key), not
 *     necessarily whatever WP-side slug it may have started from.
 *   type — the field's state, and the form controls it pairs with:
 *     "single" (default): a string, "" for none — a <select> or radios
 *       named filter[x].
 *     "multi" (or `multi: true`): an array — checkboxes named filter[x][].
 *     "range": { min, max }, "" for an open end — two inputs named
 *       filter[x][min] and filter[x][max] (the range slider's).
 *     "toggle": a boolean — one checkbox named filter[x] with `value` as
 *       its value ("yes" by default: on_sale), e.g. "On sale", "In stock".
 *   label — a toggle's chip label (default: the value's label, or the value).
 *   sort — order options(field) returns this field's entries in. "count"
 *     (default): count descending. "alpha": name ascending, locale-aware.
 *     "index": as returned by the response (insertion order), unsorted.
 * @param {Parameters<typeof createAlpineSearch>[0]} [searchOptions] - passed straight through to createAlpineSearch()
 */
function createFilterForm(fields, searchOptions) {
    const base = createAlpineSearch(searchOptions);
    const sortFor = Object.fromEntries(fields.map((f) => [f.field, f.sort ?? "count"]));
    const typeOf = (f) => f.type ?? (f.multi ? "multi" : "single");
    const toggleValue = (f) => String(f.value ?? "yes");
    const cleared = (f) => ({ multi: [], range: { min: "", max: "" }, toggle: false })[typeOf(f)] ?? "";
    const byField = Object.fromEntries(fields.map((f) => [f.field, f]));

    return {
        ...base,

        filters: Object.fromEntries(fields.map((f) => [f.field, cleared(f)])),

        // The first sort[] spec, "" for the token's default order — for a
        // <select name="sort[]" x-model="sort">.
        sort: "",

        // Restores filters from the URL on page load and again on every
        // back/forward (gaffer-search:popstate) — mechanical, and generically
        // derivable from `fields`, so a consumer's own init() shouldn't have
        // to reimplement this per field by hand. A consumer with additional
        // state of its own in the URL re-reads it from the same
        // gaffer-search:popstate event, and still needs its own init() chaining to
        // this one (an init() of its own shadows this one).
        init() {
            base.init.call(this);
            this._seedFilters(new URLSearchParams(location.search));
            this._form.addEventListener("gaffer-search:popstate", (e) => this._seedFilters(e.detail.params));
        },

        _seedFilters(params) {
            for (const f of fields) {
                const name = `filter[${f.field}]`;
                this.filters[f.field] = {
                    multi: () => params.getAll(`${name}[]`),
                    range: () => ({ min: params.get(`${name}[min]`) ?? "", max: params.get(`${name}[max]`) ?? "" }),
                    toggle: () => params.get(name) === toggleValue(f),
                }[typeOf(f)]?.() ?? params.get(name) ?? params.get(`${name}[]`) ?? "";
            }
            this.sort = params.get("sort[]") ?? "";
        },

        // Includes a placeholder entry (count: 0) for any currently-selected
        // value not yet in the real facet distribution — critical the first
        // time this runs: this.facets is still {} before any response has
        // arrived, so without this, a value seeded into this.filters from the
        // URL (in a consumer's own init(), before the first run()) would have
        // no matching checkbox/<option> to render at all. Since run() awaits
        // $nextTick() before reading the form, that missing control means the
        // very first request silently goes out unfiltered — everything
        // *else* (the URL, the chips, this.filters itself) looks right, only
        // the actual request doesn't reflect it, until the next run() call
        // (by which point a response has populated this.facets for real).
        options(field) {
            const selected = this.filters[field];
            const selectedValues = Array.isArray(selected) ? selected : typeof selected === "string" && selected ? [selected] : [];
            const counts = { ...this.facets[field] };
            for (const v of selectedValues) {
                if (!(v in counts)) counts[v] = 0;
            }
            return getFacetOptions(counts, sortFor[field], this.labels[field]);
        },

        // A method, not a getter: the extension
        // pattern is object spread (`{...base, ...moreStuff}`), and spread
        // snapshots a getter's *current value* once into a plain, frozen
        // data property — it does not preserve the accessor. Since a
        // consumer's own filters seeding (in their init(), after base's
        // filters starts empty) happens after that spread, a getter here
        // would freeze at [] forever. A method has no such hazard — calling
        // it always re-reads this.filters live, spread or not.
        activeFilters() {
            const state = {};
            const names = {};
            for (const f of fields) {
                const type = typeOf(f);
                state[f.field] = type === "toggle" ? (this.filters[f.field] ? toggleValue(f) : "") : this.filters[f.field];
                if (type === "toggle") {
                    names[f.field] = (field, value) => f.label ?? this.labels[field]?.[value] ?? String(value);
                } else if (type !== "range") {
                    // Ranges keep getActiveFilters()'s own "min–max".
                    names[f.field] = (field, value) => this.labels[field]?.[value] ?? String(value);
                }
            }
            return getActiveFilters(state, names);
        },

        removeFilter(field, value) {
            this._clear(field, value);
            this.run();
        },

        clearAllFilters() {
            for (const f of fields) this._clear(f.field);
            this.run();
        },

        _clear(field, value) {
            const f = byField[field];
            if (f && (typeOf(f) === "range" || typeOf(f) === "toggle")) {
                this.filters[field] = cleared(f);
            } else {
                removeFilter(this.filters, field, value);
            }
        },
    };
}

export { createAlpineSearch, createFilterForm };
