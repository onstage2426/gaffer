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
| Static services | `Config`, `Paths` (fixed layout), `View` (render/fetch/share, owns the Twig env), `Acf`, `BlockFields` (`blocks/*/fields.php` → local ACF groups, derived keys), `Vite`, `Turnstile`, `TwigCache`, `AdminBar` |
| Types | `Types\Post` (+ `Attachment`, `Image`), `Term`, `Menu`/`MenuItem`, `Pagination`. Wrap the WP object (`->wp`), protected constructors, factories `from(int)`, `current()`, `query()`. Class maps `theme.types` / `theme.terms` |
| Ajax | `Ajax` (dispatcher, `url()`, `NAMESPACE`), `AjaxAction` (`METHOD`/`SHORTINIT` constants), `AjaxArguments` (typed `run()` params incl. route model binding), `AjaxNotFound` |
| Twig | `Twig\Extension`: `config()`, `ajax_url()` only |
| CLI | `Console\Console`, `Command`, `WordPress` (CLI loader), `Report`, `ConfigStubs`, `ThemeFiles`, `Commands\*`, `Checks\*` (doctor) |
| Content migrations | `Console\Migrate\`: `BlockData` (pure: rewrites ACF block data, unit-tested), `ContentStore` (find/read/write posts + block widgets straight in the DB), `Migration` (plan → refuse on any problem → backup → one transaction that re-checks every row → read back), `Backup` (`storage/backups/migrate/`, checksummed). Commands `migrate:block`, `migrate:field`, `migrate:rollback` (`MigrateCommand` base) |
| AI ("boost") | `Ai\Agent` (adapters: claude, codex, grok; paths as in Laravel Boost), `Ai\Guidelines` (Gaffer's `resources/ai/guidelines/` + plugin guidelines + the theme's `.ai/guidelines/`, same file name overrides), `Ai\Reference` (generated from the theme's code), `Ai\Installer` (writes `AGENTS.md`, agent files like `CLAUDE.md` = `@AGENTS.md`, skills, the `.gitignore` block; removes deselected agents' output; `clear()`). Commands `ai:install`, `ai:update`, `ai:clear` |
| AI sources | `resources/ai/guidelines/*.md` (+ `plugins/`), `resources/ai/skills/{name}/SKILL.md`. Edit these when Gaffer's behavior changes, then `ai:update` in blueprint |
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
- Twig `strict_variables` (on with `theme.debug`) is what catches template
  bugs; `doctor --wp` forces it on when rendering.
- WordPress's `wp_get_environment_type()` is `production` unless
  `WP_ENVIRONMENT_TYPE` is defined.
- `wp_check_post_lock()` is admin-only (`wp-admin/includes/post.php`): the
  CLI reads `_edit_lock` itself. A script that loads `wp-load.php` must not
  use a global `$theme`: WordPress overwrites it while loading.
- Testing a content migration: compare rendered pages before/after (blueprint
  has a time-based marquee delay and best-seller ties in random order:
  normalize/sort lines), compare `sha1(post_content)` per post with the
  original after rollback, and dump the database first (`mariadb-dump`
  inside the `mariadb` container, password from its secret file, never
  through your hands).
- Apache sends `.php` to php-fpm with `ProxyPassMatch`, so `.htaccess` rules
  don't apply to PHP files (homelab to-do: switch to `SetHandler`).

## Current state (2026-10-02)

- **Pushed through `eb2d42f`**; blueprint is on it (`921ad47`). Unpushed:
  `migrate:block` / `migrate:field` / `migrate:rollback`. Tested against
  blueprint (all reverted afterwards, database identical to before): field,
  sub field, repeater, block and two-page block renames, each with run →
  identical rendering → rollback → identical content; refusals for
  round-trip failure, random keys, collisions, edit lock, an edited page on
  rollback, a concurrent edit mid-transaction, tampered backup. Blueprint
  needs nothing after the push except `composer update`.
  Blueprint's `composer.json` runs `php gaffer ai:update` after every
  `composer update` (`post-update-cmd`).
- **Migration book:** 34 entries, for the user's two other sites (still on an
  older Gaffer). Keep adding; delete when the user says they're updated.
- **Blueprint state:** clean `doctor --wp` except two known warnings
  (hardcoded Gravity Forms IDs in `inc/rest/`, waiting for the forms round).
  Its `config/theme.php` has a local, uncommitted `'debug' => true`.
  `WP_ENVIRONMENT_TYPE` may not be set in its `wp-config.php` yet (admin bar
  dot shows red then); that file is the user's to edit.

## Open

- **Forms round:** captcha providers (Turnstile works on any domain; a
  reCAPTCHA provider for clients who want it), Gravity Forms form/field IDs to
  config, moving `inc/rest/` contact/newsletter into `ajax/` with a JSON
  response helper on `AjaxAction`.
- **Block fields, maybe:** a `doctor --wp` check that every `type` in
  `fields.php` is a registered ACF field type. `migrate:rollback
  --skip-changed` if refusing the whole rollback over one edited page turns
  out to get in the way. Flexible content layouts aren't keyed by
  `BlockFields` (no theme uses them yet).
- **AI boost, next:** an MCP server (blocks + ACF fields, hooks, render a
  template, last error; build it on WordPress's Abilities API so it sits
  next to ACF's own abilities), more agent adapters when someone uses them (Cursor,
  Copilot, Gemini), maybe versioned plugin guidelines.
- **Homelab:** `~/docker/to-do.md` has the Apache `SetHandler` change (PHP
  files currently bypass `.htaccess`).
