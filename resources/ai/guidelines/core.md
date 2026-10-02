## Gaffer

This WordPress theme is built on **Gaffer** (`onstage2426/gaffer`), a theme
framework installed with Composer: Twig views, typed wrappers around WordPress
objects, an ajax dispatcher, Vite assets and a CLI. The theme only holds
site-specific code.

- **Never edit `vendor/`.** It is overwritten by `composer install`. Framework
  changes belong in Gaffer itself; theme behavior goes in the theme (config,
  `inc/`, subclasses in `app/`).
- **The layout is fixed:** `views/` (Twig), `blocks/` (ACF blocks), `ajax/`
  (ajax actions), `inc/` (hooks, auto-included), `app/` (the `Theme\`
  namespace: `Theme\Types\`, `Theme\Twig\`, `Theme\Fields` for shared block
  fields; composer.json autoloads `"Theme\\": "app/"`), `config/` (PHP
  arrays), `public/` (Vite build output), `assets/` (sources), `storage/`
  (Twig cache, ACF JSON, logs, backups; can be moved by the server with
  `GAFFER_STORAGE` in `wp-config.php`). `logs/` and `backups/` get a deny-all
  `.htaccess` and a `.gitignore`; on other web servers than Apache, deny
  `storage/` yourself.
- **Boot:** `functions.php` requires `vendor/autoload.php` and calls
  `Gaffer\Gaffer::boot(__DIR__)` (config, Twig, `inc/*.php` then
  `inc/*/*.php`, ACF JSON path, every `blocks/*/block.json`, the admin bar).
  `ajax.php` calls `Gaffer\Ajax::boot(__DIR__)`. In PHP, paths inside the
  theme are `Gaffer\Paths::base('relative/path')`.

### Standards

- PHP 8.5: `match`, `readonly`, named arguments, first-class callables,
  `json_validate()`. Explicit return types. `match` over `if/elseif` chains.
  Inline single-use variables.
- **PSR-12, except method names are snake_case** (matches Twig and WordPress).
- **One way to do each thing.** No alias methods, no overloaded "accepts
  anything" signatures, no magic fallbacks: missing data is `null` and the
  calling code decides what to show.
- **PHP prepares data, Twig presents it.** WordPress template files and block
  `functions.php` files only gather data and call `View::render()`. Templates
  can't look anything up.
- Prefer clear, root-cause fixes over workarounds, even when they change more.

### Decided (don't propose otherwise)

- No nonces on ajax actions: pages are cached, so nonces would go stale.
- ACF block editor previews are intentionally empty (every block returns early
  on `is_admin()`); WordPress shows its "edit block" button instead.
- The Twig cache is off by default (`theme.cache`).
- No test or static-analysis tooling (PHPUnit, phpstan) in the theme. Use the
  `php gaffer` checks below.
- Themes are standalone, never child themes.

### WordPress gotchas

- `get_queried_object()` can return `null`: use `?->`.
- `get_term_children()` returns `WP_Error` for an unknown taxonomy: check `is_array()`.
- `wp_redirect()`'s third parameter is the redirect source label, not a boolean.
- `get_post(0)` returns the current post; Gaffer's factories return `null` for `0`.

### Check your work

After changing templates, blocks, ajax actions, types or config, run:

```
php gaffer twig:lint      # every template compiles; unknown functions/filters fail
php gaffer doctor --wp    # conventions + renders every page with strict variables
```

Fix what they report (exit code 1 = errors). `theme.debug` turns on Twig
`strict_variables`, so undefined variables fail instead of rendering empty.

Administrators see **Gaffer** in the admin bar: a status dot (red: debug on in
production/staging; orange: debug on, or Twig cache off in production), the
Gaffer/Twig versions, Twig and Vite state, and "Clear Twig cache".
