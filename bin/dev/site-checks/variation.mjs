// Maintainer tool: see README.md in this folder.
import { createRequire } from "node:module";
const [theme, url, mode] = process.argv.slice(2);
const { chromium } = createRequire(theme + "/package.json")("playwright");
const browser = await chromium.launch();
const page = await browser.newPage({ ignoreHTTPSErrors: true });
const errors = [];
page.on("pageerror", (e) => errors.push(String(e)));
await page.goto(url, { waitUntil: "load" });
await page.waitForTimeout(1000);
if (mode === "select") {
    // blueprint: <form hx-get hx-trigger="change[variationSelected()]">: choose a value in every select
    const selects = await page.locator("form.variation-selector select").all();
    const radios = await page.locator("form.variation-selector input[type=radio]").all();
    console.log("selects", selects.length, "radios", radios.length);
    const resp = page.waitForResponse((r) => r.url().includes("/product/") && r.request().headers()["hx-request"] === "true", { timeout: 15000 });
    if (selects.length) {
        for (const s of selects) { const opts = await s.locator("option").all(); await s.selectOption({ index: opts.length - 1 }); }
    } else {
        for (const r of radios) { const id = await r.getAttribute('id'); await page.locator(`label[for='${id}']`).click(); }
    }
    const r = await resp;
    await page.waitForTimeout(800);
    console.log("swap request", r.status(), "url now", page.url(), "#productBody children", await page.locator("#productBody > div").count());
} else {
    // link: a link with hx-get + hx-select, and card size forms (hx-get, closest article)
    const link = page.locator("#productBody a[hx-get]").last();
    const resp = page.waitForResponse((r) => r.request().headers()["hx-request"] === "true", { timeout: 15000 });
    await link.click(); const r = await resp; await page.waitForTimeout(800);
    console.log("variation link:", r.status(), "url now", page.url(), "current:", await page.locator("#productBody a[aria-current=true]").count());
    await page.goto(url.replace(/product\/.*/, "winkel/"), { waitUntil: "load" }); await page.waitForTimeout(800);
    const card = page.locator("article form[hx-get*='ProductCard']").nth(1);
    if (await card.count()) {
        const resp2 = page.waitForResponse((r) => r.url().includes("ProductCard"), { timeout: 15000 });
        await card.locator("button").click(); const r2 = await resp2; await page.waitForTimeout(600);
        console.log("card size:", r2.status(), "query has product:", new URL(r2.url()).searchParams.has("product"));
    }
}
console.log("page errors:", errors.filter((e) => !/Turnstile/.test(e)));
await browser.close();
