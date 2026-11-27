/**
 * Gaffer's cart badge: the number of items (or lines) in the visitor's cart, from the `gaffer_cart`
 * cookie Gaffer keeps equal to the cart (on every change, before an ajax answer is sent), so a page
 * from a cache shows the right count without a request. Import it once (through Vite, with the
 * "@gaffer" alias) and register it with Alpine:
 *
 *   import { cartBadge } from "@gaffer/cart.js";
 *   Alpine.data("cartBadge", cartBadge);
 *
 *   <span x-data="cartBadge('{{ ajax_url('GafferCartCount') }}')" x-text="count" x-show="count > 0"></span>
 *
 * The second argument is what it counts: "items" (quantities, WooCommerce's own count; the default)
 * or "lines". It reads the cookie again after every htmx request, when the page comes back from the
 * browser's history, and on the window events named in the third argument (a theme's own
 * non-htmx cart requests). A cart from before the cookie existed is asked once (GafferCartCount).
 */

/**
 * The counts in the gaffer_cart cookie, or null when there's none.
 *
 * @param {string} cookie
 * @returns {{items: number, lines: number}|null}
 */
export function cartCounts(cookie = document.cookie) {
    const match = cookie.match(/(?:^|;\s*)gaffer_cart=([^;]*)/);
    if (!match) return null;
    const values = new URLSearchParams(decodeURIComponent(match[1]));
    return { items: Number(values.get("items") ?? 0), lines: Number(values.get("lines") ?? 0) };
}

/**
 * The Alpine component.
 *
 * @param {string} url the GafferCartCount action's URL (ajax_url('GafferCartCount'))
 * @param {"items"|"lines"} kind
 * @param {string[]} events window events after which the cookie is read again
 */
export function cartBadge(url, kind = "items", events = []) {
    return {
        count: 0,

        init() {
            const read = () => {
                const counts = cartCounts();
                this.count = counts ? counts[kind] : 0;
                return counts !== null;
            };
            // No cookie but WooCommerce's says there's a cart: one from before the cookie existed.
            if (!read() && document.cookie.includes("woocommerce_items_in_cart=1")) {
                fetch(url, { credentials: "same-origin" }).then(read, () => {});
            }
            document.addEventListener("htmx:after:request", read);
            window.addEventListener("pageshow", read);
            for (const name of events) window.addEventListener(name, read);
        },
    };
}
