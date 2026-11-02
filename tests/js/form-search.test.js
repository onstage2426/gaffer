import { beforeEach, describe, expect, test } from "bun:test";
import { mockFetch, response, tick } from "./fetch.js";
import { search } from "../../resources/js/search/form-search.js";


function form(html, attributes = 'data-gaffer-search-history="push"') {
    document.body.innerHTML = `<form method="get" action="https://shop.test/search.php" ${attributes}>
        <input type="hidden" name="token" value="T">${html}</form>`;
    return document.querySelector("form");
}

beforeEach(() => {
    history.replaceState(null, "", "/winkel/");
});

describe("search(form), under createAlpineSearch()", () => {
    test("sends the form's fields, without empty ones, to the form's action", async () => {
        const calls = mockFetch();
        const f = form(`<input name="q" value="jurk"><select name="sort[]"><option value="" selected></option></select>
            <input type="checkbox" name="filter[brand][]" value="A" checked><input type="checkbox" name="filter[brand][]" value="B" checked>`);

        await search(f);
        expect(calls).toEqual(["https://shop.test/search.php?token=T&q=jurk&filter%5Bbrand%5D%5B%5D=A&filter%5Bbrand%5D%5B%5D=B"]);
    });

    test("refuses a POST form", () => {
        document.body.innerHTML = '<form method="post"></form>';
        expect(() => search(document.querySelector("form"))).toThrow(/method="get"/);
    });

    test("an identical repeat request resolves null without a request or event", async () => {
        const calls = mockFetch();
        const f = form('<input name="q" value="jurk">');
        let events = 0;
        f.addEventListener("gaffer-search:results", () => events++);

        expect(await search(f)).not.toBeNull();
        expect(await search(f)).toBeNull();
        expect([calls.length, events]).toEqual([1, 1]);
    });

    test("dispatches gaffer-search:error and rejects on failure", async () => {
        mockFetch(() => new Response(JSON.stringify({ error: "x", code: "index_not_built" }), { status: 503 }));
        const f = form('<input name="q" value="a">');
        let detail;
        f.addEventListener("gaffer-search:error", (e) => { detail = e.detail; });

        const err = await search(f).catch((e) => e);
        expect([err.code, detail.code]).toEqual(["index_not_built", "index_not_built"]);
    });
});

describe("data-gaffer-search-history", () => {
    test("push writes the address bar after the response, without the token", async () => {
        mockFetch();
        const f = form('<input name="q" value="jurk">');
        const before = history.length;

        await search(f);
        expect(location.pathname + location.search).toBe("/winkel/?q=jurk");
        expect(history.length).toBe(before + 1);

        f.elements.q.value = "jurken";
        await search(f, { commit: false });
        expect(location.search).toBe("?q=jurken");
        expect(history.length).toBe(before + 1, "a live preview replaces, it doesn't push");
    });

    test("ignored fields and fields on their default stay out of the URL", async () => {
        mockFetch();
        const f = form(`<input name="q" value="jurk"><input type="hidden" name="filter[category]" value="Jurken" data-gaffer-search-history="ignore">
            <input type="hidden" name="offset" value="0" data-gaffer-search-default="0">`);

        await search(f);
        expect(location.search).toBe("?q=jurk");
        f.elements.offset.value = "24";
        await search(f);
        expect(location.search).toBe("?q=jurk&offset=24");
    });

    test("other params in the address bar are kept", async () => {
        mockFetch();
        history.replaceState(null, "", "/winkel/?utm_source=mail");
        await search(form('<input name="q" value="jurk">'));
        expect(location.search).toBe("?utm_source=mail&q=jurk");
    });

    test("back restores the form, announces it and searches again", async () => {
        const calls = mockFetch();
        const f = form(`<input name="q" value="a"><input type="checkbox" name="filter[brand][]" value="A">
            <input type="hidden" name="offset" value="0" data-gaffer-search-default="0">`);
        await search(f);
        f.elements.q.value = "b";
        f.elements["filter[brand][]"].checked = true;
        f.elements.offset.value = "24";
        await search(f);

        let restored;
        f.addEventListener("gaffer-search:popstate", (e) => { restored = e.detail.params.toString(); });
        history.replaceState(null, "", "/winkel/?q=a");
        window.dispatchEvent(new PopStateEvent("popstate"));
        await tick();

        expect(restored).toBe("q=a");
        expect([f.elements.q.value, f.elements["filter[brand][]"].checked, f.elements.offset.value]).toEqual(["a", false, "0"]);
        expect(calls.at(-1)).toContain("q=a");
    });
});

test("response() helper sanity", async () => {
    expect((await response({ total: 3 }).json()).total).toBe(3);
});
