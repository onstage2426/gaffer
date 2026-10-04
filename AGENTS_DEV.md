# Gaffer — developing Gaffer itself

Notes for working on this repository (`CLAUDE.md` imports this file). What
Gaffer tells *themes* lives in `resources/ai/` and is written into each theme
by `php gaffer ai:update`.

Gaffer (`onstage2426/gaffer`) is a WordPress theme framework: Twig views,
typed wrappers around WordPress objects, an ajax dispatcher, Vite assets and a
CLI (`php gaffer`). Themes install it with Composer and keep only site-specific
code. This repository is a sandbox for now (a fresh repo comes before launch).

## Working in this repo

- **Work directly on `0.x`.** No feature branches. Commit messages are just
  `general`. Never add a Co-Authored-By or any other AI attribution line.
- **The user pushes.** This machine has no GitHub credentials. Commit, then
  say what's unpushed.
- **Public API:** `API.md` (what themes may rely on) and `api.txt` (its
  signatures, checked by `tests/ApiTest.php`). Anything else is `@internal`.
  Changing the public API is a decision: deprecate first, and update the
  snapshot on purpose (`UPDATE_API=1 composer test`).
- **Before every commit:** `composer test` (PHPUnit) and
  `vendor/bin/phpstan analyse --memory-limit=1G` (level 6, **no baseline**:
  fix types instead of ignoring them).
- **Integration test site:** the blueprint theme,
  `~/docker/appdata/websites/blueprint/wp-content/themes/blueprint`
  (see its own CLAUDE.md). After the user has pushed:
  `composer update onstage2426/gaffer` there, adapt blueprint, then
  `php gaffer twig:lint` and `php gaffer doctor --wp` (renders ~28 URLs with
  strict variables). Never edit a theme's `vendor/`.
- **Breaking changes are fine** (the user prefers breaking now over carrying
  a messy design). When a design needs a workaround, look for the root cause
  and propose fixing that instead, even if it breaks things.
- **Every change a site must react to gets a migration book entry** (below).
  A breaking change in Gaffer usually breaks blueprint until it's updated, so
  Gaffer and blueprint changes land in one cycle: Gaffer commit → user
  pushes → update blueprint immediately.

### Migration book (`MIGRATION.md`)

Two other sites run an older Gaffer. Until the user says they're updated,
`MIGRATION.md` in this repo collects step-by-step instructions an AI agent
applies when updating those sites: what changed, a `grep` to find affected
code, before/after examples, what to check afterwards. It is **local only**
(listed in `.git/info/exclude`): never commit or push it. When the user says
the sites are updated, delete it (and the exclude line).

### Testing against real WordPress before pushing

Blueprint's `vendor/` has the last pushed Gaffer. To run unpushed code against
blueprint, use a throwaway entry file (e.g. `/tmp/gaffer-test`, run from
`/tmp` as `php gaffer-test doctor --wp`) that loads this clone's autoloader,
maps the theme's `Theme\` classes, and **preloads every Gaffer class** (once
WordPress boots the theme, the theme's older installed Gaffer would otherwise
win for any class not loaded yet):

```php
#!/usr/bin/env php
<?php
require getenv('HOME') . '/workspace/gaffer/vendor/autoload.php';
$theme = getenv('HOME') . '/docker/appdata/websites/blueprint/wp-content/themes/blueprint';
spl_autoload_register(function ($c) use ($theme) {
    if (str_starts_with($c, 'Theme\\')) { $f = "$theme/app/" . str_replace('\\', '/', substr($c, 6)) . '.php'; if (is_file($f)) require $f; }
});
$src = getenv('HOME') . '/workspace/gaffer/src';
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS)) as $f) {
    $class = 'Gaffer\\' . str_replace(['/', '.php'], ['\\', ''], substr($f->getPathname(), strlen($src) + 1));
    class_exists($class) || interface_exists($class);
}
exit(Gaffer\Console\Console::boot($theme));
```

A silent exit 255 usually means a fatal hidden by WordPress: add a
`register_shutdown_function` that prints `error_get_last()`.

Run PHP on the host; the container hostnames resolve via `/etc/hosts`. The
`phpfpm` container also works (`docker exec -i phpfpm php`). Through Apache:
`docker exec -i phpfpm php` with `file_get_contents('http://apache/...')` and a
`Host: blueprint.020004.xyz` header.

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
- **Convention over configuration.** The theme layout and ajax namespace are
  fixed; only server concerns are configurable (`GAFFER_STORAGE`).

## Settled decisions (don't re-propose)

- No nonces on ajax actions (page caching makes them stale).
- SHORTINIT and `ajax.php` stay: they're for other developers and projects,
  even when blueprint doesn't use SHORTINIT. Improve the dispatcher, don't
  replace it with the REST API or WordPress routing.
- ACF block editor previews are intentionally empty (`is_admin()` guard).
- Twig cache stays off by default.
- No code generators (`make:*`); `doctor` enforces conventions instead.
- No test/analysis tooling in sites (phpstan, PHPUnit). Gaffer can have any
  dev dependency.
- Themes are standalone, never child themes.
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

## Architecture

| Area | Files |
|---|---|
| Boot | `Gaffer::configure()` (theme root + config, no WordPress), `Gaffer::boot()` (Twig, `inc/`, ACF JSON path, blocks, admin bar), `Gaffer::twig()` (SHORTINIT), `Gaffer::version()` |
| Forms | `Forms\GravityForm` (form by title slug, fields by Admin Field Label, `submit()` via `GFAPI::submit_form`, input keys for consent/checkbox), `Forms\FormState` (what a form template shows), `Forms\FormAction` (the one ajax action: honeypot, Turnstile, submit, re-render or `HX-Redirect`), `Forms\FormTemplates`; `forms:show`; `WordPressCheck::forms`. phpstan knows Gravity Forms through `phpstan/gravityforms.stub` (`scanFiles`) |
| Config | `Config` (theme config), `ConfigStubs` (Gaffer's keys + descriptions from `config/*.php`, the theme's key comments), `Console\Env` (the theme's `.env`: `SITE_URL`), `Gaffer::debug()` (= `WP_DEBUG`) |
| Static services | `Storage` (`private_dir()`: logs/backups with deny `.htaccess` + `.gitignore`), `Config`, `Paths` (fixed layout), `View` (render/fetch/share, owns the Twig env), `Acf`, `BlockFields` (`blocks/*/fields.php` → local ACF groups, derived keys), `Vite`, `Turnstile`, `TwigCache`, `AdminBar` |
| Types | `Types\Post` (+ `Attachment`, `Image`), `Term`, `Menu`/`MenuItem`, `Pagination`. Wrap the WP object (`->wp`), protected constructors, factories `from(int)`, `current()`, `query()`. Class maps `theme.types` / `theme.terms` |
| Ajax | `Ajax` (dispatcher, `url()`, `NAMESPACE`), `AjaxAction` (`METHOD`/`SHORTINIT` constants), `AjaxArguments` (typed `run()` params incl. route model binding), `AjaxNotFound` |
| Twig | `Twig\Extension`: `config()`, `ajax_url()` only |
| CLI | `Console\Console`, `Command`, `WordPress` (CLI loader), `Report`, `ConfigStubs`, `ThemeFiles`, `Templates` (template names, includes and variables from Twig's parse tree; used by `twig:lint`, `TemplatesCheck`, the reference), `Commands\*`, `Checks\*` (doctor) |
| Content migrations | `Console\Migrate\`: `BlockData` (pure: rewrites ACF block data, unit-tested), `ContentStore` (find/read/write posts + block widgets straight in the DB), `Migration` (plan → refuse on any problem → backup → one transaction that re-checks every row → read back), `Backup` (`storage/backups/migrate/`, checksummed), `Log` (`storage/logs/migrate.log`, JSON line per `--run` with outcome). Commands `migrate:block`, `migrate:field`, `migrate:remove-block`, `migrate:remove-field`, `migrate:rollback` (`MigrateCommand` base) |
| (doctor) | `Checks\GuidelinesCheck` (theme `.ai/` mentions of missing paths, `Theme\` classes, `acf/` blocks), `Checks\AppCheck` (snake_case methods in `app/`, `#[\Override]` exempt), `TemplatesCheck` also flags `.get_*()` calls, `Checks\AssetsCheck` (Vite build present, not older than `assets/`, `Vite::` entries in the manifest; skipped in dev mode), `Checks\IncCheck` (functions/classes declared in `inc/`), `MarkupCheck` (HTML in PHP), `TemplatesCheck` |
| MCP | `Mcp\Mcp` (registers the `gaffer/*` abilities and the STDIO-only server `gaffer` when the MCP Adapter plugin is active and the site isn't production; `launch()` = `wp mcp-adapter serve --server=gaffer`, the same on every machine: WP-CLI finds WordPress from the theme dir, and the tools allow any WP-CLI call (no `--user`; whoever runs `wp` can do anything anyway)), `Mcp\Tool` + `Mcp\Tools\*` (block-usage, doctor, render, last-errors, forms; all read-only), `Mcp::served_tools()` (starts `launch()` like an agent and asks `tools/list` over the protocol; `WordPressCheck::mcp` compares it with `Mcp::tools()`, so adapter updates that break the server show up in `doctor --wp`), `Mcp\GafferCli` (runs the theme's `php gaffer` in a child process: `doctor`, `doctor:render --html`). Protocol is the adapter's job: Gaffer only uses `wp_register_ability()` and `create_server()` (phpstan stub `phpstan/mcp-adapter.stub`) |
| AI ("boost") | `Ai\Agent` (adapters: claude, codex, grok; paths as in Laravel Boost), `Ai\Guidelines` (Gaffer's `resources/ai/guidelines/` + plugin guidelines for active plugins (WooCommerce, ACF, Gravity Forms) + the theme's `.ai/guidelines/`, same file name overrides; `doctor` lists overrides as info), `Ai\Reference` (generated from the theme's code: config, Twig, types, ajax, blocks, views, `inc/` hooks by comment), `Ai\McpConfig` (Gaffer's entry in an agent's project MCP config: JSON, or TOML edited as text, only Gaffer's own table), `Ai\Installer` (writes `AGENTS.md`, agent files like `CLAUDE.md` = `@AGENTS.md`, skills (Gaffer's + active plugins' + the theme's `.ai/skills/`), the `.gitignore` block; removes deselected agents' output; `clear()`). Commands `ai:install`, `ai:update`, `ai:clear`; both writers always load WordPress (the reference needs the booted theme's `View::share()` data) |
| AI sources | `resources/ai/guidelines/*.md` (+ `plugins/{plugin}.md`), `resources/ai/skills/{name}/SKILL.md` (+ `plugins/{plugin}/{name}/`). Edit these when Gaffer's behavior changes, then `ai:update` in blueprint |
| Config stubs | `config/*.php`: the reference list of every config key (all commented out). New keys go here; `config:show` and `doctor` read them |
| Tests | `tests/` (+ `tests/stubs/wordpress.php` for the few WP functions unit tests touch) |

## Gotchas learned the hard way

- `get_post(0)` returns the *current* post: factories return `null` for ids ≤ 0.
- Plugins bundle their own `Composer\InstalledVersions`; it may not know the
  theme's packages. `Gaffer::version()` reads `vendor/composer/installed.php`.
- Loading WordPress inside a function: `wp-config.php` variables become
  local; `Console\WordPress` binds `global $table_prefix`. It also needs
  `HTTP_HOST`/`REQUEST_URI`, `WP_USE_THEMES` for rendering and
  `WP_DISABLE_FATAL_ERROR_HANDLER` to see errors.
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
- Testing a content migration: compare rendered pages before/after (blueprint
  has a time-based marquee delay and best-seller ties in random order:
  normalize/sort lines), compare `sha1(post_content)` per post with the
  original after rollback, and dump the database first (`mariadb-dump`
  inside the `mariadb` container, password from its secret file, never
  through your hands).
- Gravity Forms keeps submission state per PHP process
  (`GFFormDisplay::$submission`): a second `GFAPI::submit_form()` in the same
  process gets the first one's result. Real requests submit once; test each
  submission in its own process. Its e-mail validation checks the domain
  (`example.com` fails: use a real domain in tests). Clean up test entries by
  ID (entries with an ID above the highest before the test), never by count.
- Register blocks (and anything that runs other plugins' filters) on `init`,
  not while `functions.php` loads: WooCommerce's block filters call `__()`,
  and translations before `init` log `_load_textdomain_just_in_time`. Find
  the caller of a `_doing_it_wrong` notice with a `doing_it_wrong_run` hook
  that prints `debug_backtrace()` (`wp --require=<file>`).

## Current state (2026-10-03)

- **Pushed through `f69e2ca`** (AI boost locked in: forms guideline +
  `gaffer-form` skill gated on Gravity Forms, `--no-wp` removed, `doctor`
  lists theme overrides of Gaffer's guidelines/skills); blueprint is on it.
  MCP server pushed (`9033b85`), blocks on `init` (`5e7c393`); blueprint is
  on it and Claude Code (in Zed) uses the MCP server. MCP config per agent
  with a portable entry pushed (`677a757`). Unpushed: `doctor --wp` checks
  the MCP server over the protocol. Next: use the MCP tools in real work and
  tune descriptions/output from that (not yet used for a real task).
- **MCP setup on blueprint:** MCP Adapter plugin 0.7.0 installed by the user
  (dev only, never on production; bundling it as a library is deprecated
  upstream). Testing unpushed MCP code: `wp --require=<file that preloads the
  clone's classes, all of them: Composer prepends the theme's autoloader, so any
  class not preloaded comes from the old vendor/>` plus the theme's
  `gaffer` entry pointed at the clone temporarily (the tools start `php
  gaffer` as a child process); restore it with `git checkout gaffer`.
  Blueprint's `composer.json` runs `php gaffer ai:update` after every
  `composer update` (`post-update-cmd`).
- **AI boost files:** `AGENTS.md` (Gaffer guidelines → active plugins'
  guidelines → theme `.ai/guidelines/` → generated reference; ~900 lines in
  blueprint, the reference stays inline on purpose), `CLAUDE.md`, skills per
  agent. Codex (`.agents/skills`) and Grok (`.grok/skills`) paths checked
  against their docs (2026-10); Grok also reads `CLAUDE.md` and
  `.claude/skills`.
- **Migration book:** 50 entries, for the user's two other sites (still on an
  older Gaffer). Keep adding; delete when the user says they're updated.
- **Blueprint state:** clean `doctor --wp` (no warnings). Twig debug follows
  `WP_DEBUG` (on in blueprint's wp-config). `WP_ENVIRONMENT_TYPE` may not be
  set in its `wp-config.php` yet (admin bar dot shows red then); that file is
  the user's to edit.

## Open

- **From migrating thenewbride (2026-10-04), for the stability pass:**
  - Standalone documents (a print page with its own `<html>`) have no place:
    "HTML in PHP" outside `header.php`/`footer.php`, but they can't use the
    shell either. Decide the convention (a second shell? a Twig document?).

- **Forms, maybe later:** file uploads, multi-page forms, Gravity Forms'
  combined fields (Name, Address), conditional logic. Decided: Turnstile is
  the only captcha (no reCAPTCHA provider); Gravity Forms is always the
  engine.
- **Block fields, maybe:** `migrate:rollback --skip-changed` if refusing the
  whole rollback over one edited page turns out to get in the way.
- **AI boost, maybe:** more MCP tools when a need shows up (decided against:
  `eval`, anything that writes, what the AGENTS.md reference already has); more
  agent adapters (Cursor, Copilot, Gemini; Boost's `src/Install/Agents/*` has
  their guideline, skill and MCP config paths); a Yoast guideline
  (Yoast owns SEO output, the theme only styles breadcrumbs; skipped for now).
