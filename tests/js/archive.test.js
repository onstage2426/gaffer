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
    test("viewUrl is the current search on its first page in another layout", () => {
        history.replaceState(null, "", "/winkel/?filter%5Bon_sale%5D=yes&offset=24&show=all");
        const c = archive();

        expect(c.viewUrl("list")).toBe("/winkel/?filter%5Bon_sale%5D=yes&show=all&view=list");
        history.replaceState(null, "", "/winkel/?view=list&q=tegel");
        expect(c.viewUrl("")).toBe("/winkel/?q=tegel");
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
