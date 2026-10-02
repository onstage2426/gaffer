## Hooks (`inc/`)

`inc/` only registers WordPress hooks, shortcodes and shared view data. Gaffer
includes `inc/*.php`, then `inc/*/*.php`.

- **One file per thing you hook into:** `wordpress.php`, `woocommerce.php`,
  `assets.php`, and one per plugin (`yoast.php`, ...). `gaffer.php` holds the
  `View::share()` calls.
- **A comment above every hook** saying where and why, `/* Area - what it does */`:
  ```php
  /* Cart - keep the header badge's cookie equal to the cart on every change */
  add_action("woocommerce_set_cart_cookies", Cart::sync_count_cookie(...));
  ```
- Closures get parameter and return types; anything longer than a few lines
  is a method on a class in `app/` (`Theme\...`, autoloaded), called from the
  hook.
- **No functions or classes declared in `inc/`.** They'd be global, need
  `function_exists` guards and depend on load order. Put them in `app/`.
- Hooks and shortcodes that output HTML return `View::fetch()` (or call
  `View::render()`); no markup in PHP.
- `doctor` reports functions and classes declared in `inc/`. The reference
  below lists every file's hooks with their comments.
