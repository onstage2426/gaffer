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
- **Pushing works from here** (`git push origin 0.x`, cached credentials).
  Push when a Gaffer change is done and verified, then update the sites.
- **Public API:** `API.md` (what themes may rely on) and `api.txt` (its
  signatures, checked by `tests/ApiTest.php`). Anything else is `@internal`.
  Changing the public API is a decision: deprecate first (`#[\Deprecated]`,
  see "Deprecating" in `API.md`), and update the snapshot on purpose
  (`UPDATE_API=1 composer test`).
- **Before every commit:** (CI runs the same: `.github/workflows/ci.yml`)
  `composer test` (PHPUnit) and
  `vendor/bin/phpstan analyse --memory-limit=1G` (level 6, **no baseline**:
  fix types instead of ignoring them).
- **Integration test site:** the blueprint theme,
  `~/docker/appdata/websites/blueprint/wp-content/themes/blueprint`
  (see its own CLAUDE.md). After pushing:
  `composer update onstage2426/gaffer` there, adapt blueprint, then
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
  breaks blueprint until it's updated, so Gaffer and blueprint changes land
  in one cycle: Gaffer commit → push → update blueprint (and thenewbride)
  immediately.

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
- Twig cache stays off by default (when a theme turns it on, `auto_reload` keeps it fresh).
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

## Architecture

| Area | Files |
|---|---|
| Boot | `Gaffer::configure()` (theme root + config, no WordPress), `Gaffer::boot()` (Twig, `inc/`, ACF JSON path, blocks, admin bar), `Gaffer::twig()` (SHORTINIT), `Gaffer::version()` |
| Blocks as content | `Blocks::markup()` (public): an ACF block's comment from values by field name, via `BlockFields::group()` (the derived keys), repeaters as count + `name_{i}_sub`, true/false `"1"`/`"0"`, images/files int IDs, links `{title,url,target}`, lists of IDs as strings (as ACF stores them); refuses unknown fields (did-you-mean), layout fields and groups (not written yet: unverified storage); escapes exactly like `serialize_block_attributes()` (round-trips through `parse_blocks()`/`serialize_block()`). Writers must `wp_slash()` it for `wp_insert_post()`/`wp_update_post()` |
| Forms | `Forms\Spam` (`storage/logs/spam.log`), `Forms\GravityForm` (form by title slug, fields by Admin Field Label, `submit()` via `GFAPI::submit_form`, input keys for consent/checkbox), `Forms\FormState` (what a form template shows), `Forms\FormAction` (the one ajax action: honeypot, Turnstile, submit, re-render or `HX-Redirect`; spam gets the normal answer, outside production with `X-Gaffer-Spam`), `Forms\FormTemplates`; `forms:show`; `WordPressCheck::forms`. phpstan knows Gravity Forms through `phpstan/gravityforms.stub` (`scanFiles`) |
| Config | `Config` (theme config), `ConfigStubs` (Gaffer's keys + descriptions from `config/*.php`, the theme's key comments), `Console\Env` (the theme's `.env`: `SITE_URL`), `Gaffer::debug()` (= `WP_DEBUG`) |
| Static services | `Storage` (`private_dir()`: logs/backups with deny `.htaccess` + `.gitignore`), `Config`, `Paths` (fixed layout), `View` (render/fetch/share, owns the Twig env), `Acf`, `BlockFields` (`blocks/*/fields.php` → local ACF groups, derived keys), `FieldGroups` (`fields/{options_page|post_type|taxonomy}/{value}.php` → one local group per target, keys `field_{param}_{value}__{name}`; `keyed()` shared with `BlockFields`, also resolves `conditional_logic` by field name (top-level path or a sibling) and refuses unknown names/keys; `target_of()` = the target of a UI group's location when one `==` rule; the meta box title comes from WordPress when ACF loads the group (`acf/load_field_group(s)`): the post type's/taxonomy's singular label or the options page's title; `is_definition()`: field definition files, exempt from `MarkupCheck` and the uploads-path check), `ImageSizes` (`wp_content_img_tag`: takes WordPress's `auto, ` off a `sizes` a template gave `Image::attrs()`), `AcfValues` (`acf/load_value`, every ACF value (blocks, posts, terms, options) except wysiwyg: kses's `&amp;` → `&`, once; never more: decoding `&lt;` could make markup), `Vite`, `Turnstile`, `TwigCache`, `AdminBar` |
| Types | `Types\Post` (+ `Attachment`, `Image`), `Term`, `Menu`/`MenuItem`, `Pagination`. Wrap the WP object (`->wp`), protected constructors (`Types\Wraps`: the factories' shared wrap-and-narrow), factories `from(int)`, `current()`, `query()`. Class maps `theme.types` / `theme.terms` |
| Ajax | `Ajax` (dispatcher, `url()`, `NAMESPACE`, `actions()`/`action_class()`: `app/Ajax/{Name}/{Name}.php`, loaded by the theme's Composer autoloader (no own autoloader), also used by `AjaxCheck` and the reference; renders without `View::share()` data; templates in `views/`, no `@ajax` namespace), `AjaxAction` (`METHOD`/`SHORTINIT` constants), `AjaxArguments` (typed `run()` params incl. route model binding), `AjaxNotFound` |
| Ajax testing | `Console\Ajax\Session` (cookie jar per name, `storage/sessions/{name}.json`; browser rules: empty/`deleted`/expired removes; `woocommerce_customers()` from `wp_woocommerce_session_*`), `ajax:call` (over HTTP via `wp_remote_request` to `Ajax::url()`, the class's METHOD, `HX-Request`, `key=value` params incl. array keys, prints `HX-*`/`X-Gaffer-*`/`Location`, `--select` via `HtmlDiff::select_all`), `ajax:end` (`WC_Session_Handler::delete_session()` per customer, forgets the jar), `--session` on `doctor:render` (`Request::session()`: the jar's cookies into `$_COOKIE`, WooCommerce's session save removed as for `--cart`) and `render:screenshot` (also `context.addCookies`). CLI only, no MCP tool: it writes (settled: MCP tools only read); `gaffer-screenshot` takes `session` (reads it) |
| Search | `Search\` (with the gaffer-search plugin, `~/workspace/gaffer-search`; design and contract there: `design.md`, `docs/contract.md`): `Engine` (the plugin's contract-1 object from `apply_filters('gaffer_search_engine', null, 1)`: `define`/`problems`/`status`/`endpoint`/`search`/`token`/`supports`, every answer type-checked: an odd shape degrades to empty results; the contract only grows from the plugin's first tag on, newer features via `supports()`; reset once on 2026-11-03), `ArchiveUrl` (pure, unit-tested: the archive's normalized URL (canonical, page links, `q`/`filter`/`sort`/`offset`/`limit`, the base's own query kept), `indexable()`, `redirect()`/`translate()` (`/page/N/`, WooCommerce's params, from the definition's fields: `fields()`), `request()` (a URL's params as the first page's search); moved from the plugin: per-site SEO policy doesn't belong behind a frozen contract), `Indexes` (`config/search.php` `indexes` → the engine's definitions: Gaffer's keys `cards` (layout => template, the first the default: field `card`, others `card_{layout}`)/`card_context`/`card_variants`/`card_variant`/`document` (the theme's computed fields) out, `document` closure (theme fields + cards) and `version` (definition + card files + method files + `RENDERING`) in; `card_field()` = the visitor's variant field), `Cards` (renders `card` (+ `card_{variant}`) per document on the site's scheme (WP-CLI without `--url` is http), `compact()`: line-break runs → one line break outside pre/textarea/script/style; `version()` = content hash of the card and everything it includes via `Templates::references()` (patterns globbed, parsed with its own Twig environment), the file list cached in a transient with mtimes so a request only stats them; `code_version()` = the files of the `card_context`/variant methods; all paths relative to the theme), `Search` (public: `archive()` (memoized; WooCommerce shop/product taxonomy), `archive_url()` (a link to an archive with a query), `cards()` (with `filter`), `searchbar()`, `available()`; `archive.eager` → `Cards::eager()` on every page's first N stored cards, N per layout or one int), `Archive`/`Searchbar` (readonly; `initial()` = JSON safe for a script tag, `page_url(n)` with base params and layout), `Integration` (from `Gaffer::boot()`: `define()`, and with `archive`: `template_redirect` 301s via `redirect()` (after the theme's `redirect_params`; its own 301 only when `url()` differs), `?s=` → shop with `redirect_search`, Yoast canonical (prio 20)/robots, `wp_robots`). JS: `resources/js/search.js` (+ `search/`: the plugin's former frontend modules as ES modules, plus `archive()` with `onRender`), tested with bun (`tests/js`, happy-dom; CI runs it). `SearchContractTest` runs Gaffer against the plugin's fixtures (`tests/fixtures/search-contract/`, copied with their MANIFEST: refresh them when the plugin records new cases). `archive` config: search keys (`index`, `per_page`, `limits`, `sorts`, `default_sort`, `facets`, `facet_values`, `max_docs`, `bounds`), `seo` (`index_pages`, `indexable_filters`: the shop only) and `urls` (`base_params` (in `Search::archive_base()`), `redirect_params`, `redirect_search`). `Search::archive()` = `token()` for the archive's request + one `search()` with the URL's params (visitor filter in `filter`; `visibility`/`term` win) + `ArchiveUrl` for canonical/indexable; `?view=` a layout of `cards` (its field, noindex, `views` links with `value`); `bounds` (one extra `search()`: `facetStats`). No contract features yet. Checks: `SearchCheck` (card keys, archive keys and their shapes, `Search::archive()` without any template reading `.available`), `WordPressCheck::search` (engine there, `problems()`, product taxonomies with used archives the index doesn't store, built/current/errors, one card rendered with strict variables). MCP `gaffer-search` (read-only). Guideline/skill `plugins/gaffer-search` (on when the engine answers or the theme has `config/search.php`) |
| Twig | `Twig\Extension`: `config()`, `ajax_url()`, the `t` filter (gettext in `style.css`'s Text Domain) only |
| Render compare | `doctor:render` (one URL in a child process: it starts `bin/render.php` (`Console\Render\Request`), which loads WordPress and `template-loader.php` at its top level like `index.php`, so template files run in the global scope as in a web request (a template's `$page` is WordPress's global, which `get_header()` can overwrite); `Request::preload()` loads every class of the running Gaffer first when its autoloader isn't the theme's (a dev clone); as the theme whose `gaffer` started it (`WordPress::as_this_theme()`, every command: a staged copy, i.e. a Gaffer theme with the active theme's `Theme Name` next to it, is loaded instead via `stylesheet`/`template` filters, with the active theme's `theme_mods_*` and no object cache drop-in (`enable_loading_object_cache_dropin`; the active theme read from the DB, as that's decided before options load); `migrate:* --run` refused there; `bin/stage.php` for `wp --require`); `Console\Render\StagedTheme` validates a `--theme` copy, `Renderer` rewrites its directory name to the live one in the HTML): query string into `$_GET`, `DONOTCACHEPAGE`/`DONOTROCKETOPTIMIZE`, `--user`/`--cart` pre-hooked before WordPress loads and writing nothing (no WooCommerce session save, no persistent cart), nonces pinned (`nonce_life` max, fixed `nonce_user_logged_out`) so renders compare, `time_ms`/`queries`/`twig_ms`; the result is one line after a marker: plugins print after it; `search` = whether the render saw the engine: `Renderer::without_engine()` → an error in `RenderCheck`, a warning in `render:diff`/`render:snapshot`, for a plugin activated only in the parent (a prepend)). `Console\Render\`: `Renderer` (doctor's URL sample, posts using blocks, parallel `doctor:render` children with visitor options; also `RenderCheck`), `HtmlDiff` (pure: `without` (ignore regexes, on the raw page), CSS select (also the MCP render tool's), normalize, line diff: LCS for small gaps, patience (unique-line anchors) for large pages; hunks; `reorder_only()` (removed = added as multisets), `shorten()` (a changed long line as its differing part, text output only); unit-tested), `Snapshot` (`storage/snapshots/{name}.json`; `redirected()`: most pages to one URL = a login/maintenance plugin: `render:snapshot` refuses, `render:diff` warns). `render:screenshot` (`Commands\RenderScreenshot`: renders once via `Renderer` (`as_live: false`, so a `--theme` copy loads its own assets), writes `storage/screenshots/{slug}/page.html`, then `node bin/screenshot.cjs`: Playwright resolved from the theme's `node_modules` (`createRequire`, works for a dev clone too) or a server at `PLAYWRIGHT_WS_ENDPOINT` in `.env`; the first main-frame navigation is answered with the render, everything else goes to the site; console errors and failed requests of the main frame only (Turnstile's iframe is noise); own quiet-network wait after each step (Playwright's `networkidle` is reached once per page); `--click`/`--fill` in command-line order via `getRawTokens()`; no `--user`/`--cart`: shops show cart and login through cookies/ajax, so a visitor's state comes from an `ajax:call` session (`--session`); `ScreenshotCheck` = info when Playwright isn't installed). Commands `render:snapshot` (rendered, `--path`, `--user`/`--cart` stored for the diff, `--theme` a staged copy, or `--from` over HTTP), `render:diff` (`--theme`, `--json`, `--hide-reorders`, `console.render_ignore`) (each distinct change once, with its pages) |
| CLI | `Console\Console`, `Command`, `WordPress` (CLI loader), `Report`, `ConfigStubs`, `ThemeFiles`, `Templates` (template names, includes and variables from Twig's parse tree; used by `twig:lint`, `TemplatesCheck`, the reference), `Commands\*`, `Checks\*` (doctor) |
| Content migrations | `Console\Migrate\`: `BlockData` (pure: rewrites ACF block data, unit-tested), `ContentStore` (find/read/write posts, block widgets and ACF reference rows (`postmeta`/`termmeta`/`option` locations) straight in the DB; `references()` returns `Reference`s (stored name, key, owner and owner type: a revision's parent's), owners and editor locks cached per run), `FieldReferences` (pure, unit-tested: which references are a `fields/` target's own: owner type and stored name matching the field's path pattern, option `post_id` prefixes per WPML/Polylang language; outside and other names are reported, broken keys repaired by name; `leftovers()` = target keys under another name; `name_regex()` for removals), `FieldTarget` (builds it from ACF: the file, the target's UI groups, option prefixes). Deletes: a `Location` with `owner` + `name` can be deleted (`write(…, null)`) and re-inserted by rollback with its own ID; `null` before/after = the row doesn't exist, `Migration` (plan → refuse on any problem → backup → one transaction that re-checks every row → read back → optional value check (`check_values()`: what ACF reads before and after); `summarize()` prints one line per distinct change, `progress()` to stderr; above 200 locations the log line has counts, the backup has each), `Backup` (`storage/backups/migrate/`, checksummed), `Log` (`storage/logs/migrate.log`, JSON line per `--run` with outcome). Commands `migrate:block`, `migrate:field`, `migrate:fields` (blocks, and `fields/` targets: rewrites the `_name` reference rows of a UI group with the same target; `--delete-groups` after success, refused while old-name references remain), `migrate:remove-field`/`migrate:remove-leftovers` (blocks or `fields/` targets; `remove-field` takes a repeater's/group's sub-field rows along (`FieldReferences::removal()`: by their references' derived keys) and works on a target whose file is gone, which `WordPressCheck::deleted_targets` reports), `migrate:remove-block`, `migrate:rollback` (`MigrateCommand` base: `rewrite_blocks()` rewrites each location once for several blocks; `Migration` refuses a second change to one location) |
| (doctor) | `Console\Baseline` (`doctor-baseline.json`: known warnings by check+file+message with counts, `--baseline`/`--prune`/`--all`; keep line numbers and page lists out of warning messages, put them in the hint), `Checks\DeprecationsCheck` (theme uses of `#[\Deprecated]` members of the public API, read by `Console\PublicApi` (also what `ApiTest` snapshots); Gaffer's Twig functions' `deprecationInfo`, caught while parsing templates), `Checks\GuidelinesCheck` (theme `.ai/` mentions of missing paths, `Theme\` classes, `acf/` blocks), `Checks\AppCheck` (snake_case methods in `app/`, `#[\Override]` exempt), `TemplatesCheck` also flags `.get_*()` and `.meta()` calls, `|t` on anything but a literal string and an include inside a `<form>` that brings its own `<form>` (`Templates::includes_in_form()`), `SourceCheck` (`|raw`/`new Markup` without a `trusted:` comment on the line or alone above), `Checks\FieldGroupsCheck` (unknown `fields/` dirs, same name on two options pages, fields nothing mentions), `AcfJsonCheck` (a UI group targeting code is an error, a UI group that could be code a warning, conditional logic on keys the group doesn't have a warning, groups `fields/` can't express one info line), `WordPressCheck::field_groups` (targets registered, stored references to derived keys that no longer exist), `WordPressCheck::acf_orphans` (`acf-field` posts without their group), `WordPressCheck::acf_database_only` (ACF UI field groups, post types, taxonomies, options pages with no JSON; a group whose target is already code gets the migrate hint), `Checks\TexturizeCheck` (`>` inside an attribute in templates block output uses: wptexturize ends the tag there), `Checks\AssetsCheck` (Vite build present, not older than the code in `assets/` (JS/CSS family only: images and fonts there are often served directly), `Vite::` entries in the manifest; skipped in dev mode), `Checks\IncCheck` (functions/classes declared in `inc/`), `MarkupCheck` (HTML in PHP), `Checks\TemplateGlobalsCheck` (the globals `load_template()` and `WP_Query::setup_postdata()` declare, read from WordPress's own source, or a declared `global`, assigned at the top level of a root/`woocommerce/` template file), `TemplatesCheck` |
| MCP | `Mcp\Mcp` (registers the `gaffer/*` abilities and the STDIO-only server `gaffer` when the MCP Adapter plugin is active and the site isn't production, or is with `ai.mcp_production` (a live-site theme migration); `launch()` = `wp mcp-adapter serve --server=gaffer`, with `PHP_BINARY -d memory_limit=<WP_MAX_MEMORY_LIMIT>` + the resolved `wp` file when `wp` is a PHP file (its `env php` may find another PHP version; the CLI php.ini's 128M killed the server on a WooCommerce site): WP-CLI finds WordPress from the theme dir, and the tools allow any WP-CLI call (no `--user`; whoever runs `wp` can do anything anyway)), `Mcp\Tool` + `Mcp\Tools\*` (block-usage, doctor, render (with `user`/`cart`, timing), screenshot (`render:screenshot --json` via `GafferCli`), last-errors (`PHP_ERROR_LOG` in the theme's `.env`), forms; all read-only), `Mcp::served_tools()` (starts `launch()` like an agent and asks `tools/list` over the protocol; `WordPressCheck::mcp` compares it with `Mcp::tools()` (a warning, not checked, when this process has no `wp` on its PATH: the phpfpm container), so adapter updates that break the server show up in `doctor --wp`), `Mcp\GafferCli` (runs the theme's `php gaffer` in a child process: `doctor`, `doctor:render --html`). Protocol is the adapter's job: Gaffer only uses `wp_register_ability()` and `create_server()` (phpstan stub `phpstan/mcp-adapter.stub`) |
| AI ("boost") | `Ai\Agent` (adapters: claude, codex, grok; paths as in Laravel Boost), `Ai\Guidelines` (Gaffer's `resources/ai/guidelines/` + plugin guidelines for active plugins (WooCommerce, ACF, Gravity Forms, gaffer-search, MCP Adapter) + the theme's `.ai/guidelines/`, same file name overrides; `doctor` lists overrides as info), `Ai\Reference` (generated from the theme's code: config, Twig, types, ajax, blocks, views, `inc/` hooks by comment; written as the generated skill `gaffer-reference`, AGENTS.md only points to it), `Ai\McpConfig` (Gaffer's entry in an agent's project MCP config: JSON, or TOML edited as text, only Gaffer's own table), `Ai\Installer` (writes `AGENTS.md`, agent files like `CLAUDE.md` = `@AGENTS.md`, skills (Gaffer's + active plugins' + the theme's `.ai/skills/`), the `.gitignore` block; removes deselected agents' output; `clear()`). Commands `ai:install`, `ai:update`, `ai:clear`; both writers always load WordPress (the reference needs the booted theme's `View::share()` data) |
| AI sources | `resources/ai/guidelines/*.md` (+ `plugins/{plugin}.md`), `resources/ai/skills/{name}/SKILL.md` (+ `plugins/{plugin}/{name}/`). Edit these when Gaffer's behavior changes, then `ai:update` in blueprint. AGENTS.md loads on every turn: guidelines hold rules and decisions; procedures and reference detail (migrations, render compare, a plugin's full API) go in skills, with a pointer and the must-know rules left in the guideline |
| Config stubs | `config/*.php`: the reference list of every config key (all commented out). New keys go here; `config:show` and `doctor` read them |
| Tests | `tests/` (+ `tests/stubs/wordpress.php` for the few WP functions unit tests touch) |

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
- Testing a content migration: compare rendered pages before/after (blueprint
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
  server (container, `/usr/local/apache2/htdocs/…`) and the CLI (host, `/home/devgoat/…`) see
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
- Never tell a live site to `wp cache flush` (or `redis-cli FLUSHALL`): on Bestratingsmarkt
  (Redis, 2M+ keys) the flush blocked Redis and the site answered with WordPress's Redis error
  for a few seconds (2026-11-04). After deleting options straight in the database, delete only
  the stale entries per site: `wp cache delete alloptions options --url=<site>` and
  `wp cache delete notoptions options --url=<site>`.

## Current state (2026-10-31)

- **Sites** (all in `~/docker/appdata/websites/{site}/wp-content/themes/{theme}`):
  - blueprint (the integration site and starter; commit and push it with each
    Gaffer cycle);
  - thenewbride (dev site, its own `.ai/guidelines/`; its 16 UI field groups are
    in `fields/` since 2026-10-20; usually has the user's uncommitted work: don't
    commit there);
  - a4hydrauliek (theme a4hydrauliek, WPML, built from Figma by its own Claude
    Code: uncommitted work in progress, don't commit there; Gravity Forms by
    shortcode, no headless forms; `doctor-baseline.json` holds its config
    strings translated with `|t`);
  - blueprint-base (theme blueprint: multisite + WPML test network, free to
    change, not a git repository; still has 11 stale block field groups only in
    its database);
  - verdemare-wp (theme `blueprint`: a full copy of blueprint, code and
    database, made into a WooCommerce site by its own Claude Code; its field
    report of 2026-10-31 drove the memory, `SITE_URL`, `remove-field`, spam
    header and `|raw` trust changes; the user's demo site: we may change and
    commit there, never push (its `origin` is blueprint's repo); the search
    test site);
  - a live site on another server (no WooCommerce, forms or search; WP Rocket);
  - Bestratingsmarkt, a large live WooCommerce multisite (WP Rocket, Redis,
    YITH filters, Gravity Forms shortcodes) converted to Gaffer by its own
    Claude Code without staging: its feedback drives the render/doctor/migrate
    tooling (it moved 34 UI field groups, ~173,000 references, to `fields/`).
  Blueprint and thenewbride keep WordPress's 1536/2048 image sizes (re-added
  2026-10-11, thumbnails not regenerated).
- **Before 1.0, done:** public API (`API.md`, `api.txt` snapshot, `@internal`
  on the rest), deprecations (`#[\Deprecated]` + `doctor`), `symfony/console`
  a runtime requirement, CI (`.github/workflows/ci.yml`), supported versions
  (always the latest; MCP experimental). **Left:** the fresh
  repository, a README, the tag (`CHANGELOG.md` started 2026-10-20).
- **MCP:** the MCP Adapter plugin (0.7.x) is on both dev sites, never on
  production; `doctor --wp` checks the server over the protocol. Claude Code
  in Zed uses it via the generated `.mcp.json`.
- **Screenshots on this host:** Chromium runs locally (its system libraries were
  installed with dnf on 2026-11-01; Playwright warns the OS isn't officially
  supported and uses its Ubuntu build, which works). Blueprint has `playwright`
  as a dev dependency (bun); other themes: `npm install --save-dev playwright`.
- **Testing unpushed Gaffer against a theme:** the throwaway entry file
  described above (`/tmp/gaffer-test`; a copy with the theme path swapped for
  thenewbride). For WP-CLI (the MCP server): `wp --require=<file that preloads
  every clone class>` and the theme's `gaffer` entry pointed at the clone
  temporarily (`git checkout gaffer` afterwards).
- **Search:** gaffer-search (`~/workspace/gaffer-search`, private repo
  `onstage2426/gaffer-search`; push it when a change is verified, the user tags releases) is
  Gaffer's search engine behind a data-only contract (`docs/contract.md` there, frozen
  fixtures); `Gaffer\Search` is the theme side (see Architecture). It continues fuzor-wp
  (`~/workspace/fuzor-wp`, abandoned for good: never propose it again); the Fuzor library stays
  standalone. Every site switched by
  2026-11-04 with identical archives, canonicals, redirects and searches (verdemare,
  thenewbride, Bestratingsmarkt after six staging rounds, blueprint, blueprint-base); fuzor-wp was
  deleted from blueprint, blueprint-base and verdemare on 2026-11-04 (backup and RESTORE.md in
  `~/backups/fuzor-wp-delete-2026-11-04/`); Bestratingsmarkt deleted it the same day (its
  inactive `themes/bestratingsmarkt-bootstrap` rollback copy still has fuzor code; the user's
  call); thenewbride's rebuild got the prompt (`~/handoff/fuzor-wp-delete/`), no report yet. Earlier backups:
  `~/backups/verdemare-wp-2026-11-02-pre-gaffer-search/`,
  `~/backups/fuzor-to-gaffer-search-2026-11-04/`. The migration support (fuzor guideline and
  skill, old-API warnings, moved-key messages, `console.raw_baseline`) was purged 2026-11-04.
  Decided out: cards-by-ID tokens (an ajax action renders them), facet search, audiences.

## Open

- **gaffer-search before its first tag** (agreed 2026-11-03, not started; the contract only grows
  from the tag on):
  1. Products' standard fields (`price`, `regular_price`, `sale_price`, `stock_status`, `sku`,
     `rating`) built in from `WC_Product`, so themes stop mapping `_price`/`_stock_status` meta:
     the contract mustn't depend on WooCommerce keeping products in post meta (its product
     tables). Breaking for every `config/search.php` (remove those `meta` lines).
  2. Fixtures for what the contract promises but only checks the shape of: `direct_terms` (a
     category search without its children's products), `fields` (typed values in results),
     `facet_values` and `max_docs` honored by `search()`, `limits` in `search()`.
  3. Delete dead internals: `Audience` (customer groups, decided against) and fuzor-wp's global
     helpers in `Functions.php` (into classes).
  The browser side (endpoint params, token, error codes) freezes with it: cached pages keep old
  JS and tokens for days.
- **Why gaffer-search, not a general fuzor-wp** (the user asked; answer of 2026-11-03): each
  fuzor-wp theme wrote its own glue (tokens, SEO/redirect hooks); with gaffer-search it's config.
  Evidence, Bestratingsmarkt round 4: all 2,081 archives, canonicals, robots and redirects
  identical to live, its SEO hooks 91 → 24 lines. Cost: four rounds and ~25 changes on our side,
  and it only serves Gaffer themes (the Fuzor library stays general).
- **At 1.0:** the CHANGELOG starts fresh; gaffer-search's `docs/migration.md` at its first tag.
- **Customer groups and prices** (a Bestratingsmarkt client's request, 2026-11-03; only an
  answer so far): groups, group prices and discounts, personal price agreements, and a periodic
  job moving customers to another group by revenue over a period. WooCommerce B2B work (a B2B
  plugin, or theme code: a group on the user, prices per group, the price filters, an Action
  Scheduler job that sums completed orders and logs the moves). Search side: a few groups = card
  variants (one stored card per group, ~30 MB each there; their tokens already expire after a
  day); sorting/filtering by a group's price needs a price field per variant in Gaffer (not when a
  group's discount is one percentage on everything: same order); personal prices can't be
  indexed (per customer): product page, cart, or loaded into the card per visitor. Open questions
  for the client: how many groups, percentage or per-product prices, personal prices on
  archives or only product page/cart, the exact move condition.
- **Standalone documents** (thenewbride's print page, with its own `<html>`):
  `doctor` warns "HTML in PHP"; accepted there (its `site.md` says why).
  Decided: no convention for one case. Revisit when a second site needs a
  standalone document (print view, PDF export, email preview).
- **Forms, maybe later:** file uploads, multi-page forms, Gravity Forms'
  combined fields (Name, Address), conditional logic. Decided: Turnstile is
  the only captcha (no reCAPTCHA provider); Gravity Forms is always the
  engine.
- **Generated files, maybe** (PDFs, images, exports a site makes): a public
  writable directory, e.g. `Storage::private_dir()` as public API (deny-all,
  downloads stream through an action). Asked for by a site that writes PDFs to
  `uploads/`; the user thinks it may be a good idea, not decided yet.
- **Block fields, maybe:** `migrate:rollback --skip-changed` if refusing the
  whole rollback over one edited page turns out to get in the way.
- **AI boost, maybe:** more MCP tools when a need shows up (decided against:
  `eval`, anything that writes, what the `gaffer-reference` skill already has); more
  agent adapters (Cursor, Copilot, Gemini; Boost's `src/Install/Agents/*` has
  their guideline, skill and MCP config paths); a Yoast guideline
  (Yoast owns SEO output, the theme only styles breadcrumbs; skipped for now).
