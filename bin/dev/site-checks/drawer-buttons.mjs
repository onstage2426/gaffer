// Maintainer tool: see README.md in this folder.
import { createRequire } from "node:module";
const [theme, base] = process.argv.slice(2);
const { chromium } = createRequire(theme + "/package.json")("playwright");
const browser = await chromium.launch();
const page = await browser.newPage({ ignoreHTTPSErrors: true });
const errors = [];
page.on("pageerror", (e) => errors.push(String(e)));
await page.goto(base + "/winkel/", { waitUntil: "load" });
await page.waitForTimeout(1000);
await page.locator("form[hx-post*='CartAdd'] button").first().click();
await page.waitForSelector("#drawerBody form[hx-post*='CartUpdate']", { timeout: 15000 });
const qty = async () => (await page.locator("#drawerBody form[hx-post*='CartUpdate'] span.min-w-7").first().innerText().catch(() => "?")).trim();
console.log("after add:", await qty());
const plus = page.locator("#drawerBody form[hx-post*='CartUpdate'] button[name=quantity]").last();
const upd = page.waitForResponse((r) => r.url().includes("CartUpdate"));
await plus.click(); await upd; await page.waitForTimeout(500);
console.log("after +:", await qty());
const minus = page.locator("#drawerBody form[hx-post*='CartUpdate'] button[name=quantity]").first();
const upd2 = page.waitForResponse((r) => r.url().includes("CartUpdate"));
await minus.click(); await upd2; await page.waitForTimeout(500);
console.log("after −:", await qty());
const remove = page.locator("#drawerBody button[hx-post*='CartRemove']").first();
if (await remove.count()) {
    const rem = page.waitForResponse((r) => r.url().includes("CartRemove"));
    await remove.click(); await rem; await page.waitForTimeout(500);
    console.log("after remove (type=button, own hx-target): items left", await page.locator("#drawerBody form[hx-post*='CartUpdate']").count(), "drawer text:", (await page.locator("#drawerBody").innerText()).slice(0, 60).replace(/\s+/g, " "));
}
console.log("page errors:", errors);
await browser.close();
