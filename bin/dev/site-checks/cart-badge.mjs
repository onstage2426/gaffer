// Maintainer tool: see README.md in this folder.
// The header's cart badge on Gaffer's gaffer_cart cookie (cartBadge from @gaffer/cart.js): it
// follows an add to the cart and a quantity change in the drawer, survives a reload, and a page
// whose cart has no cookie yet (a cart from before the cookie) asks GafferCartCount once.
import { createRequire } from "node:module";
const [theme, base, path = "/winkel/", badge = "#cart-count"] = process.argv.slice(2);
const { chromium } = createRequire(theme + "/package.json")("playwright");
const browser = await chromium.launch();
const context = await browser.newContext({ ignoreHTTPSErrors: true });
const page = await context.newPage();
const errors = [];
page.on("pageerror", (e) => errors.push(String(e)));
const count = async () => (await page.locator(badge).first().innerText().catch(() => "?")).trim();
let failed = false;
const check = (ok, what) => { console.log((ok ? "ok   " : "FAIL ") + what); failed ||= !ok; };

await page.goto(base + path, { waitUntil: "load" });
await page.waitForTimeout(800);
check(await count() === "0", `an empty cart shows 0 (badge: ${await count()})`);

const add = page.waitForResponse((r) => r.url().includes("CartAdd"));
await page.locator("form[hx-post*='CartAdd'] button").first().click();
await add; await page.waitForTimeout(500);
check(await count() === "1", `after adding: 1 (badge: ${await count()})`);

const select = page.locator("#drawerBody select").first();
if (await select.count()) {
    const update = page.waitForResponse((r) => r.url().includes("CartUpdate"));
    await select.selectOption("3");
    await update; await page.waitForTimeout(500);
    check(await count() === "3", `after a quantity of 3 in the drawer: 3 (badge: ${await count()})`);
}
const expected = await count();

await page.reload({ waitUntil: "load" });
await page.waitForTimeout(800);
check(await count() === expected, `after a reload: ${expected} from the cookie (badge: ${await count()})`);

// A cart from before the cookie, on a page from a cache: WooCommerce's cookies, no gaffer_cart,
// and the page served as stored HTML (a cache answers without running PHP, so nothing sets it).
const cached = await page.content();
// (Serialized after Alpine ran, so its x-for copies make Alpine errors there: those aren't counted.)
const realErrors = [...errors];
const kept = (await context.cookies()).filter((c) => c.name !== "gaffer_cart");
await context.clearCookies();
await context.addCookies(kept);
await page.route(base + path, (route) => route.fulfill({ status: 200, contentType: "text/html; charset=utf-8", body: cached }));
const asked = [];
const debug = process.env.DEBUG === "1";
page.on("request", (r) => { if (r.url().includes("GafferCartCount")) asked.push(r.url()); if (debug && r.resourceType() !== "image") console.log("  req", r.resourceType(), r.url().slice(0, 120)); });
if (debug) page.on("response", (r) => { const c = r.headers()["set-cookie"]; if (c && c.includes("gaffer_cart")) console.log("  set-cookie from", r.url().slice(0, 120)); });
await page.reload({ waitUntil: "load" });
await page.waitForTimeout(1500);
check(asked.length === 1 && await count() === expected, `without the cookie: GafferCartCount asked ${asked.length}× and the badge back at ${expected} (badge: ${await count()})`);

console.log("page errors:", realErrors);
await browser.close();
process.exit(failed ? 1 : 0);
