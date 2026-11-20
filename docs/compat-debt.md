# Compatibility debt

What a site's running code depends on that could break it after a Gaffer or platform update,
and what we carry or plan because of it. Kept by `prompts/audit.md` (run it now and then, before a
1.x minor and before a major). Every entry is dated, says why, and what removing or fixing it
takes. Fixed entries are removed here and mentioned in `CHANGELOG.md`.

Last audit: 2026-11-20 (WordPress 7.1.5, ACF Pro 6.8.10, WooCommerce 11.2.1, Gravity Forms
3.2.3.1, Twig 3.30, PHP 8.5.12, Alpine 3.17.4; htmx 4.0.2 on the local sites since that evening,
2.0.11 before). At those versions Gaffer calls no deprecated WordPress, WooCommerce, ACF or
Gravity Forms function or hook, `doctor --wp` shows no notices, and the local themes' templates
trigger no Twig deprecation.

## Open after the 2026-11-20 round

Start the next round here; remove an item when it's done or decided.

- **Rollout.** Migrating the sites to the 2026-11-20 entries (stability audit, htmx 4, a theme's
  own forms): four local sites done; one site migrated itself but its own form action still
  reads the honeypot and Turnstile fields itself (switch to `Spam::check()`); three sites not
  yet. Which is which: `local/AGENTS_LOCAL.md`.
- **Nothing clicks in `doctor`.** The worst break of the round (`<input name="form">` under
  htmx 4) only showed in a browser (`bin/dev/site-checks/`). Candidate: a `doctor --wp` browser
  smoke test with the existing Playwright setup (`render:screenshot`). For each Gaffer form it
  would submit with the honeypot filled (expect `X-Gaffer-Spam: honeypot` and a swapped answer,
  writing nothing), then check console errors and that `window.htmx` is there. Only where
  Playwright is installed.
- **Fake success hides breakage.** Spam gets a normal confirmation, so a broken widget or check
  loses entries silently. Today only the `spam.log` heuristic (`ContractsCheck`) notices.
  Consider counting real entries per form as well, so a form whose real entries stop while
  spam lines continue gets flagged.
- **The probe doesn't cover uploads.** New files need a web SAPI. An HTTP probe through
  `ajax.php` with a throwaway form template would cover `$_FILES`/`is_uploaded_file()` and
  `gform_uploaded_files`.
- **Deploying `public/`.** Gaffer can now tell when the build is stale (`gaffer.json`). It
  doesn't know how live sites build and deploy, nor whether a build lands before the PHP after
  a `composer update`. Ask the site owners and write it in `local/sites.md`.
- **doctor's HTTP request still runs the site's WP-cron** (after the renders since
  2026-11-20). A plugin update it starts can still put a live site in maintenance mode for a
  moment.
- **Dates:**
  - remove `FormAction`'s old `form` key after 2026-12-31;
  - remove `Turnstile::verify()` when `bin/dev/usage` shows no caller;
  - gaffer-search's updater token expires 2026-12-06 (memory: the pre-1.0 audit);
  - htmx 2 support in Gaffer's JavaScript goes when the last site has moved.

## Kept for compatibility (2.x candidates)

- **`FormAction` accepts the request key `form` next to `form_name`** (2026-11-20).
  `@gaffer/forms/meta.twig` sent `form` until 2026-11-20; a page cache may still serve that
  markup. Remove the `$form` parameter after 2026-12-31 (no cache keeps a page that long). It
  costs one parameter.

- **`Turnstile::verify()` is deprecated** (2026-11-20). A theme's own form used it next to the
  honeypot field it read itself (one site's own form action). `Forms\Spam::check()` does both and
  owns the field names. Remove it with the first major or before 1.0, when no theme calls it
  (`bin/dev/usage --member=Turnstile::verify`).

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
- **htmx 4** (2026-11-20). 4.0.2 is a stable release on npm's `next` tag; `latest` stays on
  2.0.11 until 2027. The local sites moved to htmx 4 on 2026-11-20 (the integration
  site, the starter and two more); other sites stay on 2 until their own update. Gaffer's
  JavaScript works with both:
  - `forms.js` shrinks photos on `change` and holds `submit` in the capture phase (htmx 4's
    `htmx:confirm` only fires with `hx-confirm`);
  - the Turnstile renderer listens to both versions' events;
  - error answers carry `HX-Reswap: none`.

  `HtmxCheck` reports htmx 2 names in an htmx 4 theme: renamed or removed attributes, old event
  names including `hx-on::after-request`, and inheritance (that part comes from htmx's own
  `upgrade-check.py`). Plan: drop htmx 2 from Gaffer's JavaScript and the docs when the last
  site has moved (then `forms.js` could use `htmx:config:request`).
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
- **Search's endpoint/token inputs** (2026-11-20). The template writes them for Gaffer's search
  JavaScript; experimental until gaffer-search 1.0, then make them a partial or attributes from
  `Searchbar`/`Archive`.

## Rules that follow from this

- PHP and bundled JavaScript ship together but deploy apart (`composer update` vs the next
  build): a contract between them only grows, and each half keeps working with the previous
  release's other half. `doctor` says when the build has older Gaffer JavaScript.
- When Gaffer checks what a template, a build or a theme file writes, Gaffer writes it too
  (a partial, a method, the Vite plugin).
- What a site relies on in a plugin and can't get through a public API gets a real check in
  `doctor --wp` (`ContractsCheck`), not just documentation.
