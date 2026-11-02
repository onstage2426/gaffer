import { createAlpineSearch } from "./alpine-search.js";

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
 *   item      — the element around each card, default "li", with class `itemClass`
 *   onRender  — called with the list after new cards are in (e.g. list => htmx.process(list))
 *
 * Everything else (minQueryLength, onBeforeRun, onSuccess) goes to createAlpineSearch().
 */
function archive({ form = "#archive-form", list = "#archive-list", initial = "#archive-results", cardField = "card", item = "li", itemClass = "", onRender, onSuccess, ...searchOptions } = {}) {
    const listElement = () => document.querySelector(list);
    const initialData = typeof initial === "string" ? JSON.parse(document.querySelector(initial)?.textContent || "null") : initial;

    return {
        ...createAlpineSearch({
            ...searchOptions,
            form,
            initial: initialData ?? undefined,
            onSuccess(data) {
                this.showCards(data);
                onSuccess?.call(this, data);
            },
        }),

        showCards(data) {
            const element = listElement();
            if (!element) return;
            element.replaceChildren(...(data.results ?? []).map((hit) => {
                const wrapper = document.createElement(item);
                if (itemClass) wrapper.className = itemClass;
                wrapper.innerHTML = hit[cardField] ?? "";
                return wrapper;
            }));
            onRender?.(element);
        },

        async goTo(page) {
            await this.goToPage(page);
            listElement()?.scrollIntoView({ behavior: "smooth", block: "start" });
        },
    };
}

export { archive };
