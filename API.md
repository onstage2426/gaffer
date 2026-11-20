# Gaffer's public API

What a site's code may rely on. From 1.0 on, nothing listed here changes in a
minor or patch release: it's deprecated first (see Deprecating) and removed in
the next major. Everything else in `src/` is internal (`@internal`) and may
change in any release; `doctor` warns when theme code uses it.

The promise covers what runs on the site: the PHP a theme calls, the Twig it
renders, the JavaScript it imports, and the data and conventions below. The
tooling (the CLI, `doctor`, the MCP tools, the generated AI files) only runs
while building, so a change there can't break a site: it may change in a minor
release, always with a changelog entry. That keeps 1.x long-lived.

**The PHP API** is every class and member in `src/` that isn't marked
`@internal` (and nothing in `Console\`, `Ai\` or `Mcp\` but `Console\Console`);
its docblocks document it (`vendor/onstage2426/gaffer/src/`). `tests/api.txt`
snapshots its signatures: `composer test` fails when they change, until
`UPDATE_API=1 composer test` updates it on purpose, so the diff shows in review.

Not covered: the tooling (see Tooling below), the wording of messages, and
what's **experimental** until the plugin it builds on reaches 1.0: the MCP
server (`gaffer`, its tools and inputs; MCP Adapter) and Search (everything
under Search below: `Gaffer\Search\*`, `config/search.php`'s keys and the
JavaScript in `resources/js/search.js`; gaffer-search). Experimental parts may
change in a minor release, always with a changelog entry.

## Supported versions

Gaffer aims for the latest: PHP (`composer.json`), WordPress, ACF Pro,
WooCommerce, Gravity Forms and Twig 3. Sites keep Gaffer and their plugins
updated; a minor release may start relying on what the current plugin
versions offer. No minimum plugin versions are declared or checked.

## Using the PHP API

The classes and what each member does are in their docblocks. What those can't show:

**Entry points** (in the theme's own files): `Gaffer::boot()` in `functions.php`,
`Ajax::boot()` in `ajax.php`, `Console::boot()` in `gaffer`, and
`vendor/onstage2426/gaffer/bin/stage.php` (`wp --require=` in a staged copy of the theme).

**Types** (`Gaffer\Types\`): missing data is `null`; `meta()` is null for a key the object
doesn't have (the stored value otherwise, `''` included); `link()` is a string (`''` only for
broken data). **Theme subclasses** of `Post` and `Term` (mapped in `theme.types` /
`theme.terms`) may add methods and override `thumbnail()`; every other public method is
`final`, and the constructor and factories are not theirs to change. A new public method on
`Post`, `Term`, `Attachment` or `Image` (which could clash with a subclass's own method of that
name) comes only in a major, or in a minor after one minor in which `doctor` warned about theme
methods with that name.

**Ajax**: an action extends `AjaxAction` and declares `run()` with the documented parameter
types (scalars, `array`, `Post`/`Term`/`Image` and theme subclasses, nullable, defaults). An
ajax request larger than PHP's `post_max_size` is answered with 413.

**Forms**: the theme's form action extends `Forms\FormAction`; every form template includes
`@gaffer/forms/meta.twig` (below).

**Search** is experimental (see above): `Gaffer\Search\*` and `config/search.php`'s keys,
with the gaffer-search plugin.

## Twig

The functions `config()` and `ajax_url()`; the filter `t`; the namespaces `@block` and
`@gaffer`; `strict_variables` with `WP_DEBUG`. Gaffer's partials:

- `@gaffer/forms/meta.twig` inside every form: `form`, and an optional `turnstile`
  (`appearance`, `language`, `theme`, `size`, `class`).

## JavaScript

- `resources/js/forms.js`: `shrinkUploads()` (inputs with `data-gaffer-resize`; the form has
  `aria-busy="true"` while it runs).
- `resources/js/search.js` (experimental): a failed request's error has `stale`: true for any
  4xx (the page needs a refresh). Gaffer reserves, in the objects its JavaScript returns
  (`createAlpineSearch()`, `createFilterForm()`, ...), every name starting with `_` and every
  name it ships: a theme's mixin adds its own names and calls the shipped ones, it doesn't
  redefine them.

## Data and conventions

These live in databases and theme folders, so a change breaks sites even
without a code change:

- **The theme layout:** `views/`, `blocks/`, `fields/`, `inc/` (`*.php` then
  `*/*.php`), `app/` (`Theme\`), `config/`, `public/`, `assets/`, `storage/`;
  `GAFFER_STORAGE`; ajax actions as `app/Ajax/{Name}/{Name}.php` (`Theme\Ajax\{Name}\{Name}`).
- **Blocks:** `block.json` with `acf.renderTemplate`; `fields.php` (a list of ACF
  field arrays without keys) and **the derived keys**:
  `group_{name}`, `field_{name}__{field}`, sub fields `…__{parent}__{field}`,
  layout fields `{type}-{label}`. Stored in post content: changing the
  formula orphans every site's block data.
- **Field groups:** `fields/{param}/{value}.php` for the params
  `options_page`, `post_type`, `taxonomy` (the same list of fields) and their
  derived keys: `group_{param}_{value}`, `field_{param}_{value}__{field}`, sub
  fields and layout fields as for blocks. Stored next to every value in
  postmeta, termmeta and options.
- **Config keys** in `config/*.php` stubs (`theme.*`, `turnstile.*`,
  `console.*`, `ai.*`) and the theme's `.env` (`SITE_URL`).
- **Forms:** template `views/forms/{title slug}.twig`; fields by Admin Field
  Label; the request keys `fields[...]`, `files[...]` (multipart) and
  `kept_files[...][]`. The rest of what a form sends (its name, the honeypot,
  Turnstile's token) is Gaffer's own, written by `@gaffer/forms/meta.twig` and
  read by `FormAction`: it may change in any release, both ends together.
  Gaffer loads Turnstile's script.
- **The build:** `vite.config.js` uses Gaffer's plugin (`resources/vite.js`,
  `gaffer()`): it owns the settings Gaffer reads (`public/`, the manifest, the dev
  hotfile, `public/.vite/gaffer.json`) and the `@gaffer` alias. Gaffer's PHP and the
  bundled JavaScript of one release work with the previous release's other half
  (a composer update changes the PHP at once, the JavaScript with the next build).
- **Storage formats:** `storage/backups/migrate/*.json` (rollback reads old
  backups) and `storage/logs/migrate.log`.

## Tooling

Not part of the promise above: commands, their options, output and exit codes,
`--json` shapes, `doctor`'s checks and severities, the MCP tools, and the
generated AI guidelines and skills may change in a minor release (with a
changelog entry). What stays, because theme files or stored files depend on it:

- The entry points in the theme's own files: the `gaffer` script
  (`Console::boot()`), `bin/stage.php`, and `php gaffer ai:update` (the
  theme's `post-update-cmd` script).
- Files Gaffer wrote before are read by later versions or refused with a
  message: `doctor-baseline.json` (`"version"`), migrate backups (rollback),
  render snapshots (`"format"`: take them again).
- The hidden `render:page` is a protocol between Gaffer versions (the live
  theme's Gaffer runs a staged copy's): its options and its result line
  (`"v": 1`) only grow.
- Checks that depend on third-party plugins (the MCP server, gaffer-search's
  `problems()`) and pattern-based template checks (wptexturize) are warnings,
  never errors, so a plugin release can't make `doctor` fail.

## Deprecating

- **Methods and constants:** PHP's own attribute,
  `#[\Deprecated(message: 'use fresh()', since: '1.3')]`. PHP raises
  `E_USER_DEPRECATED` on every call (shown/logged like any notice under
  `WP_DEBUG`), `doctor` warns about theme code that uses it (PHP and Twig;
  instance methods by name), and `tests/api.txt` marks it ` #[Deprecated]`. The old
  member keeps working, usually by calling the new one.
- **Twig functions and filters:** `deprecationInfo: new
  DeprecatedCallableInfo('onstage2426/gaffer', '1.3', 'fresh')` on the
  `#[AsTwigFunction]`/`#[AsTwigFilter]`. Twig triggers it while compiling;
  `doctor` reports it with the template and line.
- **Classes:** PHP can't mark a class: deprecate its factories and methods.
- **Data and conventions** (config keys, layout, request keys):
  keep accepting the old form and add a `doctor` warning naming the new one.
  An error (like `ConfigCheck::MOVED`) only comes with the major that
  removes it.
- Every deprecation goes in the changelog of its release; the next major
  removes them all.
