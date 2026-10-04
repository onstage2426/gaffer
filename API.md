# Gaffer's public API (draft)

What themes may rely on. From 1.0 on, nothing listed here changes in a minor
or patch release: it's deprecated first (`@deprecated`, a deprecation notice,
`doctor` warns) and removed in the next major. Everything else in `src/` is
internal (`@internal`) and may change in any release.

Not covered: the generated AI guidelines and skills (`AGENTS.md`, `.claude/`,
...; they're regenerated with every update) and the wording of messages.

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
  `Turnstile::log_spam(string $form, string $reason)` (for a theme's own forms)

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
- **MCP:** server name `gaffer`, tool names and inputs (tools may be added).

## Internal

`AdminBar`, `AjaxArguments`, `AjaxNotFound`, `BlockFields`, `Storage`,
`TwigCache`, `Twig\Extension` (its functions are public, the class isn't),
`Forms\FormReport`, `Forms\FormTemplates`, `Forms\GravityForm`, everything in
`Console\`, `Ai\` and `Mcp\`; and the methods not listed above:
`Gaffer::configure/twig/debug/version`, `Ajax::handle`, `Ajax::NAMESPACE`,
`Config::load/all`, `Paths::*` except `base`, `View::env/set_env/shared_keys`,
`Vite::*` except `tags`/`url`, `MenuItem::tree`, `Pagination::from_counts`, `FormState::__construct`.

## Decided (2026-10-04), applied in step A2

1. Remove `Vite::ver()`, `hotfile()`, `path()` and `Image::data()`, `file()`,
   `sizes()`; keep `Vite::url()`.
2. The stylesheet is its own Vite entry; `Vite::url()` gives the editor
   style; `css_url()` is removed (it guessed the CSS path in dev mode).
3. `Menu` builds the tree; `MenuItem` gets a private constructor and loses
   `add_child()`, `mark_current_ancestor()`, `parent_id()`.
4. `Pagination::from_counts()` is internal.
5. `meta()` keeps returning `mixed`.
6. `View::env()` is internal.
7. Only `Post` and `Term` are for theme subclasses. (`Attachment` can't be
   final: `Image` extends it.)
