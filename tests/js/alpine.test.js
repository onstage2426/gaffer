import { beforeEach, describe, expect, test } from "bun:test";
import { deferred, mockFetch, response, tick } from "./fetch.js";

const F = window.GafferSearch;

// An Alpine component without Alpine: the factory's object with the two magics it uses.
function component(factory, html = '<input name="q">') {
    document.body.innerHTML = `<form method="get" action="https://shop.test/search.php"><input type="hidden" name="token" value="T">
        <input type="hidden" name="offset" value="0">${html}</form>`;
    const form = document.querySelector("form");
    const c = { ...factory, $root: form, $nextTick: async () => { form.elements.offset.value = String(c.offset); } };
    c.init();
    return c;
}

beforeEach(() => history.replaceState(null, "", "/winkel/"));

describe("createAlpineSearch", () => {
    test("starts from the server-rendered response without a request", () => {
        const calls = mockFetch();
        const c = component(F.createAlpineSearch({ initial: { query: "jurk", total: 40, offset: 16, limit: 8, results: [{ id: 1 }], facets: { brand: { A: 1 } }, facetStats: {} } }));

        expect([c.query, c.searchedQuery, c.total, c.offset, c.limit, c.results.length]).toEqual(["jurk", "jurk", 40, 16, 8, 1]);
        expect([c.currentPage(), c.pageNumbers()]).toEqual([3, [1, 2, 3, 4, 5]]);
        expect(calls).toEqual([]);
    });

    test("goToPage keeps its offset; any other run starts at page 1", async () => {
        const calls = mockFetch((url) => response({ total: 50, limit: 10, offset: Number(new URL(url).searchParams.get("offset")) }));
        const c = component(F.createAlpineSearch({ initial: { total: 50, offset: 0, limit: 10, results: [] } }));

        await c.goToPage(3);
        expect([calls.at(-1).includes("offset=20"), c.offset, c.currentPage()]).toEqual([true, 20, 3]);

        c.query = "jurk";
        await c.run();
        expect([calls.at(-1).includes("offset=0"), c.offset]).toEqual([true, 0]);
    });

    test("below minQueryLength it resets instead of searching", async () => {
        const calls = mockFetch();
        const c = component(F.createAlpineSearch({ minQueryLength: 1, initial: { query: "x", total: 3, results: [{ id: 1 }] } }));
        c.query = " ";

        expect(await c.run()).toBeUndefined();
        expect([c.results, c.total, c.searchedQuery, calls.length]).toEqual([[], 0, "", 0]);
    });

    test("run never rejects: a failure sets error, the next success clears it", async () => {
        let fail = true;
        mockFetch(() => (fail ? new Response(JSON.stringify({ error: "old", code: "invalid_token" }), { status: 400 }) : response({ total: 1 })));
        const c = component(F.createAlpineSearch());

        c.query = "a";
        expect(await c.run()).toBeNull();
        expect([c.error.code, c.loading]).toEqual(["invalid_token", false]);

        fail = false;
        c.query = "b";
        await c.run();
        expect([c.error, c.total]).toEqual([null, 1]);
    });

    test("loading belongs to the newest call", async () => {
        const slow = deferred();
        mockFetch((url) => (url.includes("q=old") ? slow.promise : response()));
        const c = component(F.createAlpineSearch());

        c.query = "old";
        const old = c.run();
        await tick();
        c.query = "new";
        const newer = c.run();
        await tick();
        slow.resolve(response());
        await old;
        await newer;
        expect(c.loading).toBe(false);
    });

    test("hooks run around a real request only", async () => {
        mockFetch(() => response({ total: 7 }));
        const seen = [];
        const c = component(F.createAlpineSearch({
            minQueryLength: 1,
            onBeforeRun() { seen.push(`before:${this.query}`); },
            onSuccess(data) { seen.push(`success:${data.total}`); },
        }));
        await c.run();
        c.query = "a";
        await c.run();
        expect(seen).toEqual(["before:a", "success:7"]);
    });

    test("arrow-function hooks get the component as their last argument", async () => {
        mockFetch(() => response({ total: 3 }));
        const seen = [];
        const c = component(F.createAlpineSearch({
            onBeforeRun: (opts, comp) => seen.push(comp === c),
            onSuccess: (data, comp) => { comp.took = data.total; },
        }));
        await c.run();
        expect(seen).toEqual([true]);
        expect(c.took).toBe(3);
    });

    test("onSuccess follows back/forward too, which searches outside run()", async () => {
        mockFetch((url) => response({ total: url.includes("q=b") ? 2 : 1 }));
        const seen = [];
        const c = component(F.createAlpineSearch({ onSuccess(data) { seen.push(data.total); } }));
        c._form.dataset.gafferSearchHistory = "push";

        c.query = "a";
        c._form.elements.q.value = "a";
        await c.run();
        c._form.elements.q.value = "b";
        await c.run();
        history.replaceState(null, "", "/winkel/?q=a");
        window.dispatchEvent(new PopStateEvent("popstate"));
        await tick();

        expect(seen).toEqual([1, 2, 1]);
        expect(c.total).toBe(1);
    });
});

describe("createAlpineSearch's form", () => {
    function wrapped(options) {
        document.body.innerHTML = `<div id="archive"><form id="archive-form" method="get" action="https://shop.test/search.php">
            <input type="hidden" name="token" value="T"><input name="q"></form>
            <ul><li><form><input name="product" value="705"></form></li></ul></div>`;
        const root = document.getElementById("archive");
        const c = { ...F.createAlpineSearch(options), $root: root, $nextTick: async () => {} };
        c.init();
        return c;
    }

    test("the `form` option lets the component sit on a wrapper around the form and its results", async () => {
        const calls = mockFetch(() => response({ total: 4 }));
        const c = wrapped({ form: "#archive-form" });

        await c.run();
        expect(c._form.id).toBe("archive-form");
        expect(calls[0]).not.toContain("product");
        expect(c.total).toBe(4);
    });

    test("takes an element too", () => {
        document.body.innerHTML = '<form id="f" method="get"></form><div id="w"></div>';
        const form = document.getElementById("f");
        const c = { ...F.createAlpineSearch({ form }), $root: document.getElementById("w"), $nextTick: async () => {} };
        c.init();
        expect(c._form).toBe(form);
    });

    test("fails at init when the component isn't on a form and has no `form` option", () => {
        expect(() => wrapped()).toThrow(/must sit on the search <form>, or be given it with the `form` option/);
        expect(() => wrapped({ form: "#nope" })).toThrow(/found no element, not a <form>/);
        expect(() => wrapped({ form: "ul" })).toThrow(/found a <ul>, not a <form>/);
    });
});

describe("createFilterForm", () => {
    const fields = [{ field: "brand", multi: true }, { field: "color" }];

    test("seeds filters from the URL and offers selected values before any response", () => {
        history.replaceState(null, "", "/winkel/?filter[brand][]=a&filter[color]=rood");
        const c = component(F.createFilterForm(fields));

        expect(c.filters).toEqual({ brand: ["a"], color: "rood" });
        expect(c.options("brand")).toEqual([{ value: "a", label: "a", count: 0 }]);
        expect(c.activeFilters().map((chip) => chip.label)).toEqual(["a", "rood"]);
    });

    test("a single field also reads a one-item list (filter[color][]=…, as forms and old links write it)", () => {
        history.replaceState(null, "", "/winkel/?filter[color][]=rood");
        expect(component(F.createFilterForm(fields)).filters.color).toBe("rood");
    });

    test("options and chips show the names from the responses' labels, kept across responses", async () => {
        mockFetch(() => response({ facets: { brand: { b: 2 } }, labels: { brand: { b: "Bravo" } } }));
        history.replaceState(null, "", "/winkel/?filter[brand][]=a");
        const c = component(F.createFilterForm(fields, { initial: { facets: { brand: { a: 1 } }, labels: { brand: { a: "Alpha" } } } }));

        expect(c.options("brand")).toEqual([{ value: "a", label: "Alpha", count: 1 }]);
        await c.run();
        expect(c.options("brand")).toEqual([{ value: "b", label: "Bravo", count: 2 }, { value: "a", label: "Alpha", count: 0 }]);
        expect(c.activeFilters()).toEqual([{ field: "brand", value: "a", label: "Alpha" }]);
    });

    test("removing filters searches again from page 1", async () => {
        const calls = mockFetch();
        history.replaceState(null, "", "/winkel/?filter[brand][]=A&filter[brand][]=B");
        const c = component(F.createFilterForm(fields, { initial: { offset: 30, limit: 10, total: 100 } }));

        c.removeFilter("brand", "A");
        await tick();
        expect([c.filters.brand, c.offset, calls.length]).toEqual([["B"], 0, 1]);

        c.clearAllFilters();
        await tick();
        expect(c.filters).toEqual({ brand: [], color: "" });
    });
});

describe("createFilterForm field types", () => {
    const fields = [
        { field: "price", type: "range" },
        { field: "on_sale", type: "toggle", label: "Sale" },
        { field: "stock_status", type: "toggle", value: "instock" },
        { field: "brand", type: "multi" },
    ];

    test("ranges, toggles and the sort come from the URL, also on back/forward", () => {
        history.replaceState(null, "", "/winkel/?filter[price][min]=10&filter[on_sale]=yes&filter[stock_status]=outofstock&sort[]=price:asc&sort[]=title_sort:asc");
        const c = component(F.createFilterForm(fields));

        expect(c.filters).toEqual({ price: { min: "10", max: "" }, on_sale: true, stock_status: false, brand: [] });
        expect(c.sort).toBe("price:asc");

        c._form.dispatchEvent(new CustomEvent("gaffer-search:popstate", { detail: { params: new URLSearchParams("filter[stock_status]=instock") } }));
        expect(c.filters).toEqual({ price: { min: "", max: "" }, on_sale: false, stock_status: true, brand: [] });
        expect(c.sort).toBe("");
    });

    test("chips for ranges and toggles; removing one clears it to its empty state", async () => {
        mockFetch();
        history.replaceState(null, "", "/winkel/?filter[price][max]=50&filter[on_sale]=yes&filter[stock_status]=instock");
        const c = component(F.createFilterForm(fields, { initial: { labels: { stock_status: { instock: "Op voorraad" } } } }));

        expect(c.activeFilters()).toEqual([
            { field: "price", value: { min: "", max: "50" }, label: "–50" },
            { field: "on_sale", value: "yes", label: "Sale" },
            { field: "stock_status", value: "instock", label: "Op voorraad" },
        ]);

        c.removeFilter("price");
        c.removeFilter("on_sale", "yes");
        await tick();
        expect([c.filters.price, c.filters.on_sale]).toEqual([{ min: "", max: "" }, false]);

        c.clearAllFilters();
        await tick();
        expect(c.filters).toEqual({ price: { min: "", max: "" }, on_sale: false, stock_status: false, brand: [] });
        expect(c.activeFilters()).toEqual([]);
    });

    test("the response's limit is kept", () => {
        const c = component(F.createFilterForm(fields, { initial: { limit: 48 } }));
        expect([c.view, c.limit]).toEqual([undefined, 48]);
    });
});
