/**
 * Computed styles of pages, for matching a design: render:styles and render:compare start it.
 * A page is {url, html?, http?, cookies}: with html, the browser gets that file as the answer
 * to its first request (a render of this site) and everything else from the site; without, the
 * URL as it is (a design site, a file:// design).
 *
 * node design.cjs <options as JSON>
 *   styles:  {mode: 'styles', theme, page, selectors, children, width, endpoint}
 *            → {elements: [{selector, matches, items: [{tag, text, box, styles, before?, after?, children?}]}]}
 *   compare: {mode: 'compare', theme, design, site, within, width, endpoint}
 *            → {pairs: [{text, design, site, differences}], missing: [...], extra: [...], fonts}
 * Or {error}.
 *
 * @internal
 */

'use strict';

const { createRequire } = require('node:module');
const fs = require('node:fs');
const path = require('node:path');

const done = (result) => process.stdout.write(JSON.stringify(result) + '\n');

/** What a design specifies, besides geometry: compared against the browser's defaults for the tag. */
const PROPERTIES = [
    'display', 'position', 'font-family', 'font-size', 'font-weight', 'font-style', 'line-height', 'letter-spacing',
    'text-transform', 'text-align', 'text-decoration-line', 'color', 'background-color', 'background-image', 'opacity',
    'padding-top', 'padding-right', 'padding-bottom', 'padding-left', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left',
    'gap', 'row-gap', 'column-gap', 'flex-direction', 'align-items', 'justify-content', 'grid-template-columns',
    'border-top-width', 'border-top-color', 'border-bottom-width', 'border-bottom-color', 'border-left-width', 'border-right-width',
    'border-top-left-radius', 'border-top-right-radius', 'border-bottom-left-radius', 'border-bottom-right-radius',
    'box-shadow', 'max-width', 'object-fit', 'aspect-ratio', 'transform', 'filter', 'backdrop-filter',
];

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

    try {
        if (options.mode === 'styles') {
            const page = await open(browser, options.page, options.width);
            const elements = [];
            for (const selector of options.selectors) {
                elements.push({ selector, ...(await page.evaluate(styles, { selector, children: options.children, properties: PROPERTIES })) });
            }
            done({ url: options.page.url, width: options.width, elements });
        } else {
            const design = await open(browser, options.design, options.width);
            const site = await open(browser, options.site, options.width);
            const [a, b] = await Promise.all([design.evaluate(texts, options.within ?? null), site.evaluate(texts, options.within ?? null)]);
            if (a.error || b.error) return done({ error: (a.error ? `design: ${a.error}` : `site: ${b.error}`) });
            done({ width: options.width, ...compare(a.items, b.items) });
        }
    } catch (e) {
        done({ error: String(e.message).split('\n')[0] });
    } finally {
        await browser.close();
    }
}

/** A page at this width, loaded and quiet, scrolled through once (lazy and scroll-triggered content). */
async function open(browser, target, width) {
    const context = await browser.newContext({ viewport: { width, height: width <= 480 ? 844 : 900 } });
    if (target.cookies?.length) await context.addCookies(target.cookies);
    const page = await context.newPage();
    if (target.html) {
        const html = fs.readFileSync(target.html);
        let served = false;
        await page.route('**/*', (route) => {
            const request = route.request();
            if (!served && request.isNavigationRequest() && request.frame() === page.mainFrame()) {
                served = true;
                return route.fulfill({ status: target.http ?? 200, contentType: 'text/html; charset=utf-8', body: html });
            }
            return route.continue();
        });
    }
    const response = await page.goto(target.url, { waitUntil: 'load' });
    if (response && response.status() >= 400 && !target.html) {
        throw new Error(`${target.url} answered HTTP ${response.status()}${response.status() === 401 || response.status() === 403 ? ' (behind a login? DESIGN_COOKIE in the theme\'s .env)' : ''}`);
    }
    await page.evaluate(async () => {
        for (let y = 0; y < document.documentElement.scrollHeight; y += window.innerHeight) {
            window.scrollTo(0, y);
            await new Promise((r) => setTimeout(r, 60));
        }
        window.scrollTo(0, 0);
        await document.fonts.ready;
    });
    await page.waitForTimeout(400);
    return page;
}

/** In the page: the computed styles of a selector's matches that differ from the tag's defaults. */
function styles({ selector, children, properties }) {
    const frame = document.createElement('iframe');
    frame.style.display = 'none';
    document.body.appendChild(frame);
    const defaults = (tag) => {
        const element = frame.contentDocument.createElement(tag);
        frame.contentDocument.body.appendChild(element);
        const style = getComputedStyle(element);
        const values = Object.fromEntries(properties.map((p) => [p, style.getPropertyValue(p)]));
        element.remove();
        return values;
    };
    const own = (style, base) => {
        const out = {};
        for (const p of properties) {
            const value = style.getPropertyValue(p);
            // A border's colour without a border is only currentColor.
            const side = p.match(/^border-(top|bottom)-color$/);
            if (side && style.getPropertyValue(`border-${side[1]}-width`) === '0px') continue;
            if (value !== base[p]) out[p] = value;
        }
        return out;
    };
    const box = (el) => {
        const r = el.getBoundingClientRect();
        return { x: Math.round(r.x), y: Math.round(r.y + window.scrollY), width: Math.round(r.width), height: Math.round(r.height) };
    };
    const text = (el) => (el.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 60);

    let matches;
    try {
        matches = [...document.querySelectorAll(selector)];
    } catch (e) {
        frame.remove();
        return { error: `invalid selector: ${e.message}`, matches: 0, items: [] };
    }
    const items = matches.slice(0, 10).map((el) => {
        const base = defaults(el.localName);
        const item = { tag: el.localName, class: el.getAttribute('class') ?? '', text: text(el), box: box(el), styles: own(getComputedStyle(el), base) };
        for (const pseudo of ['before', 'after']) {
            const style = getComputedStyle(el, `::${pseudo}`);
            if (!['none', 'normal'].includes(style.content)) item[pseudo] = { content: style.content, ...own(style, base) };
        }
        if (children) {
            item.children = [...el.children].map((child) => ({ tag: child.localName, text: text(child), box: box(child) }));
        }
        return item;
    });
    frame.remove();
    return { matches: matches.length, items };
}

/** In the page: every visible element with text of its own, in document order, with its type and the gap above it. */
function texts(within) {
    const root = within ? document.querySelector(within) : document.body;
    if (!root) return { error: `no element matches ${within}`, items: [] };
    const items = [];
    const walker = document.createTreeWalker(root, NodeFilter.SHOW_ELEMENT);
    let previous = null;
    for (let el = walker.currentNode; el; el = walker.nextNode()) {
        if (['SCRIPT', 'STYLE', 'NOSCRIPT', 'TEMPLATE', 'svg'].includes(el.nodeName)) continue;
        const own = [...el.childNodes].filter((n) => n.nodeType === Node.TEXT_NODE).map((n) => n.textContent).join(' ').replace(/\s+/g, ' ').trim();
        if (own === '') continue;
        const style = getComputedStyle(el);
        const r = el.getBoundingClientRect();
        if (r.width === 0 || r.height === 0 || style.visibility === 'hidden' || style.display === 'none' || el.closest('[hidden],[aria-hidden="true"]')) continue;
        const top = r.top + window.scrollY;
        items.push({
            text: own,
            tag: el.localName,
            font: style.fontFamily.split(',')[0].replace(/["']/g, '').trim(),
            size: parseFloat(style.fontSize),
            weight: parseInt(style.fontWeight, 10),
            line: style.lineHeight === 'normal' ? null : parseFloat(style.lineHeight),
            spacing: style.letterSpacing === 'normal' ? 0 : parseFloat(style.letterSpacing),
            transform: style.textTransform,
            color: style.color,
            gap: previous === null ? null : Math.round(top - previous),
        });
        previous = top + r.height;
    }
    return { items };
}

/** Pairs each design text with the same text on the site (the most alike of several) and lists what differs. */
function compare(design, site) {
    const key = (t) => t.toLowerCase().replace(/[^\p{L}\p{N}]+/gu, ' ').trim().slice(0, 80);
    const pool = new Map();
    for (const item of site) {
        const k = key(item.text);
        if (!pool.has(k)) pool.set(k, []);
        pool.get(k).push(item);
    }
    const tolerance = { size: 0.5, weight: 50, line: 1, spacing: 0.1, gap: 4 };
    const unit = { size: 'px', weight: '', line: 'px', spacing: 'px', gap: 'px' };
    const pairs = [];
    const missing = [];
    for (const d of design) {
        // The same text more than once (prices, "Read more"): the most alike, then the first.
        const candidates = pool.get(key(d.text)) ?? [];
        const unlike = (c) => (c.tag !== d.tag) + Math.abs(c.size - d.size) / 4 + Math.abs(c.weight - d.weight) / 100 + (c.color !== d.color);
        const best = candidates.reduce((at, c, i) => (unlike(c) < unlike(candidates[at]) ? i : at), 0);
        const s = candidates.length ? candidates.splice(best, 1)[0] : undefined;
        if (!s) {
            missing.push(d.text.slice(0, 60));
            continue;
        }
        const differences = [];
        for (const p of ['size', 'weight', 'line', 'spacing', 'gap']) {
            if (d[p] === null || s[p] === null) continue;
            if (Math.abs(d[p] - s[p]) >= tolerance[p]) differences.push(`${p} ${round(d[p])}${unit[p]} → ${round(s[p])}${unit[p]}`);
        }
        for (const p of ['font', 'transform', 'color']) {
            if (d[p].toLowerCase() !== s[p].toLowerCase()) differences.push(`${p} ${d[p]} → ${s[p]}`);
        }
        if (differences.length) pairs.push({ text: d.text.slice(0, 60), design: d.tag, site: s.tag, differences });
    }
    const extra = [...pool.values()].flat().map((s) => s.text.slice(0, 60));
    const fonts = { design: [...new Set(design.map((d) => d.font))], site: [...new Set(site.map((s) => s.font))] };
    return { matched: design.length - missing.length, of: design.length, pairs, missing, extra, fonts };
}

const round = (n) => Math.round(n * 100) / 100;

main().catch((e) => done({ error: String(e.message) }));
