## Hooks (`inc/`)

`inc/` only registers hooks, shortcodes and shared view data; Gaffer includes
`inc/*.php`, then `inc/*/*.php`.

- One file per thing you hook into (`wordpress.php`, `woocommerce.php`,
  `assets.php`, one per plugin); `gaffer.php` holds the `View::share()` calls.
- A comment above every hook, `/* Area - what it does */`; typed closures;
  anything longer than a few lines is a method in `app/`, called as
  `Cart::sync_count_cookie(...)`.
- No functions or classes declared in `inc/` (global, load-order bound): put
  them in `app/`. Output HTML through `View::fetch()`.
