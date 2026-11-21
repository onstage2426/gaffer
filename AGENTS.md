# Gaffer — developing Gaffer itself

Notes for agents working on this repository. What Gaffer tells *themes* lives
in `resources/ai/` and is written into each theme by `php gaffer ai:update`.
This host's private notes (gitignored; see "No private details" below):

@local/AGENTS_LOCAL.md

Gaffer (`onstage2426/gaffer`) is a WordPress theme framework: Twig views,
typed wrappers around WordPress objects, an ajax dispatcher, Vite assets and a
CLI (`php gaffer`). Themes install it with Composer and keep only site-specific
code. The repository was started fresh on 2026-11-20; it is public.

## Working in this repo

- **Work directly on `0.x`** (themes require `0.x-dev`). No feature branches.
- **Commits:** one change per commit, a short subject in the imperative
  ("Add Spam::check() for a theme's own forms"): capitalized, no period, about
  50 characters, never over 72 (GitHub cuts it off there). Only when the why
  isn't obvious: a blank line, then a few plain lines wrapped at 72. No lists of
  every file, no changelog copy, no site names. Never add a Co-Authored-By or
  any other AI attribution line.
- **Push** when a Gaffer change is done and verified (`git push origin 0.x`),
  then update the sites.
- **Public API:** `API.md` (hand-written, shipped): the promise, conventions,
  Twig and JS. The PHP API is every class and member in `src/` not marked
  `@internal`, documented by its docblocks (sites read them in `vendor/`:
  write them for sites). `tests/api.txt` snapshots the signatures
  (`tests/ApiTest.php`), so an API change shows as a diff. Anything else is `@internal`,
  and `doctor` warns when a theme uses it (`InternalApiCheck`). Changing the
  public API is a decision: deprecate first (`#[\Deprecated]`, see
  "Deprecating" in `API.md`), update the snapshot on purpose
  (`UPDATE_API=1 composer test`), and check who uses it first:
  `bin/dev/usage [--member=View::fetch] [--unused]` lists every theme in
  `themes/` (and `local/usage-paths`) per public member, and the internal or missing code
  they use.
- **Stability is about what runs on the site** (the user's goal: 1.x for as
  long as possible): the PHP a theme calls, Twig, the JS, stored data and
  conventions. The tooling (CLI, doctor, MCP, AI files) only runs while
  building, so it may change in a minor with a changelog entry (`API.md`,
  "Tooling"). Keep runtime code small and boring; put cleverness in tooling.
- **Before every commit:** (CI runs the same: `.github/workflows/ci.yml`)
  `composer test` (PHPUnit) and
  `vendor/bin/phpstan analyse --memory-limit=1G` (level 6, **no baseline**:
  fix types instead of ignoring them).
- **Integration test site:** a copy of the starter theme with its demo
  content, on its own branch (where it lives: `local/AGENTS_LOCAL.md`, untracked,
  with everything else about this host and the sites). The starter itself is
  being stripped to a clean starting point and is no longer the test site. After
  pushing: `composer update onstage2426/gaffer` in the integration site, adapt it,
  then
  `php gaffer twig:lint` and `php gaffer doctor --wp` (renders ~28 URLs with
  strict variables). Never edit a theme's `vendor/`.
- **Breaking changes are fine** (the user prefers breaking now over carrying
  a messy design). When a design needs a workaround, look for the root cause
  and propose fixing that instead, even if it breaks things.
- **Every change a site must react to goes in the changelog** (`CHANGELOG.md`,
  committed and shipped with the package; newest first, by date; the guideline
  tells site agents to read it after updating): what changed, a `grep` to find affected
  code, before/after, what to check afterwards. After 1.0 that's a
  deprecation, not a break (`API.md`). A breaking change in Gaffer usually
  breaks the integration site and the starter until they're updated, so they land
  in one cycle: Gaffer commit → push → update the integration site, the starter
  (and the other sites in `local/AGENTS_LOCAL.md`) immediately.
- **No private details in tracked files.** The repository is public: no site or
  theme names, hostnames, host paths or client work in code, comments, tests,
  `CHANGELOG.md`, commit messages or docs ("a site", "a live WooCommerce
  multisite"). Only what's public knowledge goes in: Gaffer, gaffer-search, the
  starter, WordPress and plugins. The rest goes in `local/` (gitignored):
  `local/AGENTS_LOCAL.md` (imported at the top of this file: where the integration site
  is, where plugins get deployed, hostnames, the sites to update after a push,
  which site an anonymized note is about), `local/sites.md` (the sites, their
  roles, history) and `local/usage-paths` (theme globs for `bin/dev/usage`). A
  fresh clone has none of it: ask the user, then write it there.

### Site feedback rounds

Gaffer improves from real work on themes. The prompts in `prompts/` carry that between agents
(`themes/README.md` has the steps):

- `prompts/feedback.md`: what the user pastes into the theme's own agent session at its end. It
  writes the report (`gaffer-feedback.md` in the theme).
- `prompts/process.md`: what the user gives a session here, after placing the theme with its
  report and transcript (.jsonl) in `themes/<site>/` (gitignored). Fill in `<site>`, then
  follow it: triage, wait for the go, build, update prompt for the site, notes.
- `prompts/audit.md`: a stability audit of what a site's running code depends on (churn in git,
  platform deprecations, hand-written pairs Gaffer could own); it keeps `docs/compat-debt.md`
  (what we carry for compatibility, platform deadlines, 2.x candidates). Run it now and then,
  before a 1.x minor and before a major.
- `prompts/audit-gaffer-search.md`: the same for gaffer-search and Gaffer's side of it (the
  endpoint, tokens in cached pages, index files, Fuzor, the queue, both update directions); it
  keeps the plugin's own `docs/compat-debt.md`. Before a plugin tag, and now and then.
- `prompts/audit-break-first.md`: the whole system at once (Gaffer, every plugin, the themes):
  what breaks first, ranked by likelihood, blast radius and how long it would go unnoticed; how
  archives are built with gaffer-search and where that hurts; whether CLI-only holds. Leads for it
  are in `local/AGENTS_LOCAL.md`.
- `prompts/plugin.md`: a new plugin for the catalog (see "Plugins"): whether it should be one,
  the proposal, then the plugin and Gaffer's side.

The workflow improves itself: after a round, when either prompt missed something (a question the
report should answer, a step worked out by hand, an earlier decision worth pointing to), edit it,
add a line under "Lessons" in `prompts/process.md`, and tell the user what changed.

### Testing against real WordPress before pushing

The integration site's `vendor/` has the last pushed Gaffer. To run unpushed code
against it: `bin/dev/gaffer-test <site> doctor --wp` (any `php gaffer` command). It loads this
clone's autoloader and **preloads every Gaffer class** (once WordPress boots the theme, the
theme's older installed Gaffer would otherwise win for any class not loaded yet).
`bin/dev/gaffer-test <site> build` builds a temporary copy of the theme with this clone's
`resources/` (Vite plugin, JavaScript). A silent exit 255 is usually a fatal WordPress hid:
`GAFFER_TEST_FATAL=1` prints it.

### The local sites, all at once

`local/sites.json` (gitignored) lists the local sites: theme, URL, whether we may commit there,
a container, their browser checks. `bin/dev/sites`:

- `status [--online]`: each site's Gaffer reference (and whether GitHub still serves it), htmx,
  lockfiles, git, gaffer-search;
- `run [--summary] -- doctor --wp`: any `php gaffer` command on every site at once;
- `update`: the update cycle after a push (`composer update` until the lock has `origin/0.x`,
  build with the theme's package manager, `ai:update`, `twig:lint`, commit `composer.lock`
  where allowed); then `run --summary -- doctor --wp`;
- `check`: the browser checks (`bin/dev/site-checks/`) with cleanup: Gravity Forms entries
  above the highest ID before are deleted and `spam.log` is restored; no form submit where
  Turnstile is off (`--allow-entries` to submit anyway).

All take `--sites=a,b`. Splitting uncommitted work into commits (here or in a plugin):
`bin/dev/stage-hunks <file> [1,4]` and `bin/dev/test-staged <test command>`.

Run PHP on the host; the container hostnames resolve via `/etc/hosts`. The
`phpfpm` container also works (`docker exec -i phpfpm php`). Through Apache:
`docker exec -i phpfpm php` with `file_get_contents('http://apache/...')` and a
`Host:` header for the site.

## Plugins

Gaffer is the core that binds its plugins into themes. Each plugin is its own repository (own
tags, releases and updater), cloned into `plugins/<name>/` (gitignored): `bin/dev/plugins clone`
fetches the missing ones from `plugins/catalog.json`, `bin/dev/plugins` shows each one's branch,
uncommitted work and last tag. Work on a plugin from this session: its own `AGENTS.md` loads
when you read its files (read only what the task needs); commit with `git -C plugins/<name>`.
To find a name across the boundary, search `src/`, `plugins/` and `themes/` together.
`bin/dev/contract [<plugin>]` maps the boundary both ways (per contract method: the plugin's
implementation, Gaffer's wrapper and callers, its recorded cases; the features each side
declares, documents and checks; the hooks; the fixtures) and exits 1 on a mismatch. A change
across it: the `contract-change` skill.

| Plugin | What | Gaffer finds it | Gaffer's side |
|---|---|---|---|
| gaffer-search | search, faceted archives and sliders from its own index; endpoints that don't boot WordPress | `apply_filters('gaffer_search_engine', null, 1)` (`Search\Engine`, contract 1) | `src/Search/`, `config/search.php`, `resources/js/search*`, `resources/ai/*/plugins/gaffer-search`, `tests/fixtures/search-contract/`, `SearchCheck` |

Every plugin follows the same rules:

- **A plugin only when it must be one:** its data outlives the theme, one copy serves a network,
  or it needs a fixed path (an endpoint). Otherwise it belongs in Gaffer or the theme.
- **One contract, and only through Gaffer.** Themes never call a plugin, and a plugin never calls
  Gaffer. Gaffer finds it through a filter (`gaffer_<name>_engine`) that names a contract
  number. The contract is in the plugin's `docs/contract.md` and only grows: additions are
  features (`supports()`), and recorded fixtures freeze it. Gaffer keeps a copy of them and tests
  against it.
- **Updated apart:** a plugin update never breaks a site on an older Gaffer (never undefines what
  a theme defined, never changes what a site shows), and a newer Gaffer works with the plugin's
  previous release. A contract change lands in the plugin first (feature, fixture, push), then
  Gaffer (copy the fixtures, check `supports()`): the `contract-change` skill.
- **Gaffer's side of each plugin:** the theme-facing API (public, experimental until the plugin's
  1.0), an entry in `Ai\Plugins` with its guideline and skill (`resources/ai/*/plugins/<name>`),
  and `doctor` checks (warnings only, so a plugin release can't make `doctor` fail; a real check
  in `ContractsCheck` for what isn't public).
- **Each plugin repository** keeps Gaffer's workflow: branch `0.x`, the commit style above, the
  public-knowledge rule, `API.md` + `api.txt`,
  `docs/migration.md` (sites read it after an update), CI. The user tags releases; never tag 1.0.
- **Fails loudly, never empty:** Gaffer's side treats the plugin missing while the theme's config
  needs it as a temporary failure (no page cache, 503 where a page is built from it), never as
  "nothing found".
- **Its updater installs only the release's built zip** (gaffer-search's `ReleaseAsset`): the
  updater's fallback to GitHub's source zip has no `vendor/` and would fatal on every request.
- **Every cron job it has reports its last run** through `status()`, and its health line in the
  admin bar (and `php gaffer health`) goes orange when a run is days overdue; work done only on
  `admin_init` also runs from a cron job (a site nobody logs into).
- **A new plugin:** `prompts/plugin.md`. The catalog stays small (up to about ten): a plugin that
  isn't used by sites any more is retired, not kept.

## Design principles

- **snake_case** method names (matches Twig and WordPress); otherwise PSR-12.
- **One way to do each thing.** No facade + type method + Twig function for
  the same lookup, no alias methods, no overloaded "accepts anything"
  signatures.
- **Separate classes per concern.** Extra `use` statements are fine.
- **Factories take the identifier only** (`int` ID). "Current" is its own
  named method, never a null/omitted argument.
- **No magic fallbacks.** Missing data is `null`; the caller decides.
- **Visible magic is fine, hidden magic isn't.** A type in a signature
  (route model binding) or a file in a folder (blocks, ajax actions) is fine;
  behavior that changes where you can't see it is not.
- **PHP prepares data, Twig presents it.** No Twig functions that fetch
  content.
- **Helpers output values or attributes, not whole HTML tags** (e.g.
  `<img class="x" {{ image.attrs('large') }}>`), so markup stays in templates.
  Show one converted example before mass-rewriting templates.
- **Null for "not found", exceptions for programmer errors.**
- **Gaffer owns both ends of a pair.** When Gaffer checks or reads what a
  template writes (a honeypot, a request key, an attribute), Gaffer also writes
  it (a method or partial the template calls), so its output can change in a
  patch and the check adapts: an improvement without a site changing code.
- **PHP and bundled JavaScript deploy apart** (`composer update` changes the PHP at once, the
  theme's next build the JavaScript): a contract between them only grows, and each half works
  with the previous release's other half. `doctor` warns when the build has older Gaffer JS.
- **What a site relies on in a plugin without a public API gets a real check** in `doctor --wp`
  (`ContractsCheck`: the Gravity Forms probe, `ajax.php` over HTTP), recorded in
  `docs/compat-debt.md`.
- **Convention over configuration.** The theme layout and ajax namespace are
  fixed; only server concerns are configurable (`GAFFER_STORAGE`).

### Guidelines and skills for themes (`resources/ai/`)

The generated `AGENTS.md` loads on every turn of every site's agent, so it
stays small as sites and features grow (Gaffer's part ~26 KB, plugins
included; the theme's own `.ai/guidelines/` get a `doctor` warning above 30 KB).

- Guidelines hold rules and decisions. Procedures, option lists and reference
  detail go in skills (loaded when needed), with a one-line pointer.
- A rule `doctor` enforces gets one line: its hint explains it when it fires.
- Signatures are `API.md`'s, what a theme has is `gaffer-reference`'s, options
  are `--help`'s: don't repeat them in guidelines.
- A new feature adds to a skill first; a guideline line only for what an agent
  must know before it would think to look.

## Settled decisions (don't re-propose)

- No nonces on ajax actions (page caching makes them stale).
- SHORTINIT and `ajax.php` stay: they're for other developers and projects,
  even when the starter doesn't use SHORTINIT. Improve the dispatcher, don't
  replace it with the REST API or WordPress routing.
- ACF block editor previews are intentionally empty (`is_admin()` guard).
- The Twig cache is on by default (2026-11-21, the user; was off): a folder per Gaffer version, a
  key per template's modification time and size (a rollback recompiles), and a folder it can't
  write renders uncached (`Twig\Cache`, `Twig\Loader`). `theme.cache: false` turns it off.
- No code generators (`make:*`); `doctor` enforces conventions instead.
- No test/analysis tooling in sites (phpstan, PHPUnit). Gaffer can have any
  dev dependency.
- Themes are standalone, never child themes.
- Theme layout: classes in `app/` (ajax actions too: `app/Ajax/`), templates in `views/`; blocks are the one self-contained folder (WordPress registers them from `block.json`). Field groups stay plain files in `fields/` (not classes with `get()`: the types read values; not `config/`: that's settings).
- Field groups are code: `blocks/*/fields.php` and `fields/{param}/{value}.php`, one file per target (sections are tabs), no group settings (the title comes from WordPress's own name for the target, placement from the first tab). UI-made groups are a `doctor` warning. Registering post types/taxonomies/options pages stays theme code (or ACF UI JSON).
- No InnerBlocks and no flexible content in blocks (the user's call: nested
  content is a no-go; wysiwyg covers rich text). `doctor` and `BlockFields`
  enforce it.
- No field type converters and no Gaffer-maintained lists of WordPress/ACF
  internals: ask ACF/WordPress at runtime (`acf_get_field_type()`,
  `wp_check_post_lock()`), and anything that writes content refuses formats it
  doesn't know (round-trip, block data shape, `widget_block` shape).
- No WP-CLI in the dev image.
- Files `php gaffer ai:update` generates in a theme (`AGENTS.md`, `CLAUDE.md`,
  agent skill folders) are gitignored, never committed. Their sources
  (`resources/ai/` here, `.ai/` and `config/ai.php` in the theme) are.
- `Site` type, `Theme` facade, `Facades\`, `PostType`/`Taxonomy`/`Video`,
  path config: removed on purpose.
- No `icon()` Twig function (it would print a whole tag): an icon sized in templates is a Twig
  partial with the SVG inline and a `class` variable (views guideline, 2026-11-05).
- Forms' hidden halves (form key, honeypot, Turnstile widget and script) are Gaffer's
  (`@gaffer/forms/meta.twig`, `Turnstile::register()`); the build settings Gaffer reads are
  Gaffer's Vite plugin (`resources/vite.js`). Audit 2026-11-20.
- No `content:seed`/`content:clear`, no `cache:clear`/`search:rebuild`/`eval` commands, no
  `money`/`number` filters (a site's report, answered 2026-11-05): content written by code is
  `Blocks::markup()` and `migrate:set`; caches and WP-CLI are the host's business.

## Architecture and sites

- **`docs/architecture.md`**: every area (boot, blocks, forms, search, render,
  CLI, migrations, doctor, MCP, AI) with its classes and how they work. Read
  the row before changing an area; update it with the change.
- **Plugins:** the table in "Plugins" above; each plugin's own `AGENTS.md` and
  `docs/contract.md`.
- **`local/sites.md`** (untracked): the sites on this host and elsewhere, their roles (which
  may be committed to, which are the user's), and history worth knowing
  (gaffer-search's road to its first tag, why gaffer-search).

## Gotchas learned the hard way

- `get_post(0)` returns the *current* post: factories return `null` for ids ≤ 0.
- Plugins bundle their own `Composer\InstalledVersions`; it may not know the
  theme's packages. `Gaffer::version()` reads `vendor/composer/installed.php`.
- A page cache drop-in (`advanced-cache.php`, WP Rocket) answers the CLI's
  fake GET request with a cached page and exits: `Console\WordPress` turns
  it off with `enable_loading_advanced_cache_dropin` (pre-set in
  `$wp_filter`, read by `plugin.php` before `wp-settings.php` decides).
- Loading WordPress inside a function: `wp-config.php` variables become
  local (and so do a template file's and plugins' top-level variables: the
  render loads WordPress at a script's top level for that reason, see Render
  compare; before that, WPML sites rendered as `lang="en-US"` instead of their
  language); `Console\WordPress` binds `global $table_prefix`. It also needs
  `HTTP_HOST`/`REQUEST_URI`, `WP_USE_THEMES` for rendering and
  `WP_DISABLE_FATAL_ERROR_HANDLER` to see errors. It raises `memory_limit` to
  `WP_MAX_MEMORY_LIMIT` on `enable_wp_debug_mode_checks` (the first filter after
  `wp_initial_constants()`, before plugins; `enable_loading_advanced_cache_dropin`
  only runs with `WP_CACHE`) and prints a fatal on shutdown: without `WP_DEBUG`
  WordPress turns `display_errors` off, and running out of memory was a silent
  exit 255. `WordPress::wrong_site()`: `SITE_URL`'s host must be `home_url()`'s
  (else WordPress redirects every render); `doctor --wp` stops there.
- WooCommerce's term ordering keeps array keys: use `reset($terms)`, not `$terms[0]`.
- Nav menu item fields (`title`, `url`, …) are dynamic WP_Post properties.
- Twig `strict_variables` (on with `WP_DEBUG`) is what catches template
  bugs; `doctor --wp` forces it on when rendering.
- WordPress's `wp_get_environment_type()` is `production` unless
  `WP_ENVIRONMENT_TYPE` is defined.
- `wp_check_post_lock()` is admin-only: `require_once ABSPATH .
  'wp-admin/includes/post.php'` first (works from the CLI).
- `$wpdb->get_var()` returns null for an empty string: use `get_row()` when
  empty is a valid value (an emptied post_content). A script that loads `wp-load.php` must not
  use a global `$theme`: WordPress overwrites it while loading.
- Testing a content migration: compare rendered pages before/after (the integration site
  has a time-based marquee delay and best-seller ties in random order:
  normalize/sort lines; WPML's cookie expiry and Gravity Forms' tokens and
  honeypot label change per request: the ignore patterns are in the
  `gaffer-migrate` skill), compare `sha1(post_content)` per post with the
  original after rollback, and dump the database first (`mariadb-dump`
  inside the `mariadb` container, password from its secret file, never
  through your hands).
- Gravity Forms keeps submission state per PHP process
  (`GFFormDisplay::$submission`): a second `GFAPI::submit_form()` in the same
  process gets the first one's result. Real requests submit once; test each
  submission in its own process. Its e-mail validation checks the domain
  (`example.com` fails: use a real domain in tests). Clean up test entries by
  ID (entries with an ID above the highest before the test), never by count.
- Right after a push, the first `composer update onstage2426/gaffer` on a site
  can still get the previous commit (GitHub briefly serves the old ref): run it
  again until `composer.lock` has the new reference.
- Anything that hashes or caches theme files must use paths relative to the theme: the web
  server (container, `/usr/local/apache2/htdocs/…`) and the CLI (host, `~/…`) see
  the same theme under two paths. Absolute paths in gaffer-search's endpoint config and in
  `Cards::version()` made each side rebuild what the other built.
- Don't parse templates with `View::env()` while Gaffer boots: parsing initializes its
  extensions, and later `addExtension()` calls fail (doctor's render profiler did).
  `Cards::files()` parses with its own environment (same loader and extensions).
- Register blocks (and anything that runs other plugins' filters) on `init`,
  not while `functions.php` loads: WooCommerce's block filters call `__()`,
  and translations before `init` log `_load_textdomain_just_in_time`. Find
  the caller of a `_doing_it_wrong` notice with a `doing_it_wrong_run` hook
  that prints `debug_backtrace()` (`wp --require=<file>`).
- Never keep often-written state in autoloaded options (one array for many keys, least of all):
  with a persistent object cache a long process (a 2-minute index build) writes back the whole
  `alloptions` it loaded at its start, so another process's newer value is lost. gaffer-search's
  built versions did that on a live multisite (2026-11-04): the index looked stale after every
  build and rebuilt in a loop. One non-autoloaded option per key.
- No `php gaffer` command runs WP-cron (`WordPress::skip_page_cache()` pre-sets `pre_get_ready_cron_jobs`; on 2026-11-20 doctor runs still started plugin updates on two dev sites), and doctor's HTTP requests to the site come after the renders. A render must not run WP-cron: `wp_cron()` on `init` spawned the overdue update checks, a
  translation auto-update put a dev site in maintenance mode, and the snapshot's later renders
  (exiting in `wp_maintenance()` before the status filter existed) were saved as HTTP 200 with
  empty HTML (2026-11-09 and 2026-11-15).
- Never tell a live site to `wp cache flush` (or `redis-cli FLUSHALL`): on a live multisite
  (Redis, 2M+ keys) the flush blocked Redis and the site answered with WordPress's Redis error
  for a few seconds (2026-11-04). After deleting options straight in the database, delete only
  the stale entries per site: `wp cache delete alloptions options --url=<site>` and
  `wp cache delete notoptions options --url=<site>`.

## Open

- **At 1.0:** a README, the tag; the CHANGELOG starts
  fresh; gaffer-search's `docs/migration.md` at its first tag.
- **Deferred to a plan (a site's report, 2026-11-05; build when a second site needs it):**
  `render:audit <path>` + MCP `gaffer-audit` (in the browser after Alpine starts: heading
  outline, title/meta/robots/canonical/og, JSON-LD types, accessible names from the
  accessibility tree, `aria-label` without a role, focusable invisible elements, skip link and
  `main`; its static part could run in `doctor --wp` via `HtmlProblems`); `render:profile <path>
  [--without=plugin,…]` (warm repeated renders, phase marks in `$wp_filter`, slowest hooks and
  blocks, `SAVEQUERIES` by caller; the user: "too heavyweight", keep deferred). Smaller:
  `MenuItem::is_current_section()`, an opt-in `|t` check for literal text, Product
  JSON-LD/gallery warnings for thin `single-product.php`, token classes missing from `@theme`,
  `migrate:remove-group`, the MCP server re-reading `.env` per call.
- **Deferred (a site's reports, 2026-11-19 and -20; build when a second site needs it):** an archive
  locked to a field value on a page of its own (today: a product taxonomy/attribute archive);
  `ajax:call` sending files (curl meanwhile); a public file normalizer for ajax actions
  (`Ajax::files()`; `Forms\Uploads` is Gravity Forms'); a documented pattern for designed
  WooCommerce mails; screenshots of a local HTML file through a remote browser. Declined there:
  `uploadState()`, `doctor --only`, `--browser=webkit`, `ajax:call --no-mail` (muting mail needs a
  server switch a header flips), a doctor check for callables in `inc/` (`doctor --wp` loads it),
  comments/emoji off in Gaffer (the starter does it), WooCommerce tips in guidelines.
- **Standalone documents:** e-mail bodies and PDF sources are whole documents through
  `View::fetch()` (views guideline, 2026-11-20). A print view with its own `<html>` (one site)
  still gets doctor's "HTML in PHP"; revisit when a second site needs one.
- **Forms, maybe later:** multi-page forms, Gravity Forms' combined fields (Name, Address),
  conditional logic. Decided: Turnstile is the only captcha; Gravity Forms is always the engine.
- **Block fields, maybe:** `migrate:rollback --skip-changed` if refusing a whole rollback over one
  edited page gets in the way.
- **AI boost, maybe:** more MCP tools when a need shows up (decided against: `eval`, anything
  that writes, what `gaffer-reference` already has); more agent adapters (Cursor, Copilot, Gemini;
  Laravel Boost's `src/Install/Agents/*` has their paths); a Yoast guideline (skipped).
