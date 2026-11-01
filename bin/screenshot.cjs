/**
 * Screenshots of one rendered page; render:screenshot starts it. The page's HTML comes
 * from Gaffer's render (the browser gets it as the answer to its first request), the rest
 * (styles, scripts, images, ajax) from the site itself. Playwright is the theme's own
 * dev dependency, resolved from the theme directory.
 *
 * node screenshot.cjs <options as JSON>
 *   {theme, url, html, http, widths, full, steps, out, endpoint}
 * Prints one JSON line: {shots: [{width, file}], console: [...], failed: [...]} or {error}.
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
            page.on('response', (response) => {
                if (response.status() >= 400 && response.request().frame() === page.mainFrame() && response.url() !== options.url) {
                    failed.add(`${short(response.url())} (HTTP ${response.status()})`);
                }
            });

            await page.goto(options.url, { waitUntil: 'load' });
            await settle(page);
            for (const step of options.steps) {
                // The first visible match: themes often have a hidden mobile or desktop twin of an element.
                const target = page.locator(step.click ?? step.fill).filter({ visible: true }).first();
                try {
                    if (step.click !== undefined) await target.click({ timeout: 5000 });
                    else await target.fill(step.value, { timeout: 5000 });
                } catch (e) {
                    throw new Error(`--${step.click !== undefined ? 'click' : 'fill'} "${step.click ?? step.fill}" at ${width}px: ${String(e.message).split('\n')[0].replace(/^locator\.\w+: /, '')} (no visible element matches, or it doesn't take this action)`);
                }
                await settle(page);
            }
            if (options.full) {
                // Lazy images below the fold load only when scrolled to: load them all.
                await page.evaluate(() => document.querySelectorAll('img[loading="lazy"]').forEach((img) => (img.loading = 'eager')));
                await settle(page);
            }

            const file = path.join(options.out, `${width}.png`);
            await page.screenshot({ path: file, fullPage: options.full, animations: 'disabled' });
            shots.push({ width, file });
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
