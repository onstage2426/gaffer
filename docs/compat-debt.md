# Compatibility debt

What a site's running code depends on that could break it after a Gaffer or platform update,
and what we carry or plan because of it. Kept by `prompts/audit.md` (run it now and then, before a
1.x minor and before a major). Every entry is dated, says why, and what removing or fixing it
takes. Fixed entries are removed here and mentioned in `CHANGELOG.md`.

Last audit: 2026-11-21 (same versions as 2026-11-20; htmx `latest` is still 2.0.11 and `next`
4.0.2, Alpine 3.17.5; WooCommerce 11.3 drops its own MCP endpoint (Gaffer uses WordPress's MCP
Adapter) and 11.6 needs PHP 8.1: neither touches Gaffer). Before: 2026-11-20 (WordPress 7.1.5, ACF Pro 6.8.10, WooCommerce 11.2.1, Gravity Forms
3.2.3.1, Twig 3.30, PHP 8.5.12, Alpine 3.17.4; htmx 4.0.2 on the local sites since that evening,
2.0.11 before). At those versions Gaffer calls no deprecated WordPress, WooCommerce, ACF or
Gravity Forms function or hook, `doctor --wp` shows no notices, and the local themes' templates
trigger no Twig deprecation.

## Open after the 2026-11-21 round

Start the next round here; remove an item when it's done or decided.

- **Rollout of 2026-11-21** (no htmx 2, no Vite dev server, `search.attrs()`, two lockfiles):
  the four local sites are done in the same cycle; the theme copies in `themes/` get a prompt
  each (`local/AGENTS_LOCAL.md` says which). A site with htmx below 4 must move before updating
  (`doctor` errors).
- **Lockfiles pinning removed commits** (2026-11-21). The repository started fresh on
  2026-11-20, and GitHub answers 404 for the old commits, so `composer install` from a
  `composer.lock` made before it fails (a fresh deploy, a CI build); `composer update
  onstage2426/gaffer` fixes it. Rule: never rewrite pushed history again. Unknown for the live
  site whose theme isn't here: ask its owner.
- **Nothing clicks in `doctor`.** Since 2026-11-21 `doctor --wp` calls the GET actions that need
  no argument (10 of 34 actions on the four theme copies with actions). POST actions (cart,
  forms, offers) and GET actions with a required argument still run only in a browser
  (`bin/dev/site-checks/`) or `ajax:call`. Candidate: a `doctor --wp` browser smoke test with
  the existing Playwright setup (`render:screenshot`): each Gaffer form submitted with the
  honeypot filled (expect `X-Gaffer-Spam: honeypot`, nothing written), console errors,
  `window.htmx`. Only where Playwright is installed.
- **Fake success hides breakage.** Spam gets a normal confirmation, so a broken widget or check
  loses entries silently. Today only the `spam.log` heuristic (`ContractsCheck`) notices.
  Consider counting real entries per form as well, so a form whose real entries stop while
  spam lines continue gets flagged. (Gaffer's forms are on few sites: four of the five theme
  copies show Gravity Forms by its own shortcode, not through Gaffer.)
- **The probe doesn't cover uploads.** New files need a web SAPI. An HTTP probe through
  `ajax.php` with a throwaway form template would cover `$_FILES`/`is_uploaded_file()` and
  `gform_uploaded_files`.
- **Deploying `public/`.** Gaffer can tell when the build is stale (`gaffer.json`). It doesn't
  know how live sites build and deploy, nor whether a build lands before the PHP after a
  `composer update`. Ask the site owners and write it in `local/sites.md`.
- **doctor's HTTP requests run the site's WP-cron** (the front page and, since 2026-11-21, the
  GET actions; after the renders). A plugin update it starts can still put a live site in
  maintenance mode for a moment.
- **Internal API in a site's running code** (2026-11-21, decided: accepted). One site's preview
  checkout prepends a template folder through `View::env()->getLoader()` (internal). It is a
  temporary migration step; `doctor` warns (`InternalApiCheck`). No public API for it unless a
  second site needs one.
- **Dates:**
  - remove `FormAction`'s old `form` key after 2026-12-31;
  - remove the hand-written search endpoint/token reading from `form-search.js` when
    `doctor`'s warning is gone on every site (2.x at the latest);
  - gaffer-search's updater token expires 2026-12-06 (memory: the pre-1.0 audit).

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
- When Gaffer checks what a template, a build or a theme file writes, Gaffer writes it too
  (a partial, a method, the Vite plugin).
- What a site relies on in a plugin and can't get through a public API gets a real check in
  `doctor --wp` (`ContractsCheck`), not just documentation.
