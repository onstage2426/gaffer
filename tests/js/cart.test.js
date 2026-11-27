import { beforeEach, describe, expect, test } from "bun:test";
import { cartBadge, cartCounts } from "../../resources/js/cart.js";

function clearCookies() {
    for (const c of document.cookie.split(";")) {
        const name = c.split("=")[0].trim();
        if (name) document.cookie = `${name}=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/`;
    }
}

beforeEach(clearCookies);

describe("cartCounts()", () => {
    test("reads both counts, null without the cookie", () => {
        expect(cartCounts("a=1; gaffer_cart=items%3D3.5%26lines%3D2; b=2")).toEqual({ items: 3.5, lines: 2 });
        expect(cartCounts("gaffer_cart=items=1&lines=1")).toEqual({ items: 1, lines: 1 });
        expect(cartCounts("woocommerce_items_in_cart=1")).toBeNull();
    });
});

describe("cartBadge()", () => {
    test("counts what it's asked, and reads again after an htmx request and a named event", () => {
        document.cookie = "gaffer_cart=items%3D3%26lines%3D2; path=/";
        const badge = cartBadge("/ajax.php?action=GafferCartCount", "lines", ["cart_updated"]);
        badge.init();
        expect(badge.count).toBe(2);

        document.cookie = "gaffer_cart=items%3D5%26lines%3D4; path=/";
        document.dispatchEvent(new Event("htmx:after:request"));
        expect(badge.count).toBe(4);

        document.cookie = "gaffer_cart=items%3D6%26lines%3D5; path=/";
        window.dispatchEvent(new Event("cart_updated"));
        expect(badge.count).toBe(5);
    });

    test("asks the action once for a cart from before the cookie", async () => {
        document.cookie = "woocommerce_items_in_cart=1; path=/";
        const calls = [];
        globalThis.fetch = (url) => {
            calls.push(url);
            document.cookie = "gaffer_cart=items%3D2%26lines%3D1; path=/";
            return Promise.resolve(new Response("{}"));
        };
        const badge = cartBadge("/ajax.php?action=GafferCartCount");
        badge.init();
        await new Promise((r) => setTimeout(r, 0));
        expect(calls).toEqual(["/ajax.php?action=GafferCartCount"]);
        expect(badge.count).toBe(2);
    });

    test("an empty cart without a cookie is 0, without a request", () => {
        globalThis.fetch = () => { throw new Error("no request expected"); };
        const badge = cartBadge("/x");
        badge.init();
        expect(badge.count).toBe(0);
    });
});
