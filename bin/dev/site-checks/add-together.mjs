// Maintainer tool: see README.md in this folder.
// A product page's "bought together" form (a theme's own CartAddTogether action): one click adds
// the product and its companions, and the drawer opens with all of them.
import { createRequire } from "node:module";
const [theme, base, path] = process.argv.slice(2);
const { chromium } = createRequire(theme + "/package.json")("playwright");
const browser = await chromium.launch();
const page = await browser.newPage({ ignoreHTTPSErrors: true });
const errors = [];
page.on("pageerror", (e) => errors.push(String(e)));
await page.goto(base + path, { waitUntil: "load" });
await page.waitForTimeout(1000);
const form = page.locator("form[hx-post*='CartAddTogether']").first();
if (!(await form.count())) {
    console.log("FAIL no CartAddTogether form on", path);
    process.exitCode = 1;
} else {
    const label = (await form.locator("button[type=submit]").innerText()).trim();
    const expected = Number((label.match(/\d+/) ?? ["0"])[0]);
    const answer = page.waitForResponse((r) => r.url().includes("CartAddTogether"));
    await form.locator("button[type=submit]").click();
    const response = await answer;
    await page.waitForSelector("#drawerBody form[hx-post*='CartUpdate']", { timeout: 15000 }).catch(() => {});
    await page.waitForTimeout(500);
    const items = await page.locator("#drawerBody form[hx-post*='CartUpdate']").count();
    const good = response.status() === 200 && items === expected;
    console.log((good ? "ok   " : "FAIL ") + `"${label}": HTTP ${response.status()}, drawer items ${items} (expected ${expected})`);
    if (!good) process.exitCode = 1;
}
console.log("page errors:", errors);
await browser.close();
