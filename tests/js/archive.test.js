import { beforeEach, describe, expect, test } from "bun:test";
import { mockFetch, response } from "./fetch.js";

const F = window.GafferSearch;

// The archive's markup: the component on a wrapper around the form, the list and the initial JSON.
function archive(options = {}) {
    document.body.innerHTML = `
        <script type="application/json" id="archive-results">{"query":"","total":30,"offset":0,"limit":2,"results":[{"id":1,"card":"<article>1</article>"}]}</script>
        <div id="wrapper">
            <form id="archive-form" method="get" action="https://shop.test/search.php">
                <input type="hidden" name="token" value="T"><input type="hidden" name="offset" value="0">
            </form>
            <ul id="archive-list"><li><article>1</article></li></ul>
        </div>`;
    const root = document.getElementById("wrapper");
    const c = { ...F.archive(options), $root: root, $nextTick: async () => { document.querySelector("#archive-form").elements.offset.value = String(c.offset); } };
    c.init();
    return c;
}

beforeEach(() => history.replaceState(null, "", "/winkel/"));

describe("archive", () => {
    test("cards that are their own list item go in as they are", async () => {
        mockFetch(() => response({ total: 30, limit: 2, offset: 2, results: [{ id: 3, card: "<li class=\"card\">3</li>" }, { id: 4, card: "<li class=\"card\">4</li>" }] }));
        const c = archive({ item: null });

        await c.goToPage(2);
        expect(document.getElementById("archive-list").innerHTML).toBe('<li class="card">3</li><li class="card">4</li>');
    });

    test("pageUrl is page n of the current search", () => {
        history.replaceState(null, "", "/winkel/?filter%5Bon_sale%5D=yes&offset=2&view=list");
        const c = archive();

        expect(c.pageUrl(3)).toBe("/winkel/?filter%5Bon_sale%5D=yes&offset=4&view=list");
        expect(c.pageUrl(1)).toBe("/winkel/?filter%5Bon_sale%5D=yes&view=list");
    });

    test("with fields it is a filter form too", () => {
        history.replaceState(null, "", "/winkel/?filter%5Bbrand%5D%5B%5D=stella");
        const c = archive({ fields: [{ field: "brand", type: "multi" }] });

        expect(c.filters.brand).toEqual(["stella"]);
        expect(typeof c.activeFilters).toBe("function");
        expect(typeof c.showCards).toBe("function");
    });

    test("viewUrl is the current search on its first page in another layout", () => {
        history.replaceState(null, "", "/winkel/?filter%5Bon_sale%5D=yes&offset=24&show=all");
        const c = archive();

        expect(c.viewUrl("list")).toBe("/winkel/?filter%5Bon_sale%5D=yes&show=all&view=list");
        history.replaceState(null, "", "/winkel/?view=list&q=tegel");
        expect(archive().viewUrl("")).toBe("/winkel/?q=tegel");
    });

    test("links follow a filter changed on page 1, before any paging", async () => {
        mockFetch(() => response({ total: 5, limit: 2, offset: 0, results: [{ id: 3, card: "<a>3</a>" }] }));
        const c = archive();
        const form = document.querySelector("#archive-form");
        form.dataset.gafferSearchHistory = "push";
        form.elements.offset.dataset.gafferSearchDefault = "0"; // as the skill's template has it
        form.insertAdjacentHTML("beforeend", '<input type="checkbox" name="filter[on_sale]" value="yes" checked>');

        expect(c.pageUrl(2)).toBe("/winkel/?offset=2");
        await c.run();

        expect(location.search).toBe("?filter%5Bon_sale%5D=yes");
        expect(c.pageUrl(2)).toBe("/winkel/?filter%5Bon_sale%5D=yes&offset=2");
        expect(c.viewUrl("list")).toBe("/winkel/?filter%5Bon_sale%5D=yes&view=list");
    });

    test("starts from the page's own results without a request", () => {
        const calls = mockFetch();
        const c = archive();

        expect([c.total, c.limit, c.results.length, c.currentPage()]).toEqual([30, 2, 1, 1]);
        expect(calls).toEqual([]);
    });

    test("a response's cards replace the list, then onRender and the theme's onSuccess run", async () => {
        mockFetch(() => response({ total: 30, limit: 2, offset: 2, results: [{ id: 3, card: "<a>3</a>", card_business: "<a>3 excl</a>" }, { id: 4 }] }));
        const rendered = [];
        const succeeded = [];
        const c = archive({ cardField: "card_business", itemClass: "flex", onRender: (list) => rendered.push(list.id), onSuccess: (data) => succeeded.push(data.offset) });

        await c.goToPage(2);

        expect(document.getElementById("archive-list").innerHTML).toBe('<li class="flex"><a>3 excl</a></li><li class="flex"></li>');
        expect([rendered, succeeded]).toEqual([["archive-list"], [2]]);
    });
});
