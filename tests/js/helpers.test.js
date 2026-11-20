import { describe, expect, test } from "bun:test";
import { deferred, mockFetch, response } from "./fetch.js";

const F = window.GafferSearch;

describe("search.js", () => {
    test("exports what themes use", async () => {
        expect(Object.keys(await import("../../resources/js/search.js")).sort()).toEqual([
            "archive", "createAlpineSearch", "createCounter", "createDualRangeSlider", "createFilterForm", "formatCurrency", "getActiveFilters", "getFacetOptions",
            "getPageNumbers", "removeFilter",
        ]);
    });
});

describe("buildSearchUrl", () => {
    test("encode every filter shape the endpoint parses", () => {
        const url = F.buildSearchUrl({
            endpoint: "https://shop.test/search.php",
            token: "t.k",
            query: "rode jurk",
            offset: 24,
            sort: "price:asc",
            filters: { brand: ["A&B", "C"], price: { min: 10, max: 50 }, color: "Rood", visibility: { not: ["x"] }, cheap: { not: { max: 0 } } },
        });
        expect(url).toBe(
            "https://shop.test/search.php?q=rode%20jurk&token=t.k&offset=24&sort[]=price%3Aasc"
            + "&filter[brand][]=A%26B&filter[brand][]=C&filter[price][min]=10&filter[price][max]=50&filter[color]=Rood"
            + "&filter[visibility][not][]=x&filter[cheap][not][max]=0",
        );
    });

    test("encode all values and any/all/not groups with numbered entries", () => {
        const url = F.buildSearchUrl({
            endpoint: "/s",
            token: "t",
            filters: { tags: { all: ["sale", "new"] }, any: [{ on_sale: "1" }, { color: "wit", price: { max: 60 } }], not: { brand: "x", size: ["s", "m"] } },
        });
        expect(url).toBe(
            "/s?q=&token=t&filter[tags][all][]=sale&filter[tags][all][]=new"
            + "&filter[any][0][on_sale]=1&filter[any][1][color]=wit&filter[any][1][price][max]=60"
            + "&filter[not][brand]=x&filter[not][size][]=s&filter[not][size][]=m",
        );
    });

    test("leave out empty optional params", () => {
        expect(F.buildSearchUrl({ token: "t", endpoint: "/s" })).toBe("/s?q=&token=t");
    });
});

describe("facet and filter helpers", () => {
    test("getFacetOptions sorts by count, label or not at all", () => {
        const counts = { zeta: 1, alpha: 3, eclair: 2 };
        const labels = { zeta: "Zeta", alpha: "Alpha", eclair: "Éclair" };
        expect(F.getFacetOptions(counts, "count", labels)).toEqual([
            { value: "alpha", label: "Alpha", count: 3 },
            { value: "eclair", label: "Éclair", count: 2 },
            { value: "zeta", label: "Zeta", count: 1 },
        ]);
        expect(F.getFacetOptions({ b: 1, a: 1, c: 1 }, "alpha", { a: "Zulu", b: "Alpha" }).map((o) => o.label)).toEqual(["Alpha", "c", "Zulu"], "by label; no label: the value");
        expect(F.getFacetOptions(counts, "index").map((o) => o.value)).toEqual(["zeta", "alpha", "eclair"]);
        expect(F.getFacetOptions(undefined)).toEqual([]);
    });

    test("getActiveFilters flattens every shape into chips", () => {
        expect(F.getActiveFilters({ brand: ["A", "B"], price: { min: 20 }, color: "Rood", empty: "", none: null, visibility: { not: ["x"] } })).toEqual([
            { field: "brand", value: "A", label: "A" },
            { field: "brand", value: "B", label: "B" },
            { field: "price", value: { min: 20 }, label: "20–" },
            { field: "color", value: "Rood", label: "Rood" },
            { field: "visibility", value: "x", exclude: true, label: "≠ x" },
        ]);
        expect(F.getActiveFilters({ color: "rood" }, { color: (f, v) => v.toUpperCase() })[0].label).toBe("ROOD");
    });

    test("all values are chips, groups aren't; removing one of them keeps the rest", () => {
        const state = { tags: { all: ["sale", "new"] }, any: [{ color: "wit" }] };
        expect(F.getActiveFilters(state)).toEqual([
            { field: "tags", value: "sale", label: "sale" },
            { field: "tags", value: "new", label: "new" },
        ]);
        F.removeFilter(state, "tags", "sale");
        expect(state.tags).toEqual({ all: ["new"] });
        F.removeFilter(state, "tags");
        expect(state.tags).toEqual({});
    });

    test("removeFilter changes the state in place", () => {
        const state = { brand: ["A", "B"], price: { min: 1 }, color: "Rood", visibility: { not: ["x", "y"] } };
        F.removeFilter(state, "brand", "A");
        F.removeFilter(state, "price");
        F.removeFilter(state, "color");
        F.removeFilter(state, "visibility", "x");
        expect(state).toEqual({ brand: ["B"], price: {}, color: "", visibility: { not: ["y"] } });
        F.removeFilter(state, "brand");
        expect(state.brand).toEqual([]);
    });

    test("getPageNumbers keeps the first, last and neighbouring pages", () => {
        expect(F.getPageNumbers(200, 10, 40)).toEqual([1, "...", 4, 5, 6, "...", 20]);
        expect(F.getPageNumbers(30, 10, 0)).toEqual([1, 2, 3]);
        expect(F.getPageNumbers(0, 10, 0)).toEqual([1]);
        expect(F.getPageNumbers(50, 0, 0)).toEqual([1, 2, "...", 50]);
    });

    test("formatCurrency follows the locale and is empty for no value", () => {
        expect(F.formatCurrency(19.9)).toBe("€\u00a019,90");
        expect(F.formatCurrency(19.9, { locale: "en-US", currency: "USD" })).toBe("$19.90");
        expect(F.formatCurrency(null)).toBe("");
    });
});

describe("createSearcher", () => {
    test("a newer call cancels the older one, which resolves null", async () => {
        const slow = deferred();
        mockFetch((url) => (url.includes("old") ? slow.promise : response({ query: "new" })));
        const search = F.createSearcher();

        const old = search("/s?q=old");
        const newer = await search("/s?q=new");
        slow.resolve(response({ query: "old" }));

        expect(await old).toBeNull();
        expect(newer.query).toBe("new");
    });

    test("answers a repeated URL from its cache", async () => {
        const calls = mockFetch();
        const search = F.createSearcher();
        await search("/s?q=a");
        await search("/s?q=b");
        await search("/s?q=a");
        expect(calls).toEqual(["/s?q=a", "/s?q=b"]);
    });

    test("rejects with the endpoint's error code, and caches no failures", async () => {
        const calls = mockFetch(() => new Response(JSON.stringify({ error: "Bad token", code: "invalid_token" }), { status: 400 }));
        const search = F.createSearcher();

        const err = await search("/s").catch((e) => e);
        expect([err.name, err.status, err.code, err.message]).toEqual(["GafferSearchError", 400, "invalid_token", "Bad token"]);
        await search("/s").catch(() => {});
        expect(calls.length).toBe(2);
    });

    test("a response that isn't JSON is an error too", async () => {
        mockFetch(() => new Response("<html>", { status: 200 }));
        const err = await F.createSearcher()("/s").catch((e) => e);
        expect([err.name, err.status, err.code]).toEqual(["GafferSearchError", 200, null]);
    });
});

describe("createCounter", () => {
    const counter = () => F.createCounter({ endpoint: "https://shop.test/search.php", token: "t.k" });

    test("asks the endpoint with the filter and answers the total", async () => {
        const calls = mockFetch(() => response({ total: 9 }));
        expect(await counter()({ brand: ["volvo"] })).toBe(9);
        expect(calls).toEqual(["https://shop.test/search.php?q=&token=t.k&filter[brand][]=volvo"]);
    });

    test("a total of 0 is a count", async () => {
        mockFetch(() => response({ total: 0 }));
        expect(await counter()()).toBe(0);
    });

    test("every failure is null, never an exception", async () => {
        mockFetch(() => response({ error: "Invalid token.", code: "invalid_token" }, 400));
        expect(await counter()()).toBeNull();
        mockFetch(() => new Response("<html>", { status: 502 }));
        expect(await counter()()).toBeNull();
        mockFetch(() => Promise.reject(new TypeError("network")));
        expect(await counter()()).toBeNull();
    });

    test("a replaced call answers undefined, so a slow old answer can't win", async () => {
        const first = deferred();
        mockFetch((url) => (url.includes("old") ? first.promise : response({ total: 2 })));
        const count = counter();
        const old = count({ brand: "old" });
        expect(await count({ brand: "new" })).toBe(2);
        first.resolve(response({ total: 99 }));
        expect(await old).toBeUndefined();
    });

    test("the engine's warnings reach the console", async () => {
        mockFetch(() => response({ total: 14, warnings: ["filter on fuel ignored"] }));
        const warn = console.warn;
        const seen = [];
        console.warn = (message) => seen.push(message);
        await counter()({ fuel: "electric" });
        console.warn = warn;
        expect(seen).toEqual(["Gaffer Search: filter on fuel ignored"]);
    });
});
