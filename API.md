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

**Experimental** is also how new runtime API arrives in a 1.x minor: a class or member marked
`@experimental` in its docblock (and in `tests/api.txt`) may still change in the next minor, with
a changelog entry and, where it's mechanical, an automatic migration; from the minor after, it's
promised like the rest. `doctor` lists the experimental API a theme uses (as info). Search's
classes carry the marker until gaffer-search 1.0.

## Supported versions

Gaffer aims for the latest: PHP (`composer.json`), WordPress, ACF Pro,
WooCommerce, Gravity Forms and Twig 3. Sites keep Gaffer and their plugins
updated; a minor release may start relying on what the current plugin
versions offer. No minimum plugin versions are declared or checked.

- **Minimums may rise in a minor:** PHP (composer then keeps a site on the Gaffer its PHP
  allows until the host catches up), WordPress and the plugins above.
- **A library major that changes theme code is a Gaffer major,** unless it can be migrated
  automatically: Twig stays 3 for all of 1.x. Gaffer's JavaScript supports the next htmx major
  next to htmx 4 for at least one minor before it requires it.

## Using the PHP API

The classes and what each member does are in their docblocks. What those can't show:

**Entry points** (in the theme's own files): `Gaffer::boot()` in `functions.php`,
`Ajax::boot()` in `ajax.php`, `Console::boot()` in `gaffer`, and
`vendor/onstage2426/gaffer/bin/stage.php` (`wp --require=` in a staged copy of the theme).

**Types** (`Gaffer\Types\`): missing data is `null`; `meta()` is null for a key the object
doesn't have (the stored value otherwise, `''` included); `link()` is a string (`''` only for
broken data). **Theme subclasses** of `Post` and `Term` (mapped in `theme.types` /
`theme.terms`) may add methods and override `thumbnail()`; every other public method is
`final`, and the constructor and factories are not theirs to change. A theme's `product` type
extends `Types\Product` (WooCommerce; `doctor` checks), which keeps the same rule, plus
`confirm_purchase()`, `price_changed_notice()` and `order_price_changed_notice()`: overriding `confirm_purchase()` makes Gaffer ask
it at every step that sells the product (adding to a cart by any route, the cart, the checkout,
paying an existing order) and refuse a checkout or payment whose price changed since the customer
saw it. A new public method on
`Post`, `Product`, `Term`, `Attachment` or `Image` (which could clash with a subclass's own method of that
name) comes only in a major, or in a minor after one minor in which `doctor` warned about theme
methods with that name.

**Ajax**: an action extends `AjaxAction` and declares `run()` with the documented parameter
types (scalars, `array`, `Post`/`Term`/`Image` and theme subclasses, nullable, defaults). What
`ajax.php` answers before `run()`, with no body and `HX-Reswap: none` (htmx fires its error
event and keeps the target): 400 for a malformed action name or an argument of the wrong type,
404 for an action that doesn't exist or a post/term argument that doesn't, 405 for the wrong
method, 413 for a request larger than PHP's `post_max_size`, 500 for an action file without its
class. Every answer has `Cache-Control: no-store` and `X-Robots-Tag: noindex`. An action renders
without `View::share()`'s data (that's for the page shell).

**Forms**: every form posts to Gaffer's `Form` action (`Forms\FormAction`; a theme's own `app/Ajax/Form/` replaces it); every form template includes
`@gaffer/forms/meta.twig` (below).

**Search** is experimental (see above): `Gaffer\Search\*` and `config/search.php`'s keys,
with the gaffer-search plugin.

## Twig

The functions `config()` and `ajax_url()`; the filter `t` (needs the `Text Domain` header in
the theme's `style.css`); the namespaces `@block` and
`@gaffer`; `strict_variables` with `WP_DEBUG`. Gaffer's partials:

- `@gaffer/forms/meta.twig` inside every form: `form`, and an optional `turnstile`
  (`appearance`, `language`, `theme`, `size`, `class`).

## JavaScript

Only what's listed here; other exports of Gaffer's JavaScript files are internal (tests use
them).

- `resources/js/forms.js`: `shrinkUploads()` (inputs with `data-gaffer-resize`; the form has
  `aria-busy="true"` while it runs).
- `resources/js/search.js` (experimental): a failed request's error has `stale`: true for a
  4xx but `token_required` (the page needs a refresh). Gaffer reserves, in the objects its JavaScript returns
  (`createAlpineSearch()`, `createFilterForm()`, ...), every name starting with `_` and every
  name it ships: a theme's mixin adds its own names and calls the shipped ones, it doesn't
  redefine them.

## Data and conventions

These live in databases and theme folders, so a change breaks sites even
without a code change. What ends up stored (block names, field keys, the purchase guard's
keys, the formats of files Gaffer reads back) is frozen for 1.x: `tests/stored.txt` pins each
rule with examples. Anything Gaffer stores that ever must change is read in both forms and
written in the new one for the rest of the major, so stored data needs no migration step.

- **The theme layout:** `views/`, `blocks/`, `fields/`, `inc/` (`*.php` then
  `*/*.php`), `app/` (`Theme\`), `config/`, `public/`, `assets/`, `acf-json/`
  (ACF UI JSON, committed), `storage/` (what can be made again: the Twig cache, tooling files; moved by `GAFFER_STORAGE`); `uploads/gaffer/` (the site's private files: `Storage::private_dir()`, Gaffer's logs and backups; per site on a multisite); ajax actions as `app/Ajax/{Name}/{Name}.php` (`Theme\Ajax\{Name}\{Name}`).
- **Blocks:** `blocks/{dir}/` named `acf/` + the folder in kebab case (`contentFaq` and `content-faq` → `acf/content-faq`; acronyms are written as words, `faqBlock`; fixed for good, since post content stores it; Gaffer sets the name, ACF Blocks V3 and rendering `functions.php` outside the editor; `block.json` holds title, description, category, icon); `fields.php` (a list of ACF
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
  Label; the request keys `fields[...]` (files too, multipart; `files[...]` still read) and
  `kept_files[...][]`. The rest of what a form sends (its name, the honeypot,
  Turnstile's token) is Gaffer's own, written by `@gaffer/forms/meta.twig` and
  read by `FormAction`: it may change in any release, both ends together.
  Gaffer loads Turnstile's script. `uploads/gaffer/logs/spam.log` is Gaffer's too
  (`Spam::check()` writes it, `doctor` reads it).
- **The build:** `vite.config.js` uses Gaffer's plugin (`resources/vite.js`,
  `gaffer()`): it owns the settings Gaffer reads (`public/`, the manifest,
  `public/.vite/gaffer.json`) and the `@gaffer` alias. Gaffer's PHP and the
  bundled JavaScript of one release work with the previous release's other half
  (a composer update changes the PHP at once, the JavaScript with the next build).
- **Storage formats:** `uploads/gaffer/backups/migrate/*.json` (rollback reads old
  backups) and `uploads/gaffer/logs/migrate.log`.
- **Carts and orders** (with a `Product::confirm_purchase()` override): the cart item key
  `gaffer_price` and the order item meta `_gaffer_price`, the price the customer saw.

## Events

What Gaffer's bundled JavaScript announces, for code outside it (analytics, a tag manager, a
theme's component) to listen to. Each event bubbles from the element named; its `detail` is
plain data and only gains keys. A theme listens to these; it never dispatches them.

- **`gaffer:search`** (on the search form, after an answer): `query` (the words, `''` without),
  `request` (the query string sent, without the token), `total` (matches, or `null`).
- **`gaffer-search:results`**, **`gaffer-search:error`**, **`gaffer-search:popstate`** (on the
  search form): the endpoint's answer, the error, the restored params. Experimental with Search.

## Report

`php gaffer health --json` prints the site's state as one JSON object of flat dotted keys
(`gaffer.version`, `php.version`, `wordpress.version`, `site.url`, `site.environment`,
`site.multisite`, `health.color`, `health.{line}.color`, `health.{line}.reason`), with
`"format": 1`. Keys are only added, never renamed or removed, and a reader ignores keys it doesn't
know: a monitor or a hub reshapes them for itself. The exit code is `health`'s.

## Reserved names

So that Gaffer can add something in a 1.x minor without colliding with a theme, these are
Gaffer's; `doctor` errors when a theme takes one:

- **`gaffer`, in every namespace:** request keys `gaffer_*` (and `action`, the dispatcher's),
  ajax actions `Gaffer*` (plus the built-in `Form`, which a theme may replace), Twig functions,
  filters and tests named `gaffer_*` or like one of Gaffer's (`config`, `ajax_url`, `t`),
  `data-gaffer-*` attributes Gaffer doesn't define, `gaffer:*` events (a theme listens to them,
  it doesn't dispatch them), `window.gaffer*` globals, options, hooks and transients `gaffer_*`,
  CSS classes `gaffer-*`.
- **Config:** `config/gaffer.php` and `config/gaffer/`. A config file Gaffer adds later goes in
  `config/gaffer/{name}.php` (read as `gaffer.{name}.*`); new keys of Gaffer's existing files go
  in those files. A theme's own config files (`config/customer.php`) are never in the way.
- **Folders:** Gaffer adds no top-level folder to the theme in 1.x; new conventions go inside
  its folders (`views/`, `app/`, `config/gaffer/`, …). A theme's other folders are its own.
- **Methods on the types:** see "Theme subclasses" above: a new method on `Post`, `Product`,
  `Term`, `Attachment` or `Image` only after a minor in which `doctor` warned about a theme
  method with that name (the names Gaffer will add are listed for that minor).

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
- **Commands a server runs from its crontab** are promised like the runtime (they run in
  production with nobody watching): `php gaffer health` keeps its name, needs no option, and
  exits 0 when every health line is green and 1 otherwise (printing the lines).
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
