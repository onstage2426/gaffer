// Maintainer tool: see README.md in this folder.
// gaffer-search in the browser: the searchbar and the archive search with the endpoint and token
// from {{ search.attrs() }} (or the older hand-written ones), and the token never reaches the
// address bar. node search.mjs <theme dir> <base url> [archive path] [query]
import { createRequire } from "node:module";
const [theme, base, archive = "/winkel/", query = "ma"] = process.argv.slice(2);
const { chromium } = createRequire(theme + "/package.json")("playwright");
const browser = await chromium.launch();
const results = [];
const check = async (name, fn) => {
    const page = await browser.newPage({ ignoreHTTPSErrors: true });
    const errors = [];
    page.on("console", (m) => m.type() === "error" && errors.push(m.text()));
    page.on("pageerror", (e) => errors.push(String(e)));
    try {
        const note = await fn(page);
        const real = errors.filter((e) => !/turnstile|challenges\.cloudflare|%c|favicon|data:,/i.test(e));
        results.push({ name, ok: real.length === 0 && !/^FAIL/.test(note), note, errors: real });
    } catch (e) {
        results.push({ name, ok: false, note: String(e).slice(0, 300), errors });
    }
    await page.close();
};
const searchRequest = (page) => page.waitForResponse((r) => /[?&]token=/.test(r.url()), { timeout: 15000 });

await check("the searchbar searches the endpoint with its token", async (page) => {
    await page.goto(base + "/", { waitUntil: "load" }).then(() => page.waitForTimeout(1000));
    const form = page.locator("form:has(input[name=q])").first();
    const how = (await form.getAttribute("data-gaffer-search-token")) !== null ? "attrs()" : "by hand";
    const input = form.locator("input[name=q]");
    if (!(await input.isVisible())) await page.locator("[x-ref=searchToggle], [aria-controls*=search]").first().click().catch(() => {});
    const answer = searchRequest(page);
    await input.click();
    await input.pressSequentially(query, { delay: 80 }); // typing: the searchbar listens to input events
    const response = await answer;
    const data = await response.json();
    return `${how}; HTTP ${response.status()}, ${data.total ?? "?"} found; address bar: ${page.url()}${page.url().includes("token=") ? " FAIL token in the address bar" : ""}`;
});

await check("the archive searches on a filter or sort change and keeps the token out of the address bar", async (page) => {
    await page.goto(base + archive, { waitUntil: "load" }).then(() => page.waitForTimeout(1000));
    const choices = "input[type=checkbox], input[type=radio]:not(:checked)";
    const form = page.locator("form[data-gaffer-search-history=push]").filter({ has: page.locator(choices) }).first();
    if ((await form.count()) === 0) return "no archive form with filters or sorts on " + archive;
    const how = (await form.getAttribute("data-gaffer-search-token")) !== null ? "attrs()" : "by hand";
    const box = form.locator(choices).first();
    const answer = searchRequest(page);
    await box.evaluate((el) => { el.checked = el.type === "radio" || !el.checked; el.dispatchEvent(new Event("change", { bubbles: true })); });
    const response = await answer;
    await page.waitForTimeout(500);
    return `${how}; HTTP ${response.status()}; address bar: ${page.url()}${page.url().includes("token=") ? " FAIL token in the address bar" : ""}`;
});

await browser.close();
for (const r of results) console.log(`${r.ok ? "ok  " : "FAIL"} ${r.name}: ${r.note ?? ""}${r.errors.length ? "\n     console: " + r.errors.slice(0, 3).join(" | ") : ""}`);
