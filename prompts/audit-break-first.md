Audit Gaffer, its plugins (`plugins/`) and the themes that run them, as one system, for what
breaks first. The goal: years of 1.x on sites nobody looks at every day. What runs on a site
(PHP a theme calls, Twig, the bundled JavaScript, the plugins' runtime, stored data, cached
pages) must keep working through any update of Gaffer, a plugin, WordPress, WooCommerce, WPML,
PHP or the host. Tooling (CLI, doctor, MCP, AI files) only counts where it can break that.

The stance to audit against (decided): the CLI and `php gaffer doctor --wp` are the interface;
there are no admin screens (gaffer-search removed its dashboards in v0.3.0). The admin bar's
"Gaffer" menu has one health line per Gaffer plugin, the main dot the worst of them. More
`gaffer-*` plugins may come (`prompts/plugin.md`): what you find for gaffer-search, say whether
it's a rule every plugin should follow.

Don't change code in this session. The output is a ranked triage; I decide what gets built.

## 1. Read first

- Gaffer's `AGENTS.md` (Plugins, Design principles, Settled, Gotchas, Open), `API.md`,
  `docs/architecture.md` (the rows you touch), `docs/compat-debt.md`, the newest `CHANGELOG.md`
  entries.
- Each plugin's `AGENTS.md`, `docs/contract.md`, `docs/hosting.md`, `docs/migration.md`,
  `docs/compat-debt.md`.
- `local/AGENTS_LOCAL.md` (its "Leads for the next audit" too) and `local/sites.md`: which
  sites exist, how they build and deploy, which you may commit to. The latest reports in
  `feedback/`. Your memory, if you have one.
- The method of the two narrower audits, which this one combines: `prompts/audit.md` (Gaffer:
  surface map, churn, platform timelines, self-adapting pairs) and
  `prompts/audit-gaffer-search.md` (the plugin: the boundary's three clocks, the endpoint, tokens,
  stored data). Their "Lessons" apply here. Don't re-propose what Settled, compat-debt or memory
  already decided or plans.
- Working trees: `git status` in Gaffer and each plugin, `bin/dev/plugins`. Uncommitted work
  isn't yours: say what it is and ask.

## 2. Where everything is

One table, per site (the local ones: `bin/dev/sites status --online`; the remote ones: their
reports; the copies in `themes/`): Gaffer reference and whether GitHub still serves it, each
Gaffer plugin's version, PHP and SQLite (web and CLI), WordPress, WooCommerce, WPML/WCML, the page
cache and CDN, the web server (nginx in front of Apache is common), how it builds `public/`,
how it deploys, how WP-cron runs. `bin/dev/sites run --summary -- doctor --wp` for the local
ones. The copies in `themes/` may be older than the sites: compare with the reports, and ask for
fresh copies before reading their code if they are.

## 3. What breaks first

For every piece that runs on a site, write down what it depends on (the exact functions, hooks,
markup, request keys, files, options, cookies, headers) and how it fails: loudly (an error page,
a doctor error) or silently (an empty list, a stale index, a cached broken page). Then rank:
**likelihood** (how often the thing underneath changes, with sources and dates) × **blast
radius** (one page, every search, every form, the checkout, the whole site) × **how long it would
go unnoticed** (which check would catch it, on its own, and when).

Measure instead of guessing: read the source of what you depend on, fetch over HTTP what a
visitor could, try updates and rollbacks on a local site, and run the browser checks
(`bin/dev/sites check`, `bin/dev/site-checks/search-pages.mjs`).

Cover at least:
- **A plugin missing:** what every page shows while a Gaffer plugin is inactive or its folder is
  gone mid-update (`bin/dev/render-probe.php … --without=<plugin>`: status, `DONOTCACHEPAGE`,
  robots), and whether a page cache would keep it.
- **Updates arriving apart:** Gaffer's PHP (`composer update`), the theme's build (Gaffer's
  JavaScript), each plugin (its updater, any day), and pages in a cache for hours. Try the
  combinations for real (an older build with newer PHP, a plugin release with the previous
  Gaffer, a cached page's token after an update).
- **How updates reach a site:** plugin updaters (their check period, GitHub's limits for
  unauthenticated API requests, a release that's missing an asset: read the updater's fallbacks),
  `composer.lock` references
  that must stay downloadable, the theme's package manager and lockfile on the server.
- **What themes override:** WooCommerce template overrides (`woocommerce/` in a theme, their
  `@version` against WooCommerce's), ACF block versions, Gravity Forms markup, hooks themes
  remove or replace.
- **Theme code that leans on internals:** `bin/dev/usage --unused`, doctor's `InternalApiCheck`,
  hand-written pairs Gaffer reads (compat-debt, "Not self-adapting yet").
- **The checks themselves:** what `doctor --wp`, the plugins' `status` commands, their daily
  health check and the admin bar would miss. A check that can't run (WP-cron off, no loopback,
  HTTP auth) must say so instead of staying quiet.

## 4. Archives with gaffer-search, end to end

How a shop or category page is built today, and where it hurts. Follow one request on a local
site with an archive (`local/AGENTS_LOCAL.md` says which) and read what each theme copy wrote
around it:
- the server side: what `Search::archive()` asks the engine (the token, the first page's search,
  `bounds`), what WooCommerce's own main query still does on that page, the queries and time per
  render (`php gaffer render:page <path>`, hidden from `list`, reports both; `bin/dev/render-probe.php`
  breaks the queries down by caller), the HTML weight of the cards and `initial()`;
- the URL: `ArchiveUrl` (canonical, `noindex`, redirects of `/page/N/` and WooCommerce's params),
  the term lock, `?view=`, `limits`, `base_params`, a filter plugin's old URLs
  (`redirect_params`); what search engines and page caches see for every filter combination;
- the browser side: `archive()`/`createFilterForm()`, the form and its fields, history and
  back/forward, `offset`, the error state, no JavaScript, slow networks;
- what each theme wrote by hand around it (filter drawers, range sliders, brand pages, a
  theme's own `Search` class, counts), duplicated across themes: candidates for Gaffer, and code
  that would break when Gaffer or the plugin changes.

List the pain points separately from the "breaks first" ranking: what's slow, what's easy to get
wrong, what every theme repeats, what the docs and skills don't say.

## 5. CLI only, no backend

Is it consistent? Anything left that needs wp-admin (a settings page, a notice someone must click,
an admin AJAX action) in Gaffer or a plugin; anything an operator needs that the CLI or doctor
can't do; whether the admin bar line says enough to know where to look, and stays small with more
plugins.

## 6. Present the triage, then wait

Most dangerous first. Per item:
- what breaks, and for whom;
- how likely, and when (with the source);
- how it would be noticed today;
- the smallest fix that keeps existing sites working, and where it goes (Gaffer, a plugin, a
  theme, the host);
- whether it needs a contract addition, a stored-format change or a major version.

Then the archive pain points, then the CLI/backend gaps. Wait for my answer.

## 7. After my answer

Record what was decided: both repositories' `docs/compat-debt.md` (public knowledge only),
`local/AGENTS_LOCAL.md` for what's about a site, and a memory note if you have memory. Improve
this prompt where it missed something, with a line under "Lessons". Keep reusable scripts in a
`bin/` of the repository they test.

## Lessons

From the narrower audits (theirs apply here too):
- Look for "fails silently" first; measure, don't guess.
- Follow the deploy: what an update deletes, when each half arrives, and roll it back once.
- History is part of the contract: a lockfile or updater pointing at something that's gone
  breaks the next install.
- Before a test that writes (a submit, a product change, a moved file), note what exists and
  undo it by ID or from a copy.
- A fix a plugin ships to every site (a file in its folder, a header) can break a host that
  allows less than yours: weigh what the fix costs where it fails, not only where it works.

From the first run (2026-11-21):
- **Turn a plugin off for one process, not on the site:** the empty shop (HTTP 200, cacheable,
  indexable) only showed with gaffer-search left out of `active_plugins` for one render.
- **Read the updater's fallbacks:** a release without its asset would have installed GitHub's
  source zip (no `vendor/`), a fatal on every request. Check what the bundled library does when
  something is missing, and what a manual update skips (WordPress rolls back only auto-updates).
- **"Nothing waiting" isn't "running":** every check looked at work that waits; none at a job
  that stopped. Ask of each cron job when it last ran, and who would be told.
- **A missing header can be the host's:** the endpoint's `ETag` vanished in WP Rocket's root
  `.htaccess` (`Header unset ETag`); `Last-Modified` still gave 304. Fetch through Apache from
  the container too, to tell the app from the proxy.
- **A version that hashes more must stay equal across contexts:** `home_url()` follows the
  request's scheme (CLI http, web https) and the theme folder differs in a staged copy; either
  would make two contexts rebuild each other's index. Use stored values, and check every WPML
  language and both paths (container and host).
- **The fix's own migration entry applies to the local sites too:** the new override check
  flagged the starter's notices, which dropped WooCommerce's `data-id`; fixing the starter-based
  themes was part of the round.

- **Test a cache against a rollback, not only an edit:** Twig's own `auto_reload` compares the
  template's modification time with the compiled file's, so restoring an older template
  (`git checkout`, `rsync -a`, a deploy tool keeping times) served the stale compiled one. Any
  cache keyed on "newer than" needs the same test.
