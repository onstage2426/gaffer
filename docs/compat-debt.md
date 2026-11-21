# Compatibility debt

What a site's running code depends on that could break it after a Gaffer or platform update,
and what we carry or plan because of it. Kept by `prompts/audit.md` (run it now and then, before a
1.x minor and before a major). Every entry is dated, says why, and what removing or fixing it
takes. Fixed entries are removed here and mentioned in `CHANGELOG.md`.

Last audit: 2026-11-21, break-first audit of the whole system; before it, the third round (WordPress 7.1.5, ACF PRO 6.8.10 with 6.8.12 out, WooCommerce
11.2.1 with 11.3.1 out, Gravity Forms 3.2.3.1, Twig 3.30 with 4.0.0-alpha1 published, PHP 8.5.12,
htmx `latest` 2.0.11 and `next` 4.0.2, Alpine 3.17.5). WooCommerce 11.3.1 keeps the three
functions Gaffer's runtime calls, none deprecated. ACF 6.8.11 has two fatal regressions
(`wp_insert_post()` with a post object, saving relational fields) fixed in 6.8.12: a site skips
6.8.11. Gaffer calls neither (migrations write the database directly). ACF 6.8.12 turns its
abilities on by default and stops preview scripts in blocks: Gaffer's MCP server lists only its
own tools, and its block previews are empty. Earlier rounds: 2026-11-20 and 2026-11-21 (same
versions; at those Gaffer called nothing deprecated and `doctor --wp` showed no notices).

## Open after the third 2026-11-21 round

Start the next round here; remove an item when it's done or decided.

- **From the break-first audit (2026-11-21, `prompts/audit-break-first.md`).** Built: an inactive
  gaffer-search keeps search pages out of caches and the archive answers 503 (before: an empty
  shop, HTTP 200, cached and indexable); `doctor --wp` and the admin bar flag a daily check older
  than two days (WP-cron stopped on a quiet site); `php gaffer health` for a daily crontab with
  `MAILTO` (nobody sees the admin bar on a site nobody visits); a card index's version includes
  the `home` option (stored cards hold absolute URLs) and `doctor` warns about cards without a
  `rebuild` schedule; `doctor --wp` compares WooCommerce overrides' `@version` with WooCommerce's
  templates. Open:
  - **WooCommerce's main query still runs on archives Gaffer answers** (measured: 82 of a shop
    page's 125 queries are WooCommerce's, its main query's rows thrown away). Candidate: skip it
    with `posts_pre_query` and `found_posts` from the search; test on a large catalog first
    (Yoast's pagination, WooCommerce's loop functions read `$wp_query`).
  - **Server page links and Yoast's offset-page parity are hand-written per site** (one site:
    its own page window, the same as the JavaScript's `getPageNumbers()`, and `%%page%%`,
    prev/next and the page crumb for `offset` pages). A pair Gaffer could own (`Archive::pages()`)
    when a second site with `seo.index_pages` needs it.
  - **Every filter combination is its own uncached URL** (page caches skip query strings):
    crawlers render each in full. Watch, no change.
  - The theme folder's name is left out of the card version on purpose: a staged copy runs under
    another name, and stage and live would rebuild each other's index.

- **The theme copies in `themes/` are older than the sites** (all five on 83a11ed; the sites
  report d8dcd34). The user decided against fresh copies this round: the round-2 rollout counts as
  done by the sites' reports, unverified. Ask for fresh copies before the next round that needs
  their code.
- **Lockfiles pinning removed commits** (2026-11-21). The fresh repository made old
  `composer.lock` references unreachable; every reporting site updated past them. Unknown for the
  live site whose theme isn't here; the stale local copy of one site still pins one. Rule: never
  rewrite pushed history again.
- **Nothing clicks in `doctor`.** `doctor --wp` calls the GET actions that need no argument or
  only posts and terms (since the third 2026-11-21 round). POST actions (cart, forms, offers) and
  GET actions with other arguments still run only in a browser (`bin/dev/site-checks/`) or
  `ajax:call`. Candidate: a `doctor --wp` browser smoke test with the existing Playwright setup
  (each Gaffer form with the honeypot filled, console errors, `window.htmx`), where Playwright
  is installed.
- **Fake success hides breakage.** Spam gets a normal confirmation; only the `spam.log`
  heuristic notices a broken widget. Consider counting real entries per form too. Since the third
  round `doctor` warns about an action that takes an e-mail address without `Spam::check()`.
- **The probe doesn't cover uploads** (new files need a web SAPI): an HTTP probe through
  `ajax.php` with a throwaway form template would cover `$_FILES` and `gform_uploaded_files`.
- **Deploying (answered 2026-11-21).** Every site that reported builds `public/` on its server;
  two serve the theme in place (a build is live at once), and on one the theme *is* production
  (a `composer update` swaps `vendor/` under running requests: the site's practice, not
  Gaffer's). Since the third round a build keeps the previous build's files, so pages in a cache
  and a build in progress keep working. The PHP/JS rule stays (cached pages and open tabs run the
  previous JavaScript for hours).
- **The theme's `.htaccess` is a hand-written pair** (2026-11-21): it lets through
  `public/assets/`, `ajax.php` and `screenshot.png`, Gaffer's paths, and keeps the rest private.
  One copy had an empty one. Since the third round `doctor --wp` fetches `.env`, `.git/HEAD`, a
  probe file in `storage/logs/` and the Composer files over HTTP. Gaffer could own the file
  (write or check its rules) if a path changes; `Storage::private_dir()` already rewrites its own
  folders' deny file.
- **doctor's HTTP requests run the site's WP-cron** (front page, GET actions, the private-file
  probe; after the renders). A plugin update it starts can still put a live site in maintenance
  mode for a moment.
- **Internal API in a site's running code** (2026-11-21, decided: accepted). One site's preview
  checkout uses `View::env()->getLoader()`; `doctor` warns. No public API unless a second site
  needs one.
- **Deferred (2026-11-21):** `render:screenshot` behind a password plugin (one site; its agent
  used its own Playwright script with the plugin's cookie). Candidate: a repeatable
  `--cookie name=value` for the render and the browser, when a second site needs it.
- **Not Gaffer's:** two sites' `.git` directories vanished on their servers during their agents'
  sessions. Nothing in Gaffer deletes outside the Twig cache, marked skill folders, an empty
  `.codex/` and `public/`.
- **Dates:**
  - remove `FormAction`'s old `form` key after 2026-12-31;
  - remove the hand-written search endpoint/token reading from `form-search.js` when
    `doctor`'s warning is gone on every site (2.x at the latest).

## Kept for compatibility (2.x candidates)

- **`FormAction` accepts the request key `form` next to `form_name`** (2026-11-20).
  `@gaffer/forms/meta.twig` sent `form` until 2026-11-20; a page cache may still serve that
  markup. Remove the `$form` parameter after 2026-12-31 (no cache keeps a page that long). It
  costs one parameter.

- **`form-search.js` still reads a hand-written search form** (2026-11-21):
  `data-gaffer-search-endpoint` and a hidden `token` input, next to what `{{ search.attrs() }}`
  writes. `SearchCheck` warns on the hand-written ones. Remove the fallback when no site writes
  them (2.x at the latest). It costs two lines.

## Platform dependencies and their timelines

- **Gravity Forms internals** (2026-11-20). Gaffer submits headless through `GFAPI::submit_form()`
  and relies on things Gravity Forms doesn't promise:
  - the input keys (`input_{id}`, and `input_{id}_{n}` for checkboxes and consent);
  - `rgpost()` stripping slashes (Gaffer `wp_slash()`es its input);
  - the result keys (`is_valid`, `validation_messages` by field ID, `confirmation_*`);
  - `$_FILES['input_{id}']` checked with `is_uploaded_file()`, and the `gform_uploaded_files`
    JSON with Gravity Forms' own HMAC `hash`;
  - `GFFormsModel::$uploaded_files`, the files kept after errors;
  - the honeypot handler's `is_api_submission()` bypass. Since GF 2.9.8 the honeypot's default
    action is "spam", so without the bypass every Gaffer entry would go to spam.

  No deprecation of any of these is announced. If one changes, entries are lost or garbled
  without an error, so `doctor --wp` (`ContractsCheck`, `forms:probe`) tests them on a
  throwaway form outside production. Not covered: new uploads (they need a web SAPI) and
  `gform_uploaded_files`. Plan: when the probe fails, fix Gaffer in a patch. Moving off
  internals would mean Gravity Forms' REST submission endpoint (it has its own spam and nonce
  rules), which isn't worth it while the probe holds.
- **ACF blocks** (2026-11-20). ACF PRO 6.8.9 made Blocks V3 the default for undeclared blocks
  on WordPress 7.1, which always iframes the post editor. Gaffer's blocks must declare
  `blockVersion: 3` (a doctor error); ACF then registers them with apiVersion 3. Reading block
  data: blocks call `get_field()` inside their render (ACF's documented way).
  `Acf::block_array()` knows it's in a block through WordPress's block type (`render_callback`
  wrapped by `Gaffer::blocks()`), not ACF's `acf/pre_load_post_id` (was used until 2026-11-20).
  Watch for: a block-render path that bypasses `render_callback`.
- **htmx 4** (2026-11-20; only htmx 4 since 2026-11-21). 4.0.2 is a stable release on npm's
  `next` tag; `latest` stays on 2.0.11 until early 2027, so a theme must ask for `^4` itself.
  Every site with htmx is on 4 (two sites use no htmx). Gaffer's JavaScript relies on:
  - `forms.js` shrinking photos on `change` and holding `submit` in the capture phase (htmx 4's
    `htmx:confirm` only fires with `hx-confirm`; `htmx:config:request` could replace this);
  - the Turnstile renderer on `htmx:after:process`/`after:swap`/`after:settle`;
  - error answers with `HX-Reswap: none` (htmx 4 swaps errors).

  `HtmxCheck` errors on a theme below htmx 4 and reports htmx 2 names in an htmx 4 theme
  (renamed or removed attributes, old event names, inheritance from htmx's `upgrade-check.py`).
  DOM-clobbering input names (`action`, `method`, `form`, …) count only in forms htmx sends
  (WooCommerce's account forms post `name="action"`).
- **Twig 4** (2026-11-20). Still in preview; 3.x marks what it changes (macros since 3.29,
  `~` vs `+`/`-` precedence, `spaceless`, the stricter sandbox). Gaffer requires `^3.21`, so a
  composer update never brings Twig 4. `doctor` reports Twig's own deprecations in theme
  templates. Gaffer's tooling walks Twig's node classes (`Console\Templates`), so a Twig 4
  release needs a Gaffer major and a pass over those.
- **PHP 8.5 / 8.6** (2026-11-20). Nothing in `src/` uses what they deprecate (backtick
  operator, non-canonical casts, `is_long()`/`doubleval()` aliases, `spl_object_hash()`,
  `mb_ereg*`).
- **WooCommerce** (2026-11-20). Gaffer's runtime touches only `is_shop()`,
  `is_product_taxonomy()` and `wc_get_page_id()`; sessions and carts only in tooling
  (`ajax:end`, renders). Classic checkout shortcodes have no removal date.
- **Cloudflare Turnstile** (2026-11-20). `api.js?render=explicit&onload=` and `siteverify` are
  unchanged; tokens live 300 s, and the widget refreshes them itself. Both ends are Gaffer's now
  (`Turnstile`, `@gaffer/forms/meta.twig`): a change is a Gaffer patch.
- **Alpine** (2026-11-20): no v4 announced. `search.js` uses `x-data`, `x-model` and
  `$nextTick`.
- **The History API** (2026-11-21). `form-search.js` writes the address bar with
  `pushState`/`replaceState` after each answer (a debounced keystroke replaces). Safari and
  Firefox throw above about 100 calls in 30 seconds, and Chrome has proposed throwing above 200 a
  second; one call per answer stays far below. The Navigation API (in every engine since
  2026-01) doesn't replace History.
- **gaffer-search** (2026-11-21; the plugin keeps its own `docs/compat-debt.md`). Without the plugin while `config/search.php` defines indexes, `Search` marks the request `engine_missing` (no page cache) and the archive answers 503 (break-first audit). Gaffer reads
  what the engine adds only when it's there: `search()`'s `code` (feature `search_codes`: the
  page stays out of caches, an archive answers 503) and `status()`'s `drift`/`served` (feature
  `health_checks`, shown by `doctor --wp`); with an older plugin both are absent and nothing
  changes. `doctor --wp` searches each index through the endpoint over HTTP; Gaffer's JavaScript
  tests replay every recorded endpoint answer (`tests/js/contract.test.js`), so a change in the
  plugin's answers fails Gaffer's CI once the fixtures are copied.
- **Hosting** (2026-11-20). `ajax.php` is a PHP file in the theme, served directly. A host rule
  or security plugin that blocks PHP in `wp-content/themes` breaks every action and form. Settled:
  it stays; `ContractsCheck` fetches it over HTTP on every `doctor --wp`.

## Not self-adapting yet

Pairs where a site writes by hand what Gaffer reads; each breaks when either side changes.

- **`fields[x]`, `files[x]` and `kept_files[x][]`** (2026-11-20). Request keys the form template
  writes. They're a documented convention (`API.md`), and doctor checks the names against
  Gravity Forms statically. A partial for kept files would cost little, but the listing and
  remove buttons are the theme's design. Revisit when a second site lists kept files.
- **`data-gaffer-resize`** (2026-11-20). The attribute is written in the template and read by
  Gaffer's `forms.js`, which is bundled into the theme. If it's renamed, the JavaScript keeps
  accepting the old name for at least one release.
- **Search's `offset` input and history attributes** (2026-11-21). `{{ search.attrs() }}` now
  writes the endpoint and token. The `offset` hidden input (Alpine-bound) and
  `data-gaffer-search-history`/`-default` stay the theme's: they're choices (push or replace,
  what a default is), and `offset` is also gaffer-search's request key. Revisit at
  gaffer-search 1.0.

## Rules that follow from this

- PHP and bundled JavaScript ship together but deploy apart (`composer update` vs the next
  build): a contract between them only grows, and each half keeps working with the previous
  release's other half. `doctor` says when the build has older Gaffer JavaScript.
- A build never removes what the previous build wrote (Gaffer's Vite plugin, since 2026-11-21):
  a page cache or an open tab keeps the previous build's HTML for hours.
- When Gaffer checks what a template, a build or a theme file writes, Gaffer writes it too
  (a partial, a method, the Vite plugin).
- What a site relies on in a plugin and can't get through a public API gets a real check in
  `doctor --wp` (`ContractsCheck`), not just documentation.
