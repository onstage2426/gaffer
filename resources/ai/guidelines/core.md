## Gaffer

This WordPress theme is built on **Gaffer** (`onstage2426/gaffer`), a theme
framework installed with Composer: Twig views, typed wrappers around WordPress
objects, an ajax dispatcher, Vite assets and a CLI. The theme only holds
site-specific code.

- **Never edit `vendor/`.** Framework changes belong in Gaffer; theme behavior
  goes in the theme (config, `inc/`, subclasses in `app/`).
- **Only the public API:** `vendor/onstage2426/gaffer/API.md` says what a theme
  may use: the PHP not marked `@internal` (its docblocks in `src/` document it)
  and the conventions. Everything else in Gaffer is internal and changes
  without notice (`doctor` warns). Missing something?
  Write it in your feedback to the Gaffer maintainers instead of reaching in.
- **The layout is fixed:** `views/` (Twig), `blocks/` (ACF blocks), `fields/`
  (ACF field groups of options pages, post types, taxonomies), `inc/` (hooks,
  auto-included), `app/` (`Theme\`: types, ajax actions in `Theme\Ajax\`,
  Twig extensions, shared fields), `config/`, `assets/` (sources), `public/`
  (Vite build), `acf-json/` (ACF UI JSON, committed), `storage/` (Twig cache and
  tooling files: nothing that can't be made again). Private files of the site's own
  (uploaded documents, generated PDFs; Gaffer's logs and backups) go in
  `Gaffer\Storage::private_dir('offers/' . $id)` (`uploads/gaffer/`, per site, with a
  deny `.htaccess`: other servers than Apache need their own rule), never a public
  uploads folder; serve them through code that checks access.
- **Boot:** `functions.php` calls `Gaffer\Gaffer::boot(__DIR__)`, `ajax.php`
  calls `Gaffer\Ajax::boot(__DIR__)`. Paths in the theme:
  `Gaffer\Paths::base('relative/path')`.

### Standards

- PHP 8.5, PSR-12 with **snake_case method names**, explicit return types,
  `match` over `if/elseif` chains, single-use variables inlined.
- **One way to do each thing.** No alias methods, no "accepts anything"
  signatures, no magic fallbacks: missing data is `null` and the caller
  decides what to show.
- **PHP prepares data, Twig presents it.** Template files and block
  `functions.php` gather data and call `View::render()`; templates can't look
  anything up.
- Root-cause fixes over workarounds, even when they change more.

### Decided (don't propose otherwise)

- No nonces on ajax actions (pages are cached: nonces go stale).
- ACF block editor previews are empty on purpose (`is_admin()` guard).
- The Twig cache is on and keeps itself fresh (a changed template compiles again, also after a
  rollback): no `twig:clear` after edits.
- No PHPUnit or phpstan in the theme: the `php gaffer` checks are the tests.
- Themes are standalone, never child themes.
- No InnerBlocks and no flexible content fields; rich text is a wysiwyg field.

### WordPress gotchas

- `get_queried_object()` can be `null` (`?->`); `get_term_children()` returns
  `WP_Error` for an unknown taxonomy; `wp_redirect()`'s third parameter is a
  label, not a boolean; `get_post(0)` is the current post (Gaffer's factories
  return `null` for `0`).
- Template files run in the global scope: never assign a WordPress global
  there (`$post`, `$posts`, `$wp_query`, `$page`, `$id`, `$product` in
  WooCommerce templates, ...): WordPress resets them inside `get_header()`.
  Use another name (`$current`); `doctor` warns.

### Updating Gaffer

After `composer update onstage2426/gaffer`, read the entries in
`vendor/onstage2426/gaffer/CHANGELOG.md` since the previous version and do
what they say (each has a grep and what to check). `composer update` runs
`php gaffer ai:update`, so these guidelines are already the new version's.

### Check your work

```
php gaffer twig:lint      # every template compiles
php gaffer doctor         # conventions, seconds
php gaffer doctor --wp    # + WordPress: stored content, renders every page with strict variables
```

Fix what they report, deprecated Gaffer API included (`doctor` names the
replacement). They can't tell whether a page *looks* right: after a visual
change, look at it with `php gaffer render:screenshot <path>` and read the
PNGs; to build from a design, measure with `render:compare` and
`render:styles`. Follow the `gaffer-browser` skill for both.

The theme's own `.ai/guidelines/` hold its conventions and decisions, short
(they load on every turn; `doctor` warns above 30 KB): procedures and plans go
in `.ai/skills/`, and what exists is already in `gaffer-reference`. Update
them when you rename or delete what they mention (`doctor` reports it).
