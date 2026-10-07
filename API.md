# Gaffer's public API

What themes may rely on. From 1.0 on, nothing listed here changes in a minor
or patch release: it's deprecated first (see Deprecating) and removed in the
next major. Everything else in `src/` is
internal (`@internal`) and may change in any release.

`api.txt` is this list as signatures, generated from the code: `composer test`
fails when the public API differs from it (`tests/ApiTest.php`). After a
deliberate change, `UPDATE_API=1 composer test` updates it, and the diff shows
in review.

Not covered: the generated AI guidelines and skills (`AGENTS.md`, `.claude/`,
...; they're regenerated with every update), the wording of messages, and
what's **experimental** until the plugin it builds on reaches 1.0: the MCP
server (`gaffer`, its tools and inputs; MCP Adapter) and the Fuzor
integration (its guideline).

## Supported versions

Gaffer aims for the latest: PHP (`composer.json`), WordPress, ACF Pro,
WooCommerce, Gravity Forms and Twig 3. Sites keep Gaffer and their plugins
updated; a minor release may start relying on what the current plugin
versions offer. No minimum plugin versions are declared or checked.

## Code

**Entry points** (in the theme's own files)
- `Gaffer\Gaffer::boot(string $dir)` (`functions.php`)
- `Gaffer\Ajax::boot(string $dir)` (`ajax.php`)
- `Gaffer\Console\Console::boot(string $dir)` (`gaffer`)

**Services**
- `View::render(string $template, array $data = [])`, `View::fetch(...)`,
  `View::share(string $key, mixed $value)`
- `Config::get(string $key)`
- `Paths::base(string $path = '')`
- `Vite::tags(array $assets)`, `Vite::url(string $asset)`
- `Acf::field_array(string $selector, ?int $post_id = null)`, `Acf::option_array(string $selector)`
- `Ajax::url(string $action)`
- `Turnstile::enabled()`, `Turnstile::site_key()`, `Turnstile::verify(string $token)`,
  `Forms\Spam::log(string $form, string $reason)` (for a theme's own forms)

**Types** (`Gaffer\Types\`): factories and methods as they are now, except
the questions below.
- `Post`: `from`, `current`, `query`, `main_query`; `wp`; `id`, `title`, `link`,
  `content`, `excerpt`, `date`, `modified_date`, `parent`, `ancestors`,
  `children`, `terms`, `blocks`, `meta`, `thumbnail`, `is_current`
- `Term`: `from`, `current`, `query`; `wp`; `id`, `title`, `link`,
  `description`, `parent`, `ancestors`, `children`, `meta`, `thumbnail`
- `Attachment`: `url`, `mime`; `Image`: `attrs`, `src`, `alt`, `width`, `height`
- `Menu`: `location`, `items`; `MenuItem`: `wp`, `id`, `title`, `link`,
  `target`, `classes`, `is_current`, `is_current_ancestor`, `is_external`,
  `children`, `has_children`
- `Pagination`: `current`; `page`, `total_pages`, `items`, `total_items`,
  `per_page`, `results_start`, `results_end`; `pages`, `previous`, `next`,
  `previous_link`, `next_link`
- **Theme subclasses** of `Post` and `Term` (mapped in `theme.types` /
  `theme.terms`): may add methods and override `thumbnail()`; the
  constructor and factories are not theirs to change.

**Ajax**: extend `AjaxAction`; constants `METHOD` (`'GET'`/`'POST'`) and
`SHORTINIT`; `run()` with the documented parameter types (scalars, `array`,
`Post`/`Term`/`Image` and theme subclasses, nullable, defaults).

**Forms**: extend `Forms\FormAction` (one `Form` action); `Forms\FormState`:
`blank()`, `name`, `sent`, `message`, `failed`, `value()`, `checked()`, `error()`.

**Twig**: the functions `config()` and `ajax_url()`; the namespaces `@block`
and `@ajax`; `strict_variables` with `WP_DEBUG`.

## Data and conventions

These live in databases and theme folders, so a change breaks sites even
without a code change:

- **The theme layout:** `views/`, `blocks/`, `ajax/`, `inc/` (`*.php` then
  `*/*.php`), `app/` (`Theme\`), `config/`, `public/`, `assets/`, `storage/`;
  `GAFFER_STORAGE`; the `Theme\Ajax\{Name}\{Name}` namespace.
- **Blocks:** `block.json` with `acf.renderTemplate`; `fields.php` (a list of ACF
  field arrays without keys) and **the derived keys**:
  `group_{name}`, `field_{name}__{field}`, sub fields `…__{parent}__{field}`,
  layout fields `{type}-{label}`. Stored in post content: changing the
  formula orphans every site's block data.
- **Config keys** in `config/*.php` stubs (`theme.*`, `turnstile.*`,
  `console.*`, `ai.*`) and the theme's `.env` (`SITE_URL`).
- **Forms:** template `views/forms/{title slug}.twig`; fields by Admin Field
  Label; the request keys `form`, `fields[...]`, `turnstile`, `website`.
- **CLI:** command names and options; `doctor` exit code (1 on errors).
  `doctor` may add **warnings** in any release, **errors** only in a major.
- **Storage formats:** `storage/backups/migrate/*.json` (rollback reads old
  backups) and `storage/logs/migrate.log`.

## Internal

`AdminBar`, `AjaxArguments`, `AjaxNotFound`, `BlockFields`, `Storage`,
`TwigCache`, `Twig\Extension` (its functions are public, the class isn't),
`Forms\FormReport`, `Forms\FormTemplates`, `Forms\GravityForm`, everything in
`Console\`, `Ai\` and `Mcp\`; and the methods not listed above:
`Gaffer::configure/twig/debug/version`, `Ajax::handle`, `Ajax::NAMESPACE`,
`Config::load/all`, `Paths::*` except `base`, `View::env/set_env/shared_keys`,
`Vite::*` except `tags`/`url`, `MenuItem::tree`, `Pagination::from_counts`, `FormState::__construct`.

## Deprecating

- **Methods and constants:** PHP's own attribute,
  `#[\Deprecated(message: 'use fresh()', since: '1.3')]`. PHP raises
  `E_USER_DEPRECATED` on every call (shown/logged like any notice under
  `WP_DEBUG`), `doctor` warns about theme code that uses it (PHP and Twig;
  instance methods by name), and `api.txt` marks it ` #[Deprecated]`. The old
  member keeps working, usually by calling the new one.
- **Twig functions and filters:** `deprecationInfo: new
  DeprecatedCallableInfo('onstage2426/gaffer', '1.3', 'fresh')` on the
  `#[AsTwigFunction]`/`#[AsTwigFilter]`. Twig triggers it while compiling;
  `doctor` reports it with the template and line.
- **Classes:** PHP can't mark a class: deprecate its factories and methods.
- **Data and conventions** (config keys, layout, request keys, CLI options):
  keep accepting the old form and add a `doctor` warning naming the new one.
  An error (like `ConfigCheck::MOVED`) only comes with the major that
  removes it.
- Every deprecation goes in the changelog of its release; the next major
  removes them all.
