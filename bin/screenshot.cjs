/**
 * Screenshots of one rendered page; render:screenshot starts it. The page's HTML comes
 * from Gaffer's render (the browser gets it as the answer to its first request), the rest
 * (styles, scripts, images, ajax) from the site itself. Playwright is the theme's own
 * dev dependency, resolved from the theme directory.
 *
 * node screenshot.cjs <options as JSON>
 *   {theme, url, html, http, widths, full, slice, steps, cookies, storage, out, endpoint}
 * Prints one JSON line: {shots: [{width, file, files, height}], console: [...], failed: [...]} or {error}.
 * A full page comes in parts of `slice` pixels (files; file is the first): a model can't read a
 * 390x8000 image.
 *
 * @internal
 */

'use strict';

const { createRequire } = require('node:module');
const fs = require('node:fs');
const path = require('node:path');

const done = (result) => {
    process.stdout.write(JSON.stringify(result) + '\n');
};

async function main() {
    const options = JSON.parse(process.argv[2] || '{}');
    let chromium;
    try {
        ({ chromium } = createRequire(path.join(options.theme, 'package.json'))('playwright'));
    } catch {
        return done({ error: 'Playwright is not installed in the theme: run `npm install --save-dev playwright` and `npx playwright install chromium`.' });
    }

    let browser;
    try {
        browser = options.endpoint ? await chromium.connect(options.endpoint) : await chromium.launch();
    } catch (e) {
        return done({ error: `The browser didn't start: ${String(e.message).split('\n').slice(0, 12).join('\n')}` });
    }

    const html = fs.readFileSync(options.html);
    const messages = new Set();
    const failed = new Set();
    const shots = [];
    try {
        for (const width of options.widths) {
            const context = await browser.newContext({ viewport: { width, height: width <= 480 ? 844 : 900 } });
            // An ajax:call session's cookies (render:screenshot --session): its cart in ajax answers too.
            const cookies = Object.entries(options.cookies ?? {}).map(([name, value]) => ({ name, value, url: new URL(options.url).origin }));
            if (cookies.length) await context.addCookies(cookies);
            // A returning visitor's stored state (localStorage), there before the page's scripts run, once per tab.
            if (Object.keys(options.storage ?? {}).length) {
                await context.addInitScript((items) => {
                    if (sessionStorage.getItem('gaffer-screenshot-storage')) return;
                    for (const [key, value] of Object.entries(items)) localStorage.setItem(key, value);
                    sessionStorage.setItem('gaffer-screenshot-storage', '1');
                }, options.storage);
            }
            const page = await context.newPage();
            let served = false;
            // Only the first request for the page itself is the render; links clicked later go to the site.
            await page.route('**/*', (route) => {
                const request = route.request();
                if (!served && request.isNavigationRequest() && request.frame() === page.mainFrame()) {
                    served = true;
                    return route.fulfill({ status: options.http, contentType: 'text/html; charset=utf-8', body: html });
                }
                return route.continue();
            });
            // The page's own frame only: embedded widgets (Turnstile, video players) log and fail requests of their own.
            const own = new Set();
            page.on('request', (request) => {
                if (request.frame() === page.mainFrame()) own.add(request.url());
            });
            // Requests in flight, for settle(): Playwright's networkidle is reached once per page, not after each step.
            page.inflight = 0;
            page.on('request', () => page.inflight++);
            for (const event of ['requestfinished', 'requestfailed']) page.on(event, () => page.inflight--);
            page.on('console', (message) => {
                const source = message.location().url;
                // "Failed to load resource" repeats a failed request (listed in failed).
                if (message.type() === 'error' && (!source || own.has(source) || source === options.url) && !message.text().startsWith('Failed to load resource')) {
                    messages.add(message.text());
                }
            });
            page.on('pageerror', (error) => messages.add(String(error.message)));
            page.on('requestfailed', (request) => {
                if (request.frame() === page.mainFrame()) failed.add(`${short(request.url())} (${request.failure()?.errorText ?? 'failed'})`);
            });
            page.on('response', async (response) => {
                const request = response.request();
                if (response.status() < 400 || request.frame() !== page.mainFrame() || response.url() === options.url) return;
                // An ajax answer's body usually says why (an error message, a JSON code).
                const body = ['fetch', 'xhr'].includes(request.resourceType()) ? await response.text().catch(() => '') : '';
                const why = body.replace(/\s+/g, ' ').trim().slice(0, 200);
                failed.add(`${short(response.url())} (HTTP ${response.status()})${why ? `: ${why}` : ''}`);
            });

            await page.goto(options.url, { waitUntil: 'load' });
            await settle(page);
            for (const step of options.steps) {
                // The first visible match: themes often have a hidden mobile or desktop twin of an element.
                const action = step.click !== undefined ? 'click' : step.hover !== undefined ? 'hover' : 'fill';
                const target = page.locator(step[action]).filter({ visible: true }).first();
                try {
                    if (action === 'click') await target.click({ timeout: 5000 });
                    else if (action === 'hover') await target.hover({ timeout: 5000 });
                    else await target.fill(step.value, { timeout: 5000 });
                } catch (e) {
                    throw new Error(`--${action} "${step[action]}" at ${width}px: ${String(e.message).split('\n')[0].replace(/^locator\.\w+: /, '')} (no visible element matches, or it doesn't take this action)`);
                }
                await settle(page);
            }
            if (!options.full) {
                const file = path.join(options.out, `${width}.png`);
                await page.screenshot({ path: file, animations: 'disabled' });
                shots.push({ width, file, files: [file], height: await page.evaluate(() => document.documentElement.scrollHeight) });
                await context.close();
                continue;
            }

            // Lazy images and scroll-triggered content (x-intersect, sliders) load only when scrolled
            // to: scroll through the page like a visitor, then back to the top.
            await page.evaluate(() => document.querySelectorAll('img[loading="lazy"]').forEach((img) => (img.loading = 'eager')));
            const viewport = page.viewportSize().height;
            for (let y = 0; y < (await page.evaluate(() => document.documentElement.scrollHeight)); y += viewport) {
                await page.evaluate((top) => window.scrollTo(0, top), y);
                await page.waitForTimeout(150);
            }
            await page.evaluate(() => window.scrollTo(0, 0));
            await settle(page);

            const height = await page.evaluate(() => document.documentElement.scrollHeight);
            const slice = options.slice > 0 ? options.slice : height;
            const files = [];
            for (let y = 0, part = 1; y < height; y += slice, part++) {
                const file = path.join(options.out, slice >= height ? `${width}.png` : `${width}-${part}.png`);
                await page.screenshot({ path: file, fullPage: true, animations: 'disabled', clip: { x: 0, y, width, height: Math.min(slice, height - y) } });
                files.push(file);
            }
            shots.push({ width, file: files[0], files, height });
            await context.close();
        }
    } catch (e) {
        return done({ error: String(e.message).split('\n')[0], shots, console: [...messages], failed: [...failed] });
    } finally {
        await browser.close();
    }

    done({ shots, console: [...messages], failed: [...failed] });
}

/** A URL without its query string, at most 150 characters: tokens make them long. */
function short(url) {
    const bare = url.split('?')[0];
    return bare.length > 150 ? bare.slice(0, 150) + '…' : bare;
}

/** Until no request has been in flight for 500 ms (htmx, Alpine, fonts, transitions), at most 5 s: some pages never are. */
async function settle(page) {
    const until = Date.now() + 5000;
    let quiet = 0;
    while (quiet < 500 && Date.now() < until) {
        await page.waitForTimeout(100);
        quiet = page.inflight > 0 ? 0 : quiet + 100;
    }
}

main().catch((e) => done({ error: String(e.message) }));
