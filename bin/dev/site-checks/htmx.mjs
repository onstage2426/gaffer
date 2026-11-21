// Maintainer tool: see README.md in this folder.
// Browser checks of a theme's htmx interactions. node htmx-check.mjs <theme dir> <base url>
import { createRequire } from "node:module";
const [theme, base, formPage = "/contact/"] = process.argv.slice(2);
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
        const real = errors.filter((e) => !/turnstile|challenges\.cloudflare|cloudflarecaptcha|%c|favicon|data:,|401|403|404/i.test(e));
        results.push({ name, ok: real.length === 0, note, errors: real });
    } catch (e) {
        results.push({ name, ok: false, note: String(e).slice(0, 300), errors });
    }
    await page.close();
};
const htmxVersion = async (page) => page.evaluate(() => window.htmx?.version);

await check("add to cart from the shop opens the drawer with the product", async (page) => {
    await page.goto(base + "/winkel/", { waitUntil: "load" }).then(() => page.waitForTimeout(1200));
    const v = await htmxVersion(page);
    const form = page.locator("form[hx-post*='CartAdd']").first();
    await form.locator("button").first().click();
    await page.waitForFunction(() => document.querySelector("#drawerBody form[hx-post*='CartUpdate'], #drawerBody [hx-post*='CartRemove']"), null, { timeout: 15000 });
    return `htmx ${v}; drawer has items`;
});

await check("pagination swaps the archive and pushes the URL", async (page) => {
    await page.goto(base + "/winkel/", { waitUntil: "load" }).then(() => page.waitForTimeout(1200));
    const next = page.locator("a[hx-get][hx-push-url]").last();
    if ((await next.count()) === 0) return "no pagination on this page";
    const href = await next.getAttribute("href");
    await next.click();
    await page.waitForURL((u) => u.href.includes(new URL(href, base).pathname), { timeout: 15000 });
    await page.waitForSelector("#archiveContainer");
    return `now at ${page.url()}, container present: ${await page.locator("#archiveContainer").count()}`;
});

await check("per-page select swaps the archive", async (page) => {
    await page.goto(base + "/winkel/", { waitUntil: "load" }).then(() => page.waitForTimeout(1200));
    const select = page.locator("#formPerPage select");
    if ((await select.count()) === 0) return "no per-page form";
    await select.selectOption("24");
    await page.waitForURL(/per_page=24/, { timeout: 15000 });
    return page.url();
});

await check("a Gaffer form posts and is replaced by its answer", async (page) => {
    await page.goto(base + formPage, { waitUntil: "load" }).then(() => page.waitForTimeout(1200));
    const form = page.locator("div[id^='form-'] form").first();
    if ((await form.count()) === 0) return "no Gaffer form on " + formPage;
    for (const input of await form.locator("input[type=text]:visible:not([name=website]), input[type=email]:visible, input[type=tel]:visible, textarea:visible").all()) {
        await input.fill((await input.getAttribute("type")) === "email" ? "test@gmail.com" : "Playwright");
    }
    for (const box of await form.locator("input[type=checkbox]:visible").all()) await box.check();
    const answer = page.waitForResponse((r) => r.url().includes("action=Form"), { timeout: 20000 });
    await form.locator("button[type=submit]").first().click();
    const response = await answer;
    await page.waitForTimeout(800);
    return `HTTP ${response.status()}, spam: ${response.headers()["x-gaffer-spam"] ?? "-"}, redirect: ${response.headers()["hx-redirect"] ?? "-"}, url ${page.url()}, wrapper now: ${(await page.locator("div[id^='form-']").first().innerText().catch(() => "")).slice(0, 80).replace(/\s+/g, " ")}`;
});

await check("an ajax error doesn't wipe the target (HX-Reswap: none)", async (page) => {
    await page.goto(base + formPage, { waitUntil: "load" }).then(() => page.waitForTimeout(1200));
    const before = await page.locator("div[id^='form-']").first().innerHTML();
    await page.evaluate(() => {
        const f = document.querySelector("div[id^='form-'] form");
        f.querySelector("[name=form_name]").value = "no-such-form";
        f.requestSubmit();
    });
    const res = await page.waitForResponse((r) => r.url().includes("action=Form"), { timeout: 10000 });
    await page.waitForTimeout(800);
    const status = res.status() + " reswap=" + res.headers()["hx-reswap"];
    const after = await page.locator("div[id^='form-']").first().innerHTML().catch(() => "");
    return `${status}, kept: ${after.length > 0 && after.length >= before.length - 50}`;
});

await browser.close();
for (const r of results) console.log(`${r.ok ? "ok  " : "FAIL"} ${r.name}: ${r.note ?? ""}${r.errors.length ? "\n     console: " + r.errors.slice(0, 3).join(" | ") : ""}`);
