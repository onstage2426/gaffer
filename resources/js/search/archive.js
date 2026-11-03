import { createAlpineSearch, createFilterForm } from "./alpine-search.js";

/**
 * archive(options?) — a server-rendered archive (Search::archive()) that the browser continues:
 * sorting, filters and pages search the endpoint, and each response's pre-rendered cards replace
 * the list. Put it on a wrapper around the archive form, the card list and the pages: cards hold
 * forms of their own (sizes, add to cart), and a form can't sit inside another.
 *
 *   form      — the archive form (its controls only), default "#archive-form"
 *   list      — the element the cards go into, default "#archive-list"
 *   initial   — the <script type="application/json"> with archive.initial(), default "#archive-results"
 *   cardField — the hit field with the visitor's card: archive.card_field
 *   fields    — the archive's filters (createFilterForm()'s fields): `filters`, `options()`,
 *               `activeFilters()`, `removeFilter()` come with them
 *   item      — the element around each card, default "li", with class `itemClass`; null for
 *               cards that are their own list item (a card template with its own <li>)
 *   onRender  — called with the list after new cards are in (e.g. list => htmx.process(list))
 *
 * pageUrl(n) is page n of the current search (from the address bar): the href for page links
 * the component renders after paging (the server's are search.page_url(n)).
 *
 * viewUrl(value) is this page's current search (from the address bar, first page) in another
 * layout (a view's `value` in archive.views: "" for the default): a link that loads that page.
 *
 * Everything else (minQueryLength, onBeforeRun, onSuccess) goes to createAlpineSearch().
 */
function archive({ form = "#archive-form", list = "#archive-list", initial = "#archive-results", cardField = "card", item = "li", itemClass = "", fields, onRender, onSuccess, ...searchOptions } = {}) {
    const listElement = () => document.querySelector(list);
    const initialData = typeof initial === "string" ? JSON.parse(document.querySelector(initial)?.textContent || "null") : initial;

    const options = {
        ...searchOptions,
        form,
        initial: initialData ?? undefined,
        onSuccess(data) {
            // The address bar is written before the results arrive (syncUrl): keep it here,
            // reactive, so links built from it (pageUrl, viewUrl) follow every search.
            this.address = location.search;
            this.showCards(data);
            onSuccess?.call(this, data);
        },
    };

    return {
        ...(fields ? createFilterForm(fields, options) : createAlpineSearch(options)),

        // location.search as of the last search: Alpine re-evaluates :href bindings that read it.
        address: location.search,

        showCards(data) {
            const element = listElement();
            if (!element) return;
            const cards = (data.results ?? []).map((hit) => hit[cardField] ?? "");
            if (!item) {
                element.innerHTML = cards.join("");
            } else {
                element.replaceChildren(...cards.map((card) => {
                    const wrapper = document.createElement(item);
                    if (itemClass) wrapper.className = itemClass;
                    wrapper.innerHTML = card;
                    return wrapper;
                }));
            }
            onRender?.(element);
        },

        pageUrl(page) {
            const params = new URLSearchParams(this.address);
            const offset = (Math.max(1, page) - 1) * (this.limit || 1);
            if (offset > 0) params.set("offset", String(offset));
            else params.delete("offset");
            const query = params.toString();
            return query ? `${location.pathname}?${query}` : location.pathname;
        },

        viewUrl(name) {
            const params = new URLSearchParams(this.address);
            params.delete("offset");
            if (name) params.set("view", name);
            else params.delete("view");
            const query = params.toString();
            return query ? `${location.pathname}?${query}` : location.pathname;
        },

        async goTo(page) {
            await this.goToPage(page);
            listElement()?.scrollIntoView({ behavior: "smooth", block: "start" });
        },
    };
}

export { archive };
