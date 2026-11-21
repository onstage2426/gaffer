// Maintainer tool: see README.md in this folder.
// gaffer-search on each of these pages, without a browser: the page's {{ search.attrs() }}
// (endpoint and token), the token's site and language claims, and one search through the
// endpoint with that token. For a network or a site with languages: every site's and every
// language's shop page, each searching its own index file. node search-pages.mjs [<theme dir>]
// <base url> <path>… [--q=<query>] (the theme dir, which bin/dev/sites check passes first, is unused).
const args = process.argv.slice(2).filter((a, i) => i > 0 || /^https?:|^--/.test(a));
const q = (args.find((a) => a.startsWith("--q="))?.slice(4)) ?? "";
const [base, ...paths] = args.filter((a) => !a.startsWith("--"));
if (!base || paths.length === 0) {
    console.error("Usage: node search-pages.mjs <base url> <path>… [--q=<query>]");
    process.exit(2);
}
// Local sites with self-signed certificates (without Node's warning about it).
process.removeAllListeners("warning");
process.env.NODE_TLS_REJECT_UNAUTHORIZED ??= "0";

const unescape = (s) => s.replace(/&amp;/g, "&").replace(/&quot;/g, '"').replace(/&#0?39;/g, "'").replace(/&lt;/g, "<").replace(/&gt;/g, ">");
let failed = false;
for (const path of paths) {
    const page = await fetch(new URL(path, base));
    const html = await page.text();
    const m = html.match(/data-gaffer-search-endpoint="([^"]+)" data-gaffer-search-token="([^"]+)"/);
    if (!m) {
        console.log(`FAIL ${path}: HTTP ${page.status}, no {{ search.attrs() }} on the page`);
        failed = true;
        continue;
    }
    const [endpoint, token] = [unescape(m[1]), unescape(m[2])];
    const claims = JSON.parse(Buffer.from(token.split(".")[0], "base64url").toString());
    const url = `${endpoint}${endpoint.includes("?") ? "&" : "?"}${new URLSearchParams({ token, q })}`;
    const answer = await fetch(url);
    const body = await answer.json().catch(() => ({}));
    const ok = answer.status === 200 && Array.isArray(body.results);
    failed ||= !ok;
    const first = body.results?.[0]?.url ?? body.results?.[0]?.id ?? "-";
    console.log(`${ok ? "ok  " : "FAIL"} ${path} (page ${page.status}, ${new URL(page.url).pathname}): site ${claims.site}, language ${claims.language ?? "-"}; `
        + `search ${answer.status}${body.code ? ` ${body.code}` : ""}, ${body.total ?? "?"} found, first ${first}`);
}
process.exit(failed ? 1 : 0);
