Audit Gaffer (and its plugins' theme side, `plugins/`) for long-term stability. The goal: a site's running code never breaks after a Gaffer or plugin update, for as long as possible, so 1.x lives long. Tooling (CLI, doctor, MCP, AI files) is outside that promise (`API.md`, "Tooling"): only flag it when it can break a site's running code or stored data.

Don't change code in this session. The output is the triage below and `docs/compat-debt.md`; I decide what gets built.

1. **Read first:** AGENTS.md (Design principles, Settled, Open), `API.md`, `docs/architecture.md`, `docs/compat-debt.md` (you're continuing it: start from its "Open" list and check its dates), the newest CHANGELOG entries, `local/sites.md` (if present), and your memory if you have one (the earlier audits). Don't re-propose what Settled or memory declined, nor what compat-debt already plans.

   **Where the sites are:** per site on this host and in `themes/`, the Gaffer reference in `composer.lock` (and whether GitHub still serves it: `curl -so /dev/null -w '%{http_code}' https://codeload.github.com/onstage2426/gaffer/legacy.zip/<ref>`), the htmx version (`bun.lock`/`package-lock.json`; both present is a finding), and `php gaffer doctor --wp` (local sites: errors and warnings only). The copies in `themes/` have no WordPress or `vendor/`: `bin/dev/doctor-copy themes/<site> doctor` runs this clone's static doctor on them (errors from what a copy lacks, like `public/` or `views/`, are noise). New copies: add them to `local/sites.md`. Which CHANGELOG entries is each site behind on? Don't commit where `local/sites.md` says not to.

2. **Map the surface a site's running code touches.** Start with `bin/dev/contract` for each plugin's boundary. For each public member in `tests/api.txt` and each convention in `API.md` (request keys, template names, derived keys, config keys, JS exports), note:
   - which sites use it (`bin/dev/usage`, `--member=` for detail);
   - what it depends on underneath: WordPress, WooCommerce, ACF, Gravity Forms, Twig, htmx/Alpine, Gaffer's plugins. Name the exact functions, hooks, globals, request fields and markup it relies on.

3. **What changes often:** run `git log --format='%h %ad' --date=short --name-only` over `src/`, `resources/js/` and `config/`. Count changes per file and per public member, and read the CHANGELOG for breaking entries. Code that's public and churns is a risk; say why it churns.

4. **What the platforms are changing.** Search the web (`extended` mode for the hard ones) for each dependency the map names: deprecations, planned removals and their replacements. Check what's deprecated in the versions installed on the integration site (`_deprecated_function` and `_deprecated_hook` calls, `@deprecated` in their source, `WP_DEBUG` notices during `doctor --wp`).
   - **WordPress:** the next releases' dev notes, Interactivity API vs. classic scripts, block API versions, `wp_kses`/escaping, PHP version policy.
   - **WooCommerce:** HPOS, the cart/checkout blocks replacing shortcodes and templates, template overrides (version headers), the Store API, session and cookie handling.
   - **ACF:** blocks v3 and later, `get_field()` formatting, JSON sync.
   - **Gravity Forms:** `GFAPI::submit_form`, `rgpost`, upload handling, the 3.x frontend.
   - **Twig 3 → 4:** deprecations Gaffer or themes trigger.
   - **htmx 2 → 4** and **Alpine:** attribute and event names.
   - **PHP:** deprecations in 8.5 and 8.6.

   For each: what Gaffer uses, the timeline, the replacement, and whether Gaffer can switch without a site changing anything.

5. **Self-adapting APIs.** Look for places where a site's code writes something by hand that Gaffer checks or reads: a field name, markup, an attribute, a request key, a cookie, a build setting, a file path. Those break when either side changes. The better design is a pair Gaffer owns on both ends, so its output can change in a patch and the check adapts by itself.
   - **Done on 2026-11-20 (the pattern to follow):** `@gaffer/forms/meta.twig` with `Spam::check()` (form key, honeypot, Turnstile), and `Turnstile::register()`. Also the Vite plugin `resources/vite.js`, which owns the hotfile, manifest, alias and the `gaffer.json` stamp.
   - **Still open** (compat-debt, "Not self-adapting yet"): `fields[x]`/`files[x]`/`kept_files[x][]`, `data-gaffer-resize`, search's endpoint/token inputs. Look for new ones in what themes added since (grep the sites' `app/Ajax/` and `views/` for keys, attributes and paths Gaffer reads).
   - **Also look for names that collide with the platform:** an input named like a DOM property (`form`, `action`, `method`: they shadow `HTMLFormElement`'s own), request keys WordPress reserves (`name`, `p`, `page`, `s`, `year` in a GET), Alpine/htmx attribute prefixes.
   - Say for each whether it's already self-adapting, could be, and what it would cost.

6. **Forms and ajax specifically** (I feel least safe here):
   - Forms depend on Gravity Forms' internals (compat-debt lists them). `doctor --wp` probes them on a throwaway form (`ContractsCheck`, `forms:probe`). Is the list still complete against the installed Gravity Forms' source? What does the probe still miss (uploads, `gform_uploaded_files`, notifications)?
   - Ajax actions can break without anyone noticing quickly: per site, which actions does nothing exercise (no render, no probe, no browser check)?
   - Spam gets a fake success, so every broken check or widget loses entries silently. What makes that loud?
   - Prefer checks that run on every `doctor --wp` over docs.

6b. **Click, don't only render.** `doctor --wp` renders pages but never clicks. Run `bin/dev/site-checks/` (see its README) on every local site: add to cart, the drawer, variations, archive swaps, each Gaffer form (one submit, one error answer). Read the browser console. Mind test hygiene: a submit on a site without Turnstile creates a real entry and maybe a mail, so note the highest entry ID first and delete by ID; and clear your lines from `spam.log`. Whatever only a click found is a candidate for a doctor check (compat-debt's "Nothing clicks in doctor").

7. **Present the triage to me briefly**, most dangerous first. Per item: what can break, how likely and when (with the source), the smallest fix that keeps existing sites working, and whether it needs a 2.x. Then wait for my answer.

8. **Write `docs/compat-debt.md`** (tracked; create it the first time):
   - what we keep for compatibility and would change in 2.x;
   - known platform deprecations with dates and our plan;
   - designs that aren't self-adapting yet;
   - each entry dated, with the reason and what removing it would take.

   Keep it current: entries that got fixed are removed and mentioned in the CHANGELOG. Record what was decided in compat-debt.md; a memory note if you have memory.

9. **Improve this prompt** if the audit showed it missed something, and add a line under "Lessons". Keep reusable test scripts in `bin/dev/` (never only in `/tmp`).

## Lessons
- 2026-11-20 (first run): a pair isn't only template ↔ PHP. Also check build ↔ PHP (the Vite
  hotfile, manifest and `@gaffer` alias were hand-written in `vite.config.js`) and bundled JS ↔
  PHP, which deploy at different times. And check how each failure would show: forms answer spam
  with a fake success, so a broken widget loses entries silently. Look for "fails silently" first.
  Measure what you can instead of guessing: compile every template collecting `Since twig/twig`
  deprecations, scan the installed plugins' sources for deprecated functions Gaffer calls
  (`_deprecated_function`/`@deprecated`; `_doing_it_wrong` is noise), and read the plugin's
  source for the internal you depend on (the GF honeypot's `is_api_submission()` bypass).
- 2026-11-20 (same day, building and rolling out):
  - **What only a browser found:** htmx 4 broke every Gaffer form through an
    `<input name="form">` that shadows `HTMLFormElement.form`. Neither htmx's upgrade checker
    nor doctor saw it. After a library upgrade, click through the sites (step 6b); for
    htmx-style libraries, read the library's source (htmx 4's `htmx:confirm` only fires with
    `hx-confirm`, and the form data is read before any event that can wait).
  - **What doctor itself caused:** `php gaffer` commands ran WP-cron, and an auto-update put
    two sites in maintenance mode in the middle of doctor's renders. When renders fail across
    the board, look at `.maintenance` and the plugins' file times before suspecting the change.
  - **Housekeeping:** install packages with the theme's own package manager (`bun.lock` vs
    `package-lock.json`); only commit what you created yourself.
  - **The partial was an API:** a theme's own (non-Gravity Forms) form used the partial too,
    so Gaffer needed a public check (`Spam::check()`). When Gaffer takes over one half of a
    pair, ask who else uses the other half.
- 2026-11-21 (second run, five theme copies):
  - **A copy is a new kind of site:** run static doctor on each (`bin/dev/doctor-copy`). It
    found what no local site had: a theme without `app/Ajax/` crashed doctor, and WooCommerce's
    account forms (`name="action"`) tripped the htmx check on the one site that copies them.
    Doctor errors a site can't baseline are the first thing to fix.
  - **History is part of the contract:** the fresh repository made every old `composer.lock`
    reference unreachable. Check that each site's reference still downloads.
  - **Count who uses a pair before deferring it:** the search form's hand-written keys were
    "experimental until 1.0", but six themes already wrote them.
