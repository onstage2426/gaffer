# Changelog

What a theme has to do after updating Gaffer, newest first. Each entry: what
changed, how to find the code it affects, and what to check afterwards. Read
the entries since the version in your `composer.lock` after every
`composer update onstage2426/gaffer` (this file is in
`vendor/onstage2426/gaffer/CHANGELOG.md`). Until 1.0, changes may break; after
1.0 they're deprecations first (see `API.md`).

## 2026-11-26

- **Paying an existing order at an old price gets its own notice: `order_price_changed_notice()`.**
  Only for a theme whose `Product` overrides `confirm_purchase()`. An order (pending, a failed
  payment, made in the admin) keeps the price it was placed at, so when the product's price
  changed since, its payment is refused every time, not once as the changelog of 2026-11-23 said:
  the customer has to place a new order. `price_changed_notice()`'s "check the new total and try
  again" was wrong there; the new method says so (English by default). Also fixed: Gaffer asks
  `confirm_purchase()` about the variation actually being sold (before, an "any" variation listed
  first, or an out-of-stock one, could make it check another). Find: `grep -rn
  "price_changed_notice" app`. A theme that overrides `price_changed_notice()` for its language
  overrides `order_price_changed_notice(string $was, string $now): string` too. Check: with an
  override, place an order unpaid, change the product's price, open the order's payment page.

## 2026-11-25

- **Theme blocks get a Preview button in the editor; the canvas shows a placeholder.** The
  canvas never runs a block's `functions.php`: it shows a placeholder with the block's title and
  the first text in its fields. The Preview button in the block's toolbar opens the block on the
  site itself in a modal: the post's preview page with the theme's header, CSS, fonts and
  JavaScript, showing the unsaved block (or the whole unsaved page), at desktop, tablet or
  mobile width. Nothing is saved. That replaces this day's earlier in-canvas previews:
  `theme.block_previews` and the `assets/css/editor.css` entry are gone (doctor reports
  `block_previews` as an unknown key). Find: `grep -n "block_previews" config/theme.php;
  ls assets/css/editor.css; grep -n "editor.css" vite.config.js`. Remove all three, then
  build; a `theme.css` partial `app.css` imports can stay. A theme that loads its fonts on
  `enqueue_block_assets` for the editor only can drop that too. Blocks need no editor guard
  (`if (is_admin()) return;`, `$is_preview` branches): `grep -rn "is_preview\|is_admin"
  blocks/*/functions.php`. Check: `php gaffer doctor --wp` renders each block with no field
  filled in (what a page shows when an editor saves a block before filling it in) and reports
  the ones that fail; open a page in the editor, press Preview on a block, change a field and
  press it again.

## 2026-11-23

- **Shops whose stock or prices live in another system can have Gaffer check every sale live:
  `confirm_purchase()`.** Nothing changes unless the theme's `Product` overrides it (return null
  when it can be bought now, or the reason it can't; bring WordPress's copy of the price and stock
  up to date in it; throw when the system can't be asked). Gaffer then asks it when the product
  goes into a cart by any route (forms, `?add-to-cart=`, the Store API, the theme's own
  `WC()->cart->add_to_cart()`), on the cart and checkout pages, when the order is placed and
  before an existing order is paid, and stops a checkout or payment once when the price changed
  since the customer saw it (`price_changed_notice()`, English by default: override it for the
  site's language). A theme that freezes a price in the cart (`$cart_item['data']->set_price()`
  from a stored cart item value) charges the old price anyway: move per-customer prices to
  WooCommerce's `woocommerce_product_get_price` filter. Find:
  `grep -rn "set_price\|custom_price\|woocommerce_add_cart_item\b" app inc`.
  Check: with an override, add a product, change its price, open the checkout (one notice, then
  the new total) and place a test order.

- **`doctor --wp` warns when WooCommerce's Store API lists hidden products to anyone**
  (`/wp-json/wc/store/v1/products?catalog_visibility=hidden`: their names, links and prices,
  whatever the templates hide). Nothing changes at runtime. Check: `php gaffer doctor --wp`; a
  product that must stay private isn't published.

- **`Gaffer\Types\Product`: what every shop's product type had, in Gaffer.** A theme's `product`
  type now extends it instead of `Post` (`doctor` errors otherwise). It has `product()` (the
  `WC_Product`, a LogicException without one), `is_variable()`, `with_variation($attributes)`
  (returns a **copy** with the matching variation; `variation()`, `selected()`),
  `can_add_to_cart()`, `is_on_sale()`, `thumbnail()` (the variation's image, else the product's;
  null without one: no placeholder) and `gallery()` (the product gallery without the main image).
  All final but `thumbnail()`: a theme declaring one of the others fatals, so remove or rename
  them in the same change. Find:
  `grep -rnE "select_variation|hover_image|hover_thumbnail|function (product|is_variable|variation|selected|can_add_to_cart|is_on_sale|gallery|wc)\(|->variation\b|->selected\b" app views inc woocommerce blocks`.
  - `class Product extends Post` → `class Product extends \Gaffer\Types\Product`; delete the
    theme's `$product`/`$variation`/`$selected` properties and the methods above (its own private
    `wc()` becomes `product()`); `$this->variation` → `$this->variation()`, `$this->selected` →
    `$this->selected()`.
  - `$product->select_variation($a);` → `$product = $product->with_variation($a);` (Twig:
    `{% set product = product.with_variation(attributes) %}`). The old one changed the object
    every partial shared; the copy doesn't, and a theme cache (a property filled lazily) starts
    empty on it, so nothing selection-dependent needs resetting.
  - A placeholder image: `return parent::thumbnail() ?? Image::from((int) get_option("woocommerce_placeholder_image"));`.
  - A hover image: `product.gallery|first` (a `hover_image()` returning the second of "main image +
    gallery" was the wrong image for a product without a main image). A gallery that included the
    main image gets its own name (`exterior()`, `images()`).
  - A rule on who may buy what goes in WooCommerce's `woocommerce_is_purchasable` filter, so
    `can_add_to_cart()` and the cart agree.
  Check: `php gaffer doctor --wp`, a product page with variations (pick each attribute; the price,
  image and button follow), a card's hover image, adding to the cart.

- **A build keeps a week of earlier builds' files** (Gaffer's Vite plugin): before, only the
  previous build's, so a page cache holding pages from two builds back served them without their
  JavaScript and CSS. Nothing to change; the next build after `composer update` takes it. Check:
  `php gaffer doctor --wp` (no "loads assets the build no longer has").

- **`doctor` checks the names a theme writes as strings.** A literal `Config::get('a.b')` or Twig
  `config('a.b')` key that `config/` doesn't have, and an `ajax_url('Name')`/`Ajax::url('Name')`
  for an action that doesn't exist, are errors (each read null or answered 404 without a word);
  a literal `hx-vals` key that the action's `run()` has no parameter for is a warning (it's
  ignored). Nothing changes at runtime. Check: `php gaffer doctor`; a key that may be unset gets
  `=> null` in its config file.

- **File inputs are named `fields[x]` like every other input** (`fields[x][]` with `multiple`).
  `files[x]` keeps working, so nothing breaks; doctor no longer asks for the two names. Find:
  `grep -rn 'files\[' views`. Before: `<input type="file" name="files[fotos][]" multiple>`; after:
  `<input type="file" name="fields[fotos][]" multiple>` (`kept_files[...]` stays). Check: submit the
  form with a file for real and see it in the entry.

- **Gaffer answers forms itself: `app/Ajax/Form/` can go.** Every theme had the same
  `class Form extends \Gaffer\Forms\FormAction {}`; the dispatcher now uses `FormAction` (no
  longer abstract) when the theme has no `app/Ajax/Form/Form.php`. A theme that keeps the file keeps
  working. Find: `php gaffer doctor` ("app/Ajax/Form/ only repeats Gaffer's form action"). Before:
  `app/Ajax/Form/Form.php` with the empty class; after: `rm -r app/Ajax/Form`, then `composer
  dump-autoload` when the theme uses a classmap. Check: submit a form for real (an entry, one with
  errors) and `php gaffer doctor --wp`.

- **Gaffer writes each block's boilerplate.** A block is named `acf/` + its folder in kebab case,
  registered as ACF Blocks V3, and its `functions.php` runs only outside the editor (previews were
  already empty on purpose). `block.json` keeps title, description, category and icon; the `name`,
  the `acf` key and the `if (is_admin()) { return; }` guard at the top of `functions.php` can go.
  **Nothing breaks on updating:** a block that still says them works as before, and a `name` other
  than the folder's stays an error (it's stored in post content). Update Gaffer first: an older
  Gaffer can't register a block without its `name`. Find: `php gaffer doctor` ("blocks still write
  what Gaffer sets"). Remove them all at once, from the theme folder:
  ```sh
  php -r 'foreach (glob("blocks/*/block.json") as $f) { $j = json_decode(file_get_contents($f), true); $n = "acf/" . strtolower(preg_replace("/(?<!^)[A-Z]/", "-$0", basename(dirname($f)))); if (($j["name"] ?? $n) === $n) unset($j["name"]); $a = $j["acf"] ?? []; if (($a["blockVersion"] ?? 3) === 3) unset($a["blockVersion"]); if (($a["renderTemplate"] ?? null) === "functions.php") unset($a["renderTemplate"]); if ($a === []) unset($j["acf"]); else $j["acf"] = $a; file_put_contents($f, json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"); } foreach (glob("blocks/*/functions.php") as $f) file_put_contents($f, preg_replace("/^if\\s*\\(\\s*is_admin\\(\\)\\s*\\)\\s*\\{?\\s*return\\s*;[ \\t]*(?:\\/\\/[^\\n]*)?\\s*\\}?[ \\t]*\\R(\\s*\\R)?/m", "", file_get_contents($f), 1));'
  ```
  Before: `{"name": "acf/hero-content", "title": "Hero", …, "acf": {"blockVersion": 3, "renderTemplate":
  "functions.php"}}`; after: `{"title": "Hero", …}`. Check: `php gaffer doctor --wp` (no such
  warning, every block registered, renders clean), a page with blocks looks the same, and the
  editor still shows the blocks with their fields.

- **Private folders moved from the theme's `storage/` to `uploads/gaffer/`** (WordPress's uploads
  folder: `uploads/sites/{id}/gaffer/` on a multisite). `Storage::private_dir()` (visitors'
  uploads, generated files), Gaffer's logs (`spam.log`, `migrate.log`) and the migration backups
  are the site's own data: in the theme folder, a deploy that replaces the theme deleted them, every
  site of a network shared them, and a host's backup of uploads and the database missed them. The
  theme's `storage/` keeps what can be made again (Twig cache, screenshots, sessions, snapshots).
  `private_dir()` needs WordPress loaded (not SHORTINIT). **Nothing breaks on updating:** a folder
  that's still in `storage/` is used where it is until it's moved; `doctor --wp` warns and gives
  the exact command. Find: `php gaffer doctor --wp` ("Private folders are still in the theme's
  storage/"). Move, with the command from its hint, e.g.:
  ```sh
  mkdir -p /path/to/wp-content/uploads/gaffer && mv storage/logs storage/backups /path/to/wp-content/uploads/gaffer/
  ```
  (on a network: into the main site's folder, `doctor --wp` with its `SITE_URL`). Check: `doctor
  --wp` shows no such warning and no "The web server serves private files" error; on a server
  other than Apache, deny `/wp-content/uploads/gaffer/` (and `uploads/sites/*/gaffer/`) in its
  configuration as for the theme folder. Code that wrote paths into `storage/` by hand (not through
  `private_dir()`): `grep -rn "storage/" app inc views`.

## 2026-11-22 (full audit)

- **`vite.config.js`: `build.rollupOptions` is `build.rolldownOptions`** (Vite 8 deprecates the
  old name; a later Vite may drop it). `doctor` warns on Vite 8 and later. Find:
  `grep -n rollupOptions vite.config.js`. Before: `build: { rollupOptions: { input: entryFiles } }`;
  after: `build: { rolldownOptions: { input: entryFiles } }`. Check: `npm run build` (or `bun run
  build`) still writes every entry to `public/.vite/manifest.json`.

- **Removed `MenuItem::is_external()`** (no theme used it). Find:
  `grep -rn is_external views blocks app inc`; compare the link's host with `home_url()`'s in the
  theme instead. **Internal now:** `FormAction::run()` (the dispatcher calls it; its parameters
  follow Gaffer's own form keys), `Spam::log()` (`Spam::check()` logs) and
  `Turnstile::enabled()` (Gaffer writes and checks the widget): `doctor` warns when theme code
  uses them. `API.md` now lists what `ajax.php` answers (400/404/405/413/500 with
  `HX-Reswap: none`, `no-store`), that `|t` needs the `Text Domain` header, and that only the
  listed JavaScript exports are public.

- **ACF's JSON moved from `storage/acf-json/` to `acf-json/` in the theme.** It's code (post types,
  taxonomies, options pages and field groups made in the ACF UI, committed), but `storage/` holds
  runtime files and moves with `GAFFER_STORAGE`: a site that defined it would have lost them.
  Gaffer still reads `storage/acf-json/` (and ACF saves a file there in place) until the theme
  moves it; `doctor` warns meanwhile. Find: `ls storage/acf-json`. Move:
  ```sh
  git mv storage/acf-json acf-json
  ```
  (not a git repository: `mv storage/acf-json acf-json`). Check: `php gaffer doctor --wp` shows no
  "ACF JSON is still in storage/acf-json/" and no "only in the database" warnings; ACF → Field
  Groups lists no "Sync available". On a server, the theme folder must be writable for the web
  server where the ACF UI is used (as `storage/` was).

- **A page missing a Vite entry stays out of page caches** (`DONOTCACHEPAGE`): an entry the build
  doesn't have (not built yet, renamed, a deploy without `public/`) rendered the page without its
  styles or scripts, and a page cache kept it that way. `doctor` names the entry as before.
  Nothing to change.
- **`doctor --wp` warns when it can't fetch `ajax.php` or the private-file probe** (a server that
  blocks requests to itself): it was an info line, so on such a host neither was ever checked and
  nothing said so. Check: the hint says what to try in a browser instead.

- **A search request without a token isn't "stale" any more** (`error.stale` is false for
  `token_required`): it comes from a build older than the templates that write the token
  (`search.attrs()`), and refreshing the page doesn't fix that; the error state says "try again
  later" now. Needs the theme's next build. Nothing to change; deploy templates and their build
  together.

- **`post.is_current()` is false on a term's or an author's archive.** It compared the post's ID
  with the queried object's, so post 12 counted as current on category 12's archive (a highlighted
  link that shouldn't be). Nothing to change. Find: `grep -rn is_current views blocks app`.

- **WordPress's search URL (`/?s=…`) goes to the archive again with `urls.redirect_search`.**
  WordPress sets the `post_type` query var to `any` for a search without one, and the redirect
  only accepted an empty one, so `/?s=…` stayed on WordPress's search: the theme's `search.php`,
  or a blank page with HTTP 200 (cached by a page cache) when `index.php` prints nothing. A
  search of another post type (`?s=…&post_type=post`) still stays. Find:
  `grep -n redirect_search config/search.php`. Check: `/?s=test` answers 302 to the archive with
  `?q=test`.

## 2026-11-22 (break-first audit 2)

- **A changed Twig extension compiles the templates again.** A compiled template calls the
  theme's filter and function methods by name, so renaming one (or making it static, giving it a
  required argument, marking it safe) left the templates compiled before calling what was gone:
  "Call to undefined method" on every page using it, until the template itself changed. The Twig
  cache now starts a fresh folder when a file in `theme.twig_extensions` changes, and a search
  index with cards rebuilds when one changes (a card prints its filters' output: a price format).
  **On updating, every index with cards rebuilds once** (on a large catalog, update at a quiet
  hour). Nothing to change in the theme. Check: `php gaffer doctor --wp` after the rebuild.
- **gaffer-search's health line says when its updater stops finding releases** (plugin 0.4.0,
  feature `update_checks`): a failed update check (GitHub's rate limit for a server's IP address,
  a moved repository, a release without its built zip) looked like "no update", so a site could
  stay on an old release unnoticed. The admin bar's line and `php gaffer health` turn orange after
  7 days without a successful check; `doctor --wp` warns, with the last error. Nothing to change.
  Check: `doctor --wp` shows "gaffer-search's updater found its releases …" once the plugin is
  updated.
- **Only the web server writes the Twig cache.** `php gaffer` (doctor's renders, `wp
  gaffer-search` builds) reads it and compiles the rest in memory: run as another user (root), it
  left folders the web server couldn't write to, and pages rendered uncached without saying so.
  The admin bar now says "Twig cache: on, but not writable for the web server" when that's the
  case. Check: the admin bar's Twig cache line; if it says so, give the folder it names to the web
  server's user (`chown -R`) or delete it (`php gaffer twig:clear` as that user).

## 2026-11-21 (break-first audit)

- **The Twig cache is on by default** (it was off unless `config/theme.php` set `'cache' => true`):
  compiled templates are kept in `storage/cache/views/<version>/`, about 15 ms less per uncached
  page render on a shop page. It can't break a page: a template changed in any way (also put back
  with an older modification time, as a rollback or `rsync -a` does) compiles again, a Gaffer
  update starts a fresh folder and removes the previous one, and when the web server can't write
  the folder, pages render without the cache as before. `twig:clear` is no longer needed after
  editing a template. The admin bar no longer marks "Twig cache off in production" orange. Find:
  `grep -n "'cache'" config/theme.php`: delete a `'cache' => true` line (the default now); keep
  `'cache' => false` only to turn it off on purpose. Check: the admin bar's "Twig cache: on (N
  files…)" after a few page views, and `storage/cache/` stays out of git (the starter's
  `.gitignore` has it).
- **Debug on a local or development site is green** in the admin bar and `php gaffer health`
  ("OK (debug on, development)"; it was orange, so `health` printed and exited 1 on every dev
  site). Debug in production or staging stays red; `WP_ENVIRONMENT_TYPE` unset is production.
  Nothing to change.
- **`doctor --wp` compares the theme's WooCommerce overrides with WooCommerce's templates**: one
  warning lists the overrides without `@version`, and one per override whose WooCommerce template
  has a newer `@version` than it says (WooCommerce changed the markup, hooks or data it passes; a
  dropped `$notice['data']` loses the attributes its checkout script reads). Find:
  `grep -rL '@version' woocommerce/`. Compare each with
  `wp-content/plugins/woocommerce/templates/<same path>`, carry over what the theme needs, and add
  WooCommerce's version to the override's docblock:
  ```php
  <?php
  /**
   * WooCommerce's notices/notice.php, rendered by components/notices.twig.
   *
   * @version 10.4.0
   */
  ```
  Check: `php gaffer doctor --wp` has no such warning.
- **An index with cards rebuilds once after this update, and again when the site's address
  changes.** Stored cards hold absolute URLs (links, images, ajax actions): a site moved to its
  real domain or to https kept serving cards with the old addresses until a rebuild. The index's
  version now includes the `home` option, so the first request after this update schedules one
  rebuild in the background (the old index answers meanwhile; a large catalog takes minutes).
  Nothing to change. And `doctor` warns about an index with `cards` but no `rebuild` schedule:
  a card shows more than its product (a brand's logo, an option, a theme class's price rule), and
  without a schedule nothing renders it again. Find: `grep -n "'rebuild'" config/search.php`;
  add `'rebuild' => 'daily'`. Check: `php gaffer doctor --wp` (the index "waits for a rebuild",
  then is built and current).
- **`php gaffer health`**: the admin bar's health lines from the command line (Gaffer's debug and
  Twig cache, each Gaffer plugin: gaffer-search's indexes, its daily check, files a visitor can
  download). Silent while everything is green; otherwise it prints each line and exits 1, so a
  daily crontab line with `MAILTO` tells someone on a site nobody looks at every day:
  `MAILTO=you@example.com` and `0 7 * * * cd /path/to/theme && php gaffer health`. Nothing to
  change; worth adding on a live site (debug on is orange, so a development site always prints).
- **`doctor --wp` and the admin bar say when gaffer-search's daily check stopped running**: its
  last run more than 2 days ago is a warning ("the engine's daily check last ran 3 days ago") and
  an orange admin bar line. It's a WP-cron job, so WP-cron doesn't run any more (a crontab that
  curls `wp-cron.php` and stopped, a firewall, HTTP auth); before, a quiet site with nothing
  waiting showed nothing. Nothing to change. Check: `php gaffer doctor --wp`.
- **Without gaffer-search, search pages stay out of page caches and the shop answers 503.**
  When `config/search.php` defines indexes but the plugin doesn't answer (deactivated, or its
  folder missing during an update), every page that shows search (the searchbar, sliders, counts)
  sets `DONOTCACHEPAGE` and no-cache headers, and an archive (the shop, a category) answers 503
  with `Retry-After: 120` and `noindex`, as when its index isn't built. Before, WP Rocket kept an
  empty shop (HTTP 200, indexable) for hours after the plugin was back. Nothing to change; the
  archive's `results.code` is `engine_missing` then. Check: `php gaffer doctor --wp`.

## 2026-11-21 (admin bar)

- **The admin bar's "Gaffer" menu shows each Gaffer plugin the theme uses in one line**, with
  its own dot: for gaffer-search "1 index built and current", or the first thing wrong (an index
  not built or its last build failed: red; building, changes waiting over 5 minutes, documents
  changed without WordPress's hooks: orange). The main dot shows the worst of Gaffer and its
  plugins. Administrators only, cached for a minute per site. Nothing to change; `doctor --wp`
  still says everything.

## 2026-11-21 (gaffer-search audit)

- **`doctor` warns about a search form whose component never shows its error.** A template
  with `x-data` that prints a search object's `attrs()`, and that (with what it includes) never
  reads `error`: when the endpoint fails, the visitor sees "nothing found" or the last results.
  Find: `grep -rLw error $(grep -rl 'attrs()' views | xargs grep -l x-data)`. Show it, e.g.
  `<p x-show="error" x-cloak><span x-show="error?.stale">…refresh…</span><span
  x-show="!error?.stale">…try again later…</span></p>` (`error.stale`: the page's token no longer
  works). Check: `php gaffer doctor` has no such warning.
- **`doctor --wp` shows gaffer-search's daily health check** (gaffer-search 0.2.0, feature
  `health_checks`): an error when a visitor can download files from the plugin's folders (an
  index holds hidden documents too; nginx ignores the deny `.htaccess`), and a warning when
  documents of the daily sample differed from the database (content written without WordPress's
  hooks: direct SQL, an import past WordPress, a sync plugin; the engine updated them). Nothing
  to change. Check: `php gaffer doctor --wp`; on a site with `DISABLE_WP_CRON`, the system cron
  must run WordPress's due jobs too (`wp cron event run --due-now`) or the check never runs.
- **`doctor --wp` searches each index through the endpoint over HTTP, like a visitor's
  browser.** It catches what the command line can't see: a web server that refuses PHP in
  `wp-content/plugins`, an endpoint that doesn't find its config, an index the web server
  doesn't find where WordPress built it, a web PHP whose SQLite is too old, and a second
  `Cache-Control` header from a server rule. A failed search is an error with the likely cause.
  Nothing to change. Check: `php gaffer doctor --wp` says "searched through the endpoint over
  HTTP" per index.
- **A search that couldn't run keeps its page out of caches.** With gaffer-search 0.2.0
  (feature `search_codes`), a search on an index that isn't built yet (a first build, a rotated
  search secret, a deleted file) or that failed comes back with the engine's `code`. Gaffer then
  sets `DONOTCACHEPAGE` and no-cache headers for the page, and an archive (the shop, a category)
  answers 503 with `Retry-After: 120` and `noindex`: before, a page cache kept the empty shop
  (HTTP 200) for hours after the index was back. Nothing to change; with an older plugin
  nothing changes. Check: `php gaffer doctor --wp` (an index that isn't built is an error there
  anyway).

## 2026-11-21 (third stability audit)

- **A build keeps the previous build's files.** Gaffer's Vite plugin no longer empties
  `public/` first: after a build it deletes only what neither this build nor the previous one
  wrote (`public/.vite/gaffer.json` now lists the build's files). A page a cache (WP Rocket,
  Cloudflare) or an open tab has from before the build keeps its CSS and JavaScript, and while
  the build runs the old manifest and its files stay in place (before, a page rendered then had
  no CSS or JavaScript). Nothing to change; the first build after this update deletes nothing,
  the second removes what older builds left. Check: `npm run build` twice, the site looks
  right, and `public/assets/` holds at most two builds' files.
- **`doctor --wp` checks that the web server keeps the theme's private files private**: it
  fetches `.env`, `.git/HEAD`, a probe file in `storage/logs/` (written and deleted again) and
  the Composer files like a visitor. One served is an error (secrets, visitors' uploads, logs) or
  a warning (Composer's files show versions). The theme's `.htaccess` is what keeps them private
  on Apache: an empty or missing one serves everything; nginx ignores it. Find:
  `cat .htaccess` (the starter's denies everything but `public/assets/`, `ajax.php` and
  `screenshot.png`). Check: `php gaffer doctor --wp` has no `[contracts]` error.
- **`doctor --wp` also calls GET ajax actions that take only posts or terms** (`run(Product
  $product)`): with the newest published post of the post types `config/theme.php` maps to
  that class (or a term in use). Its info line names each action and the ID it got. Nothing to
  change; an action whose type has nothing published is named as not called.
- **`doctor` warns about an ajax action that takes an e-mail address without a spam check**
  (a POST `run()` with a `$email` parameter, not a `FormAction`, whose folder never calls
  `Forms\Spam::check()`): bots fill such forms, and what it stores or mails grows without end.
  Find: `grep -rln 'email' app/Ajax`. The fix is the one for a theme's own form (2026-11-20): the
  form includes `@gaffer/forms/meta.twig` with `FormState::blank('<name>')`, and `run()` starts
  with `if (Spam::check('<name>') !== null)` answering as if it worked. Check: send it once with
  the honeypot filled (`X-Gaffer-Spam: honeypot`, nothing stored) and once for real.

## 2026-11-21 (second stability audit)

- **`search.attrs()`** (`Searchbar` and `Archive`): the search form's endpoint and token as
  attributes Gaffer writes and its search JavaScript reads, so both can change together. The
  hand-written `data-gaffer-search-endpoint` and hidden `token` input keep working; `doctor`
  warns where a template still writes them. Find: `grep -rn "data-gaffer-search-endpoint\|name=\"token\"\|\.endpoint }}" views`.
  A searchbar whose `action` was the endpoint gets `action="{{ search.results_url }}"` (a submit
  without JavaScript then opens the archive's results, not the endpoint's JSON).
  ```twig
  {# before #}
  <form method="get" action="" data-gaffer-search-endpoint="{{ search.endpoint }}" data-gaffer-search-history="push">
      <input type="hidden" name="token" value="{{ search.token }}">
  {# after #}
  <form method="get" action="" {{ search.attrs() }} data-gaffer-search-history="push">
  ```
  Build (`npm run build`) in the same change: the new attributes need this release's JavaScript.
  Check: the searchbar finds something, the archive filters and pages, and the address bar has
  no `token=`.
- **No Vite dev server any more.** `Gaffer\Vite` always serves the build in `public/` (it no
  longer reads `public/.vite/hotfile`), Gaffer's Vite plugin no longer writes that file, and the
  admin bar's "Vite" line is gone. A change shows after `npm run build` (or `npm run start`,
  `vite build --watch`, which rebuilds on save). Remove the dev parts from the theme; `doctor`
  warns while they're there. Find: `grep -n "full-reload\|server:\|devSourcemap\|loadEnv" vite.config.js`
  and `grep -n '"dev"' package.json`.
  ```js
  // before
  import { defineConfig, loadEnv } from "vite";
  import FullReload from "vite-plugin-full-reload";
  const reloadFiles = [/* ... */];
  export default defineConfig(({ mode }) => {
      const { SITE_URL } = loadEnv(mode, process.cwd(), "SITE_");
      return {
          plugins: [gaffer(), FullReload(reloadFiles), tailwindcss()],
          css: { devSourcemap: true },
          server: { host: "localhost", port: devPort, /* ... */ },
          build: { rollupOptions: { /* ... */ } },
      };
  });
  // after
  import { defineConfig } from "vite";
  export default defineConfig({
      plugins: [gaffer(), tailwindcss()],
      build: { rollupOptions: { /* ... */ } },
  });
  ```
  Then, with the theme's package manager: `bun remove vite-plugin-full-reload` (or
  `npm uninstall vite-plugin-full-reload`), delete the `"dev"` script from `package.json`,
  and the dev server's line from `.env.example`'s comment (`SITE_URL` stays: the CLI uses it).
  Delete a leftover `public/.vite/hotfile` (the next build does too). Check: `npm run build`,
  `php gaffer doctor` (no `assets` warning), the site loads its CSS and JS while logged in.
- **Gaffer's JavaScript needs htmx 4** (htmx 2 support is gone; every site with htmx had
  moved). The Turnstile renderer listens to htmx 4's events only, and `doctor` errors when the
  theme builds with htmx 2. Find: `grep '"htmx.org"' package.json`. A theme still on htmx 2
  follows "Moving a theme to htmx 4" (2026-11-20) before this update. Check: each form once
  in a browser (the Turnstile widget renders again after an error answer).
- **`Turnstile::verify()` is removed** (deprecated on 2026-11-20; no theme called it any more).
  A theme's own form calls `Forms\Spam::check('form')` instead (see "a theme's own forms" below).
  Find: `grep -rn "Turnstile::verify" app inc`.
- **`doctor` warns about two package managers' lockfiles** (`bun.lock` next to
  `package-lock.json`): a build with the other one installs other versions (a stale `bun.lock`
  still had htmx 2). Keep the one the theme builds with and delete the other, e.g.
  `rm bun.lock && npm install`, or `rm package-lock.json && bun install`.
- **`doctor --wp` calls every GET ajax action that needs no argument** over HTTP, like a
  visitor without cookies, and errors on a server error (a fatal after an update). Nothing to
  change; an action that fails there fails for visitors too.
- **`doctor` no longer stops on a theme without `app/Ajax/`** (it failed in the forms check since
  2026-11-20). Nothing to change.
- **`doctor`'s htmx check no longer flags `name="action"` (or `method`, `form`, …) in a plain
  form** that htmx never sends, like WooCommerce's account forms copied to `views/`. It still
  does inside a form with an `hx-` attribute (on the form or an element in it), in a partial
  without its own `<form>`, and anywhere when the theme uses `hx-boost`. Nothing to change.

## 2026-11-20 (a theme's own forms)

From a site's update: a theme's own form action (not Gravity Forms) had no public way to run
Gaffer's spam checks, so it read the honeypot field itself, whose name Gaffer may change.

- **`Forms\Spam::check(string $form): ?string`**: the honeypot and Turnstile checks on the
  request a form with `@gaffer/forms/meta.twig` sent. It returns the reason (already logged in
  `spam.log`, and as `X-Gaffer-Spam` outside production) or null. `FormAction` uses it too.
  Find a theme's own check: `grep -rnE "\$website|\$turnstile|Turnstile::verify" app/Ajax`.
  ```php
  // before
  public function run(string $naam, string $turnstile = "", string $website = ""): void {
      $spam = match (true) {
          $website !== "" => "honeypot",
          Turnstile::enabled() && !Turnstile::verify($turnstile) => "turnstile",
          default => null,
      };
      if ($spam !== null) { Spam::log("offerte", $spam); View::render("quote/sent.twig"); return; }
  // after: drop the two parameters
  public function run(string $naam): void {
      if (Spam::check("offerte") !== null) { View::render("quote/sent.twig"); return; } // spam looks like it worked
  ```
  The form's template includes `@gaffer/forms/meta.twig` with a `FormState::blank('offerte')`.
- **`Turnstile::verify()` is deprecated** (it still works; `doctor` warns where it's called).
- **`FormAction::run()` no longer has `$turnstile` and `$website` parameters** (it reads them
  through `Spam::check()`). Nothing to change: no theme calls `run()`.
- **doctor:** an ajax action that reads `$website`, `$turnstile` or `$_POST['website']` itself is
  a warning.

Check: `php gaffer doctor`, then send the theme's own form once with the honeypot filled
(`X-Gaffer-Spam: honeypot`) and once for real.

## 2026-11-20 (htmx 4)

Gaffer's JavaScript works with htmx 4 as well as htmx 2, and `doctor` checks an htmx 4 theme for
htmx 2 names. Nothing breaks on htmx 2.

- **The form's name is sent as `form_name`** (it was `form`), by `@gaffer/forms/meta.twig`. An
  input named `form` hides the `<form>` element's own `form` property, and htmx 4 then can't read
  the form at all (no Gaffer form submitted on htmx 4). `FormAction` still accepts `form`, so pages
  a cache still serves keep working. Nothing to change in a theme that uses the partial. `doctor`
  (htmx 4) now flags any input named `form`, `action`, `method`, `enctype`, `elements`,
  `novalidate` or `submit`.
- **`shrinkUploads()` shrinks photos when they're chosen** (the input's `change`), not when the
  form is sent; a submit while that runs waits and goes once it's done (`aria-busy="true"`
  meanwhile, as before). htmx 4's `htmx:confirm` only fires with `hx-confirm`, so the old way
  didn't shrink there. Nothing to change.
- **Moving a theme to htmx 4** (recommended; the starter is on it):
  1. With the theme's package manager: `bun add -d htmx.org@^4.0.2` (a `bun.lock`) or
     `npm install --save-dev htmx.org@^4.0.2` (a `package-lock.json`); don't create the other's lock.
  2. `assets/js/app.js`: after `import htmx from "htmx.org"`, add
     `import "htmx.org/dist/ext/hx-alpine-compat.js";` when the theme uses Alpine.
  3. `hx-disabled-elt` → `hx-disable` (rename an htmx 2 `hx-disable` to `hx-ignore` first).
  4. Event names are colon-separated: `hx-on::before-request` → `hx-on::before:request`,
     `hx-on::after-request` → `hx-on::after:request`, `htmx:load` → `htmx:after:process`,
     `htmx:afterSwap` → `htmx:after:swap`.
  5. Nothing inherits by itself: a form's wrapper with `hx-target="this" hx-swap="outerHTML"`
     around `<form hx-post>` becomes `<div id="form-x">` around
     `<form hx-post hx-target="#form-x" hx-swap="outerHTML">` (or `hx-target:inherited`).
  6. `hx-get` sends only its own form's inputs (add `hx-include="closest form"` to a button
     inside a form); 4xx/5xx answers are swapped (Gaffer's errors say `HX-Reswap: none`; a
     theme action that answers an error with HTML should too, or use `hx-status`). History no
     longer stores pages: back re-fetches. Requests time out after 60 s.
- **`php gaffer` commands no longer run WP-cron** (renders already didn't), and `doctor --wp`
  fetches the front page over HTTP only after its renders: a due plugin update started by cron
  put sites in maintenance mode in the middle of doctor's renders. Nothing to change.

  Find what's left: `php gaffer doctor` (new `htmx` errors; with python3 it also runs htmx's own
  checker for inheritance), or `npx htmx.org@4.0.2 upgrade-check --ext .twig views blocks assets`.
  Check: `npm run build`, then in a browser each form (an error round and a sent one), the cart,
  filters and pagination, and the console without errors.

## 2026-11-20 (stability audit)

Gaffer now owns both halves of what it checks (a form's hidden fields, Turnstile, the Vite build
settings), and `doctor --wp` tries for real what a site's running code relies on in Gravity Forms
and the web server. **This breaks forms and builds until the theme is updated** (steps below).
Why: see `docs/compat-debt.md`.

- **Forms: `@gaffer/forms/meta.twig` replaces the theme's meta partial.** The form name, the
  honeypot and the Turnstile widget are Gaffer's: it may change them (honeypot name, hiding,
  timing) in any release and `FormAction` adapts. Gaffer also loads Turnstile's script on every
  front-end page while Turnstile is on and renders widgets on load and after htmx swaps.
  Find: `grep -rn "form/meta.twig\|cf-turnstile\|name=\"website\"" views` and
  `grep -rln "challenges.cloudflare.com" inc app assets`.
  ```twig
  {# before #} {{ include('components/form/meta.twig', { form: form }, with_context = false) }}
  {# after  #} {{ include('@gaffer/forms/meta.twig', { form: form, turnstile: { appearance: 'interaction-only' } }, with_context = false) }}
  ```
  `turnstile` (optional) takes `appearance`, `language`, `theme`, `size` and `class`. Then delete
  the theme's `views/components/form/meta.twig`, its Turnstile `wp_enqueue_script()` (usually
  `inc/assets.php`) and its Turnstile JS module (`assets/js/components/turnstile.js` and its
  import). The widget now shows only when forms check tokens (site key **and** the server's
  `TURNSTILE_SECRET_KEY`), not on the site key alone. `doctor` errors on a form template without
  the partial and on hand-written halves.
- **`FormAction::run()` is final** (no theme overrides it). Spam reasons in `spam.log` and
  `X-Gaffer-Spam` are now `honeypot`, `turnstile-missing` (no token: a bot, or a widget that
  didn't render) and `turnstile-invalid` (was `turnstile`).
- **Gaffer's Vite plugin.** `vite.config.js` uses `gaffer()` from
  `vendor/onstage2426/gaffer/resources/vite.js`: it sets `public/`, the manifest, `base`, the dev
  hotfile Gaffer reads, the `@gaffer` alias, and stamps `public/.vite/gaffer.json` so `doctor`
  sees when a `composer update` brought Gaffer JavaScript the build doesn't have yet.
  ```js
  // before
  plugins: [hotfile("./public/.vite/hotfile"), FullReload(reloadFiles), tailwindcss()],
  publicDir: false, base: "./",
  resolve: { alias: { "@gaffer": path.resolve("vendor/onstage2426/gaffer/resources/js") } },
  build: { emptyOutDir: true, outDir: "public", manifest: true, rollupOptions: { ... } },
  // after
  import gaffer from "./vendor/onstage2426/gaffer/resources/vite.js";
  plugins: [gaffer(), FullReload(reloadFiles), tailwindcss()],
  build: { rollupOptions: { ... } },
  ```
  Remove the theme's own `hotfile()` function. Then `npm run build` (and deploy `public/`).
  From now on: build again after every `composer update` that `doctor` says changed Gaffer's
  JavaScript.
- **Blocks need `"acf": { "blockVersion": 3 }`** (a doctor error now): ACF 6.8.9 changed the
  default for undeclared blocks on WordPress 7.1, so a block that says 3 behaves the same on
  every version. Find: `grep -L '"blockVersion": *3' blocks/*/block.json`.
- **`Acf::block_array()` no longer reads ACF's internals** and doesn't take a page down: outside a
  block render it throws only with `WP_DEBUG` (in production it logs and returns `[]`). Nothing to
  change.
- **Ajax error answers** (400, 404, 405, 413, 500) carry `HX-Reswap: none`, so an error never
  wipes a form, also under htmx 4. Nothing to change.
- **Gravity Forms answering "invalid" without any message** is now a failed form
  (`form.failed`) plus a PHP log line, instead of a form re-rendered unchanged.
- **New in `doctor --wp`:** `ajax.php` is fetched over HTTP (an error when a host rule or security
  plugin blocks it); outside production a throwaway Gravity Forms form gets one valid and one
  invalid submission through Gaffer's own code, checking the stored values, the entry's status and
  the answers, then is deleted (no notifications or feeds run); many `turnstile-missing` lines for
  one form are a warning. `doctor` also reports Twig's own deprecations (what Twig 4 changes).
- Gaffer requires Twig 3.21 or later (it used 3.21's attributes already).

Check: `php gaffer twig:lint`, `npm run build`, `php gaffer doctor --wp` (no errors), then submit
each form in a browser once (the Turnstile widget renders, the entry arrives without
`X-Gaffer-Spam`).

## 2026-11-20 (second round)

Leaner guidelines, two new doctor warnings, `twig:trace`, and what "stable" covers. Nothing in a
site's runtime code breaks.

- **The guidelines are about half the size** (Gaffer's part of `AGENTS.md` ~26 KB, was ~50 KB):
  rules and decisions stay, procedures and option lists moved into skills. New skill
  **`gaffer-browser`** (screenshots, `render:compare`, `render:styles`, Playwright setup: what
  "Check your work" used to say at length). Signatures are in `vendor/onstage2426/gaffer/API.md`
  (the types guideline points there), command options in `php gaffer <command> --help`. A theme's
  `.ai/guidelines/` that override or quote Gaffer's text: compare them with the new version.
  Find them: `ls .ai/guidelines/` and `grep -rn "render:screenshot\|attrs(" .ai/`.
- **doctor warns when theme code uses Gaffer's internals** ("Uses Gaffer's internal X", or "…
  which this Gaffer doesn't have"): anything not in `API.md` may change in any release. Before:
  `Paths::storage() . '/offers'` worked silently. After: a warning naming the public way
  (`Storage::private_dir()`), or, when there is none, ask for one in your feedback report instead
  of baselining it. Check: `php gaffer doctor`.
- **doctor warns when the theme's own `.ai/guidelines/` pass 30 KB** (they load on every turn):
  move procedures, plans and reference detail into `.ai/skills/{name}/SKILL.md`.
- **`php gaffer twig:trace <template>`**: who renders or includes a template (computed names and
  search cards included), what it includes and which variables it reads.
- **`API.md` now says what stability covers:** what runs on the site (the PHP a theme calls, Twig,
  the JavaScript, stored data and conventions). The tooling (commands, options, output, exit codes,
  `--json`, doctor's checks, MCP tools, AI files) may change in a minor release with an entry here;
  the entry points in theme files (`gaffer`, `ai:update` in `post-update-cmd`) stay.

Check: `php gaffer ai:update`, then `php gaffer doctor --wp`; fix or report the new warnings.

## 2026-11-20

A public private-files folder, admin paths refused by renders, and docs. Nothing breaks.

- **`Gaffer\Storage::private_dir(string $relative)` is public API**: `storage/{relative}`,
  created with a deny-all `.htaccess` and a `.gitignore` (what Gaffer uses for its logs and
  backups). For files a site must keep private: documents visitors upload, PDFs it generates.
  It throws on an empty path or one with `..`. Find a theme's own copy:
  `grep -rnE "Paths::storage\(\)|Require all denied" app/ inc/`.
  Before: `$dir = Paths::storage() . "/offers/{$id}"; mkdir(...); file_put_contents("$dir/.htaccess", ...)`.
  After: `$dir = Storage::private_dir("offers/{$id}");` (the `.htaccess` it already wrote stays).
- **`render:page` and the `gaffer-render` MCP tool refuse `/wp-admin/…` and `wp-login.php`**
  with an error result. They used to answer `"status":"ok"` with WordPress's 404 page: a
  render runs the front-end template loader, which can't show an admin screen. Test an admin
  screen's callbacks with WP-CLI.
- **Docs:** e-mail bodies and PDF sources are whole documents with inline styles and plain
  colour values (the fragment and token rules are for pages); passing a theme object with display
  methods to a template is fine; files that need a stable public URL (images in sent mail) get
  their own folder in the theme's `.htaccess`; `ajax:call` sends real mail and no files (`curl -F`
  for uploads); a `render:screenshot` run replaces its widths' files, `--full` parts included.
  doctor's "Nothing renders or includes this template" hint now says to write the name literally.

Check: `php gaffer ai:update`, then `php gaffer doctor --wp`.

## 2026-11-19 (third round)

doctor's advice. Nothing breaks.

- **An unregistered block only in drafts or pending posts is a warning**, "Unpublished content
  uses unregistered block …" (it was an error: nobody sees it until it's published). Published,
  scheduled and private content stays an error. The hint now also names
  `migrate:remove-block <block>`, or deleting a draft nobody needs.
- **The "Hardcoded ID" hint no longer suggests a config value**: `config/` is the same on every
  database, so an ID there is still hardcoded. What the client picks (a form, an image, a page) is
  an ACF options field; what WordPress knows comes from WordPress (a menu location,
  `get_option('page_on_front')`, `wc_get_page_id()`; a Gaffer form by its title). Find IDs moved
  to config for the warning's sake: `grep -rnE "=> [0-9]{2,}" config/`. Move one to an options
  field when the client should pick it or it differs between live and dev.
- **Baselining** (`cli` guideline): a warning you've decided to keep (a parked template, a known
  exception) goes in the baseline with the reason in the commit; that counts as handled.

Check: `php gaffer ai:update`, then `php gaffer doctor --wp`.

## 2026-11-19 (second round)

doctor fixes and docs. Nothing breaks.

- **doctor no longer reports "Sends files without multipart" when a partial writes the `<form>`**:
  `hx-encoding`/`enctype` counts in any template the form template includes. A theme that moved
  the `<form>` tag into each `views/forms/*.twig` only for this check may keep it or move it back
  into the shared partial.
- **doctor's "Includes … inside a `<form>`, and it has a `<form>` itself" ignores Twig comments**:
  a `{# … <form> … #}` in the partial no longer counts. Comments reworded to dodge it can stay.
- **`render:styles`/`render:compare`/`render:screenshot` with an HTML file and
  `PLAYWRIGHT_WS_ENDPOINT`** stop with "give the design's URL" instead of Chromium's
  `ERR_FILE_NOT_FOUND`: the remote browser can't read this machine's files.
- **Docs** (`php gaffer ai:update`): a changed card template is rebuilt by WP-cron on a later
  page visit, not before the next render (`wp gaffer-search rebuild <index>` while working on
  cards); a Gravity Forms Date field sent from `<input type="date">` must be a Date Picker with
  the format yyyy-mm-dd; save a post's content before a one-off script overwrites it; screenshots
  are Chromium, so iOS quirks need a device.

Check: `php gaffer ai:update`, then `php gaffer doctor --wp`.

## 2026-11-19

File uploads in forms. Nothing breaks; one fix changes stored text.

- **File Upload fields** in Gravity Forms forms: the template sends `files[x]` (`files[x][]` with
  `multiple`) from a form with `hx-encoding="multipart/form-data"`; Gravity Forms validates
  (extensions, size, number, required) and stores them with the entry. After errors,
  `form.files('x')` lists the files Gravity Forms kept (`Forms\KeptFile`: `name`, `value`): send
  each back as `<input type="hidden" name="kept_files[x][]" value="{{ file.value }}">`. Example
  and the rest in the `gaffer-form` skill (`php gaffer ai:update`).
- **`@gaffer/forms.js`**: `shrinkUploads()` shrinks photos in inputs with
  `data-gaffer-resize="2000"` before htmx sends the form (JPEG, 2000 px on the long side; HEIC
  always as JPEG). Opt-in: `import { shrinkUploads } from "@gaffer/forms.js"; shrinkUploads();`.
- **An ajax request larger than PHP's `post_max_size` gets a 413** (it was a 400: PHP had
  dropped every argument). htmx doesn't swap it: `htmx:response-error` with status 413.
- **Fix: backslashes in form text reached Gravity Forms stripped** (`C:\temp` was stored as
  `C:temp`): Gravity Forms reads its input slashed, like `$_POST`. Values are now slashed for it.
  Nothing to change; entries saved before keep the stripped text.
- **Fix: a field's error that Gravity Forms gives as a list** (a multi-file field: one message per
  refused file) shows as one text instead of "Array".
- **doctor:** files sent without multipart, a File Upload field sent as `fields[x]` (or another
  field as `files[x]`), `files[x]` without `[]` for a multi-file field. `forms:show` prints a File
  Upload field's rules after its type.

Check: `php gaffer ai:update`, then `php gaffer doctor --wp`. A site with File Upload fields
(they were left out before): add the inputs as in the skill.

## 2026-11-17 (second round)

- **`Search::facet_counts()` takes a list of fields** and returns their counts per field, from
  one search (several fields used to be several searches). Find: `grep -rn "facet_counts(" app/ inc/ blocks/`.
  Before `Search::facet_counts('products', 'brand', $term, filter: $f)` → after
  `Search::facet_counts('products', ['brand'], $term, filter: $f)['brand']` (null without the
  plugin: `?->`/`?? []` as before). Several: `Search::facet_counts('products', ['on_sale', 'fuel'])`
  → `['on_sale' => ['yes' => 3, 'no' => 11], 'fuel' => [...]]`.
- **`render:screenshot --select=selector=value`** (MCP step `{"select": …, "value": …}`) chooses a
  `<select>`'s option by its value or its text, in order with the other steps.
- **doctor:** a guideline's `wp-content/…` path is looked up from the WordPress root (it was
  reported missing). "Stored values point to … no longer has" says when the field now lives in
  another `fields/` target (moved from one options page to another) and gives the
  `migrate:adopt-fields <target>` that repairs it, instead of `wp search-replace` on keys.
- gaffer-search's releases ship `docs/migration.md`: after a plugin update, read
  `wp-content/plugins/gaffer-search/docs/migration.md` like this file.
- Guidelines: `Menu` `items()`; run `php gaffer doctor` after editing a block template
  (`twig:lint` doesn't see the wptexturize `>`); `wp search-replace` for a URL in content.

Check: `php gaffer ai:update`, then `php gaffer doctor --wp`.

## 2026-11-17

From a site built from a design with gaffer-search. Nothing breaks; some new warnings and options.

- **`Search::facet_counts($index, $field, $term, filter: …)`**: a count per value in one search
  (value, a term's slug => count, most first; values without documents aren't listed). Replace
  a `Search::count()` per term (a brand overview). Find: `grep -rn "Search::count(" app/ inc/ blocks/`
  inside a loop over terms. Before `array_map(fn($b) => Search::count('products', $b), $brands)`
  → after `$counts = Search::facet_counts('products', 'brand')` and `$counts[$brand->wp->slug] ?? 0`
  (the field must be in the index's `facets`; its own filter is left out of its counts).
- **doctor:** a warning for an `archive.sorts`/`default_sort` field the index can't sort on
  (the engine ignored it; a number sorts once it's in `facets`, `sortable` is text only), and
  for an `AGENTS.md` older than `.ai/`, `config/ai.php` or Gaffer's guidelines (run
  `php gaffer ai:update`; after this update it fires once). "HTML in PHP" no longer counts a
  `preg_*()` pattern (`'#<h2[^>]*>#'`) as markup.
- **Archive JS:** a multi field reads `filter[x]=a` from the URL as well as `filter[x][]=a`
  (a hand-written link checked nothing, and the first change dropped it). Links rewritten to
  `[]` for this can stay.
- **`render:screenshot`** takes a design too (a URL, with `DESIGN_COOKIE`, or an HTML file),
  `--clip=<selector>` (one element whole) and `--scroll=<px|selector>` (a step, in order with
  `--click`/`--hover`/`--fill`; MCP: `clip` and `{"scroll": …}`); a run replaces only its own
  widths' files. A browser that doesn't start says what to try (`PLAYWRIGHT_WS_ENDPOINT`, the
  server's exact version, basic auth as `wss://user:pass@host/`).
- **`render:compare`** compares colours as colours: `rgba(…)` against Tailwind 4's `oklab(…)`
  is no longer a difference, and colours print as `rgb()`/`rgba()`.
- **`ajax:call`** prints the cookies the answer sets (`set-cookie:` lines; `cookies` in `--json`).
- Skills: the form meta partial's contents (`gaffer-form`); in `gaffer-search`: a URL filter
  needs a form control (hidden if the design has none) or the first search drops it, a control
  outside the form needs its own `@change="run()"`, preset price ranges.
- gaffer-search (plugin, update it too): a `meta` field named like an SQL keyword (`range`)
  no longer breaks the product index build (it built 0 documents); `problems()` reports a
  number in `sortable`.

Check: `php gaffer ai:update`, then `php gaffer doctor --wp`.

## 2026-11-15 (pre-1.0 CLI)

Command names, exit codes and `--json` settled for 1.0 (API.md lists them).

- **Migrate commands renamed:** `migrate:field` → `migrate:rename-field`, `migrate:block` →
  `migrate:rename-block`, `migrate:fields` → `migrate:adopt-fields`. `migrate:backups` lists the
  backups (`migrate:rollback` without an argument did); `migrate:rollback <backup>` needs its
  argument. `migrate:remove-leftovers` needs its target (none meant every block: run it per
  block). Find: `grep -rnE "migrate:(field|block|fields)\b|migrate:rollback *$|remove-leftovers *$" .ai/ scripts/ *.md`.
  Before `php gaffer migrate:fields post_type/product` → after `php gaffer migrate:adopt-fields post_type/product`.
- **`--json` prints an object:** `doctor --json` and `twig:lint --json` print
  `{"errors": 0, "warnings": 3, "info": 1, "baseline": {…}|null, "findings": [ … ]}` instead of a
  bare array (the array is `findings`). `render:diff --json` prints `{"error": "…"}` when it can't
  compare. Find: `grep -rn "\-\-json" scripts/ .github/ *.sh`. Before
  `jq '.[] | select(.level=="error")'` → after `jq '.findings[] | select(.level=="error")'`.
- **Exit codes:** `doctor` 2 for contradicting options or `--prune` without a baseline;
  `render:diff` 0 same, 1 different, 2 trouble; `ajax:call` 1 for HTTP 400 or more; `migrate:*` 2
  when it wrote but the read-back failed (roll back). Check scripts that treat any non-zero alike.
- **Render snapshots:** `render:snapshot --path` → `--page`; `--session` is new (the diff uses
  the same visitor). Snapshots have `"format": 1`: one taken before this update is refused, take
  it again before changing code. Find: `grep -rn "render:snapshot.*--path" .ai/ scripts/`.
  `render:diff` no longer reports a page that errored the same way in the snapshot as changed.
- **`render:screenshot --fill`** splits at the first `=` outside `[...]` and quotes (it was the
  last): `--fill='input[name="q"]=a=b'` fills `a=b`. `render:styles`/`render:compare` refuse
  `--width=390,1440` (it silently used 390): run them once per width.
- **`doctor-baseline.json`** gets `"version": 1` on the next `--baseline`/`--prune` (commit it);
  older files still read.
- The hidden `doctor:render` is now `render:page`: a staged copy (`--theme`) must run this Gaffer
  too (update its `vendor/` with the live theme's). Render children and the browser scripts have
  time limits now (a hung page is an error, not a hung command).
- Screenshot folders in `storage/screenshots/` get a short hash in their name
  (`contact-cfd191cd`); old folders can be deleted. A `--full` screenshot stops at 20,000 px
  (`truncated`).
- **AI files:** `ai:install` keeps the rest of `config/ai.php` (it dropped `mcp_production`);
  the MCP config keeps `{}` values (`"env": {}` became `[]`) and, in TOML, quoted keys, inline
  tables and the next table's comments; a theme's `.ai/guidelines/plugins/woocommerce.md` (or
  another plugin Gaffer knows) is left out while that plugin is inactive; `AGENTS.md` with one
  Gaffer marker but not its pair is an error instead of a second block.

Check: `php gaffer doctor --json | head` shows `"errors"`; `.ai/` mentions no old command names
(`doctor` reports stale mentions); retake your render snapshots.

## 2026-11-15 (pre-1.0 doctor)

Doctor's errors can't be baselined, so what isn't a certain breakage is now a warning or info:
`TexturizeCheck` (a `>` inside an attribute) is a warning, and skipped when the theme turns
wptexturize off (`add_filter('run_wptexturize', '__return_false')` or
`remove_filter('the_content', 'wptexturize')`); the MCP checks and gaffer-search's `problems()`
are warnings; "Index isn't built yet (building now)" is info.

False errors fixed: `View::render("cards/{$type}.twig")` (a computed name: the templates matching
it count as used), ACF's `block == all` location rule, a PSR-4 list (`"Theme\\": ["app/"]`),
`alt` and `.attrs()` in separate `{% if %}`/`{% else %}` branches, two options pages with the same
field name but their own `post_id` (now checked with `--wp`, by `post_id`), an ajax action whose
constructor calls WordPress (no longer constructed), `vendor/`/`public/`/`storage/` skipped below
the theme root. Templates read through `extends`/`use`/`import`/`from` and an embed's blocks (also
for search card versions: a card that extends a layout rebuilds when the layout changes).

Messages no longer carry file:line or page lists (they moved to the finding's file/line and the
hint), so they match the baseline across edits: render notices, "stores … for a field" (stale
fields), unregistered blocks, the search warnings' counts. Baselined ones show up once more.
Find: `grep -nE '\.(php|twig):[0-9]+|stores ".*" for a field' doctor-baseline.json`.
Before `{"check": "render", "message": "inc/x.php:12 Undefined variable $y"}` → after
`{"check": "render", "message": "Undefined variable $y", "file": "inc/x.php"}`.

Check: `php gaffer doctor --wp`; with a baseline: `php gaffer doctor --wp --prune`, then
`--baseline`. Templates only `extends`/`use`/`import` loaded are no longer reported as unused.

## 2026-11-15 (pre-1.0 API)

The last breaking changes before 1.0. Each item: what changed, how to find it, before → after.

- **`Acf::field_array()` is gone**, and so is `Acf::term_array()`. Inside a block's
  `functions.php`: `Acf::block_array('name')` (it throws outside a block render). For a post:
  `Acf::post_array('name', $post)` (a `Post`, not an ID). For a term:
  `get_field('name', $term->wp) ?: []`.
  Find: `grep -rn "field_array\|term_array" app blocks inc *.php woocommerce`.
  Before `Acf::field_array('vragen')` → after `Acf::block_array('vragen')`;
  before `Acf::field_array('bestanden', $this->wp->ID)` → after `Acf::post_array('bestanden', $this)`.
- **`Post::current()` is the queried post** (like `Term::current()`): the singular post or page
  the request is for, `null` on archives, search results and the like. It was `get_post()`, the
  loop's post. The loop's post is `Post::from(get_the_ID())`, and `post.is_current()` compares
  with the queried post too.
  Find: `grep -rn "::current()" app blocks inc *.php woocommerce | grep -v "Term::\|Pagination::"`.
  Look at each use outside a singular template (`index.php`, `archive.php`, `home.php`, a block
  rendered inside a query loop): there it used to be the first/loop post. A workaround like
  `Product::from(get_queried_object_id())` can become `Product::current()`.
- **`meta()` is `null` for a key that isn't stored** (`Post`, `Term`, `MenuItem`); a stored `''`
  stays `''`. Find: `grep -rn "meta(" app | grep "=== ''\|== ''\|!== ''"`; code that checks
  `is_string($x) && $x !== ''` or casts (`(string)`, `(int)`) is fine as it is.
- **Pagination:** `pagination.pages()` is a list of pages with `number`, `link`, `current` and
  `gap` (a left-out stretch: number and link null), no longer a map of page => URL; the unused
  `pagination.items` is gone. Find: `grep -rn "pages(" views`. Before:
  `{% for page, link in pagination.pages(2) %}{% if link is null %}…{% else %}<a href="{{ link }}"{{ page == pagination.page ? ' aria-current="page"' }}>{{ page }}</a>{% endif %}{% endfor %}`
  → after:
  `{% for p in pagination.pages(2) %}{% if p.gap %}…{% else %}<a href="{{ p.link }}"{{ p.current ? ' aria-current="page"' }}>{{ p.number }}</a>{% endif %}{% endfor %}`.
- **`final`:** every public method of `Post`, `Term` and `Attachment` is final except
  `thumbnail()`. A theme subclass that redefines one (none of the known themes does) fails at
  load. Find: `php gaffer doctor` (a fatal), or compare `grep -n "function " app/Types/*.php`
  with the methods in API.md.
- **Forms: `form.failed` covers the whole-form error.** Gravity Forms' refusal of the whole form
  (and a validation message for a field the template doesn't have) sets `failed`, with the
  reason as plain text in `message`; `form.error('')` is always null now.
  Find: `grep -rn "error('')" views`. Before `{% if form.failed or form.error('') %}<p>{{ form.error('') ?: 'Er is iets misgegaan.'|t }}</p>`
  → after `{% if form.failed %}<p>{{ form.message ?: 'Er is iets misgegaan.'|t }}</p>`.
  `form.values`/`form.errors` are private: use `value()`, `error()`, `checked()`.
- **`Turnstile::site_key()` is gone** (templates use `config('turnstile.site_key')`).
  Find: `grep -rn "site_key()" app inc blocks`.
- **Search is experimental** until gaffer-search 1.0 (API.md): it may still change in a minor,
  always with an entry here. `Search::available()` is gone: templates read `.available` on what
  they get. Find: `grep -rn "Search::available" app inc woocommerce`.
- **`archive.sorts` lists values only**; the template labels them (so they can be translated
  with `|t`; config loads before WordPress's translations). `search.sorts` is a list of strings.
  Find: `grep -n "sorts" config/search.php` and `grep -rn "search.sorts\|sort.label" views`.
  Before `'sorts' => ['' => 'Populair', 'price:asc' => 'Prijs oplopend']` and
  `{% for sort in search.sorts %}<option value="{{ sort.value }}">{{ sort.label }}</option>{% endfor %}`
  → after `'sorts' => ['', 'price:asc']` and
  `{% set sort_labels = { '': 'Populair'|t, 'price:asc': 'Prijs oplopend'|t } %}{% for sort in search.sorts %}<option value="{{ sort }}">{{ sort_labels[sort] }}</option>{% endfor %}`.
  The old map shape throws on the archive page; `doctor` names it.
- **Search JS: `error.stale`.** A failed request's error (Alpine `error`, the
  `gaffer-search:error` event's `detail`) has `stale`: true for any 4xx (the page's token no
  longer works: ask for a refresh), false otherwise (try again later). Check `stale` instead of
  listing codes, so new codes are covered. Find:
  `grep -rn "invalid_token\|token_expired" views assets`. Before
  `x-show="['invalid_token', 'token_expired'].includes(error?.code)"` → after `x-show="error?.stale"`,
  and `x-show="error && !['invalid_token', 'token_expired'].includes(error.code)"` →
  `x-show="error && !error.stale"`. Gaffer reserves names starting with `_` and the names it
  ships in its JS components (API.md). Rebuild the assets (`bun run build`).

Check: `php gaffer twig:lint`, `php gaffer doctor --wp`, a page with pagination, a form's error,
the archive's sort menu.

## 2026-11-15

Bug fixes from a pre-1.0 review. Mostly nothing to do; what may look different:

- **Password-protected posts.** `post.content()` now shows WordPress's password form until the
  visitor enters the password (it printed the content to everyone). Check a protected page if
  you have one.
- **Ajax actions bind only what a visitor could open.** A `Post`/`Term` parameter (`run(Product
  $product)`) answers 404 for a draft, private, trashed or password-protected post, a revision,
  or a term of a non-public taxonomy, unless the logged-in user may read it. Find: `grep -rn
  "function run(" app/Ajax`; an action that bound such posts on purpose must take an `int` and
  check it itself. Ajax answers also send `Cache-Control: no-store` and `X-Robots-Tag: noindex`.
- **`menu_item.is_external()`** looks at the host: `#anchors`, `mailto:` and `tel:` links are
  no longer external, a link to another host that starts like the site's is. An `#anchor` item
  is no longer `is_current()` on the front page.
- **`parent()`/`ancestors()` on a subclass** (`image.parent()`, a variation's product) return the
  parent instead of null.
- **`Vite::tags()`** prints `.ts`/`.tsx`/`.jsx`/`.mjs` entries as scripts and `.scss`/`.sass`/
  `.less`/`.pcss` as styles, and throws for any other entry (it printed nothing).
- **Blocks:** a `block.json` name outside `acf/` is an error (its field keys came out wrong).
  `Blocks::markup()` escapes backslashes like WordPress (a value ending in `\` broke the block).
- **ACF values in repeaters and groups** were decoded twice, wysiwyg sub fields included: a
  wysiwyg answer in a repeater now keeps its `&amp;`.
- **`migrate:remove-field` on a `fields/` target** only removes ACF's rows: rows with the same
  name and no field key next to them (WooCommerce's `_price`/`_weight`, a plugin's term meta)
  are left alone, with a note.
- **Turnstile:** an empty token fails without asking Cloudflare.
- **Search archives:** `?paged=N` (plain permalinks, old links) redirected to itself forever;
  it now 301s once to `?offset=…`. A category page filtered on its own field
  (`/product-category/x/?filter[category]=y`, which shows the category anyway) has the category
  page as canonical instead of itself. `redirect_search` leaves a search of another post type
  (`?s=…&post_type=post`) alone. Filter lists with an empty entry stay lists in the canonical,
  and values like `01` and `1` are no longer merged.
- **Archive pages are smaller:** `search.initial()` leaves the cards out (the page already shows
  them; one shop went from 225 KB to 142 KB) and survives invalid UTF-8 (it printed
  nothing). A theme that rendered cards from the Alpine `results` of the initial data would
  need them back; none of the known themes does.
- **Search JS:** after the box was emptied, typing the same query again showed nothing, and an
  answer arriving after it was emptied filled the cleared results. Rebuild the theme's assets
  (`bun run build`).
- **Migration backups and log** carry `"format": 1`; `migrate:rollback` refuses a backup of a
  newer format or of another site (a multisite's other blog), and skips rows whose post or term
  was deleted since (it refused the whole rollback). The log gets a `committed` line right after
  the database commit, before the read-back's `written`/`verify_failed`. `migrate:set` keeps
  numeric field names; a rewrite whose result doesn't parse back is refused.

Check: `php gaffer doctor --wp`.

## 2026-11-14

Nothing breaks; three things `doctor --wp` and `gaffer-render` now say.

- **Search changes that never reach the index.** With gaffer-search 0.4.0 (feature
  `pending_updates`), `doctor --wp` warns when content changes have waited more than 5 minutes
  for an index: WP-cron applies them, and it doesn't run on a password-protected site or with
  `DISABLE_WP_CRON` and no system cron (WP-CLI never runs it either). Fix: `wp gaffer-search
  update` now, and a system cron running it every minute (the plugin's `docs/hosting.md`).
  `wp gaffer-search verify` and the `gaffer-search` MCP tool show the same.
- **SEO in `gaffer-render`.** Every render has `seo`: title, meta description, canonical and
  robots read from the page. Use it instead of an SEO plugin's PHP API from WP-CLI (Yoast
  answers differently there).
- **Yoast outside production.** Yoast SEO only writes its SEO data (indexables) on production,
  so on a dev copy, content changed or imported there keeps the titles and descriptions of
  Yoast's older rows. `doctor --wp` says so (info) with the command that rebuilds them.

Check: `php gaffer doctor --wp`.

## 2026-11-09

### Search filters with `any`, `all` and `not`

Nothing breaks. With gaffer-search 0.3.0 (feature `filter_groups`) a filter can say more than
"these fields, these values": `'tags' => ['all' => ['sale', 'new']]` (every value), and groups
`'any' => [filter, …]`, `'all' => [filter, …]`, `'not' => filter` of the usual filter shape, in
`Search::cards()`/`count()`, the archive's URL (`filter[any][0][on_sale]=yes`) and JS
(`{any: [{…}, {…}]}`); `createFilterForm()` has a field `type: "all"` (checkboxes named
`filter[x][all][]`). A theme filter with them on an older plugin throws a `LogicException`
(the plugin would drop them and find more); a visitor's groups in the URL are left out there.
Pages whose URL has a group are noindex. `doctor` sees fields inside groups.

So `any`, `all` and `not` can't be index field names any more (the plugin's `problems()`, so
`doctor --wp`, names one, and the index isn't defined). Find: `grep -nE "=> '(any|all|not)'"
config/search.php` and those names as keys of `fields`. Check: `php gaffer doctor --wp`.

### gaffer-search 0.3.0 rebuilds every index once

Not Gaffer's change, but it lands with this one: the plugin's new Fuzor stores indexes in a new
layout, so each site rebuilds its indexes once after the plugin update (on the first request
that loads WordPress, then the next WP-cron run). Until a site's rebuild is done, archives and
filters show no products. On a large shop: update at a quiet hour and run
`wp gaffer-search rebuild --url=<site>` per site straight away. Check: `php gaffer doctor --wp`
(each index "built and current").

## 2026-11-08

### `doctor`: "A class in both class and :class" only for string and array bindings

Nothing breaks. Alpine's object syntax (`:class="{ hidden: !open }"`) removes a false key's
classes even when they're in the static `class`, so `class="hidden" :class="{ hidden: !open }"`
is fine and no longer warned about (a false positive since the check came in). String and array
bindings (`open ? 'a' : 'b'`, `['a', x]`) still are: Alpine only removes what it added. The
warning is now "A class in both class and a string :class".

Find: `grep -n "both class and :class" doctor-baseline.json`. Check: `php gaffer doctor`; if you
baselined these warnings, `php gaffer doctor --prune` drops the old entries.

### `archive.bounds`: only the ranges, with gaffer-search 0.2.0

Nothing breaks. With a gaffer-search that has the feature `facet_stats` (0.2.0), the bounds
search asks for the fields' ranges only, instead of counting every value: a whole shop's price
bounds take about 3 ms instead of 25 at 30,000 products (on a 7,700-product shop 0.7 instead of
4.4). Same `min`/`max`. Until the plugin is updated, `doctor --wp` says so (info).

Find: `grep -n "bounds" config/search.php`. Check: the price slider's ends on the shop and a
category are unchanged.

### `Search::searchbar()`: `attributes` is required, and second

Breaks every searchbar. `Search::searchbar($index, $attributes, $limit = 8, $facets = [])`: the
fields each hit carries come second and can't be left out (an empty list throws). Left out, a hit
carried every stored field, its cards too: on a site with stored cards about 150 KB per keystroke
instead of 7 KB. An old call fails with a TypeError (an int where the list goes).

Find: `grep -rn "Search::searchbar(" --include=*.php --include=*.twig .`

```php
// before
"search" => Search::searchbar("products", 10, ["price", "brand"]),
"search" => Search::searchbar("products", 8, ["price", "category"], ["id", "url", "thumbnail", "price", "category"]),
// after: the fields the searchbar template reads from `hit.` (`formatted` and `labels` come with every hit)
"search" => Search::searchbar("products", ["id", "url", "thumbnail", "price", "brand"], 10, ["price", "brand"]),
"search" => Search::searchbar("products", ["id", "url", "thumbnail", "price", "category"], 8, ["price", "category"]),
```

To list them: `grep -on "hit\.[a-z_]*" views/components/searchbar.twig | sort -u`. Also consider
`createAlpineSearch({ minQueryLength: 2 })` in the searchbar's JS: one letter matches nearly the
whole catalog and is the bar's slowest request.

Check: the searchbar shows its hits with thumbnail, price and names, and `doctor --wp` is clean.

## 2026-11-05

### `Search::counter()`: counts from the browser, without booting WordPress

Nothing breaks. `Search::counter($index, $term)` gives `available`, `endpoint` and `token` for
the plugin's boot-free endpoint, with `Search::count()`'s scope, and `createCounter()` in
`@gaffer/search.js` asks it. The token doesn't expire, so it can sit in a cached page.

Replace an ajax action that only wraps `Search::count()`, which boots WordPress and WooCommerce
for each count. Find: `grep -rln "Search::count(" app/Ajax`.

```php
// before: app/Ajax/FinderCount/FinderCount.php, called on every choice
View::render('components/finder-count.twig', ['count' => Search::count('products', filter: $filter)]);
// after: in the block's data; the action and its template go
'counter' => Search::counter('products'),
```
```js
// after
const count = createCounter({ endpoint, token });
const total = await count(filter); // a number; null: show no number; undefined: a newer call replaced it
if (total !== undefined) this.total = total;
```

Afterwards: run `php gaffer ai:update`; check the count equals what the shop shows for the same
filter, that the page shows plain text when the endpoint fails (block the request in the
browser), and that the console shows no `Gaffer Search:` warning (a filter on a field the index
doesn't filter on is ignored, and `doctor` can't see filters built in JavaScript).

### Field report fixes: new checks, `migrate:set`, `Search::count()`, screenshots in parts

Nothing breaks; new warnings may show up. After updating, run `php gaffer ai:update`, then
`php gaffer doctor --wp` and work through what's new:

- **`alt` next to `.attrs(`** (error): `attrs()` writes the `alt` already, so the tag had two
  and browsers kept the media library's. Decorative images: `image.attrs('medium',
  decorative: true)` writes `alt=""`. Find: `grep -rn "attrs(.*alt=" views blocks`.
  ```twig
  {# before #} <img class="h-8" {{ logo.attrs('medium') }} alt="">
  {# after  #} <img class="h-8" {{ logo.attrs('medium', decorative: true) }}>
  ```
- **A class in both `class` and `:class`** (warning): Alpine never removes the static one.
  Move every state's classes into `:class`.
- **Alpine directives outside any `x-data`** (`doctor --wp`, warning): Alpine ignores them.
  Add `x-data` on the element or an ancestor.
- **WordPress's theme-compat sidebar on a page** (`doctor --wp`, warning): a theme without
  `sidebar.php` on WooCommerce pages. `remove_action('woocommerce_sidebar',
  'woocommerce_get_sidebar')` in `inc/`.
- **The front page a visitor gets links assets the build no longer has** (`doctor --wp`, error):
  a page cache from before the last build. Clear it after every build.
- **Templates changed since the build with classes the built CSS lacks** (warning): run
  `npm run build`. A class that is no Tailwind utility (a JavaScript hook) shows until then.
- **Search:** a filter on a field the index stores but doesn't have in `facets` (warning; the
  engine ignores it, find: `grep -rn "filter\[" views`); the engine's warnings now show in
  `doctor:render`/`doctor --wp`; an index built with 0 documents while posts are published
  (warning: `wp gaffer-search rebuild`); with gaffer-search of the same day, a taxonomy field
  in `searchable` is a `problems()` error (the index stays defined without it).
- **`Search::count($index, $term, filter: [...])`**: counts in the archive's scope from the
  index. Replace hand-written `product_visibility`/`exclude-from-catalog` tax_queries that count
  or list "what the archive shows": `grep -rn "exclude-from-catalog" app inc blocks`.
- **`php gaffer migrate:set <block> <field> '<json>' [--post=ID]`** sets a block field's value
  in stored content (dry run, backup, rollback), instead of a `wp eval-file` script.
- **`render:screenshot`**: `--full` scrolls through the page first and writes it in parts
  (`--slice=1200`, `{width}-{n}.png`; the output and the MCP tool's `files` list them, with the
  page height); `--hover=<selector>` as a step; `--local-storage key=value` for a returning
  visitor's stored state.
- **`render:compare <design> <path>` and `render:styles <target> <selector>...`** (+ MCP
  `gaffer-compare`/`gaffer-styles`): a design (URL or HTML file) against a page, text by text
  (size, weight, line height, letter spacing, font, colour, gap above), and one element's
  computed styles and children's boxes. A design behind a login: `DESIGN_COOKIE=name=value` in
  `.env` (add it to `.env.example` commented out). Delete your own Playwright probe scripts.
- **`wp_print_font_faces` is removed from `wp_head`** when the theme has no `theme.json`
  (~5 ms a page, it printed nothing). Remove a theme's own `remove_action` for it:
  `grep -rn "wp_print_font_faces" inc`.
- `labels` example corrected in the `gaffer-search` skill: names visitors read go through
  `__()` on a site translated with the theme's strings (ACF choices are translated as ACF field
  settings).

Check: `php gaffer doctor --wp` and `php gaffer twig:lint`.

### Search: names for values of fields without terms (`labels`)

An index can name the values of its other string fields (an ACF select mapped in `meta`, a
`document` field, the built-in `stock_status`), the way taxonomy fields have their term names:
`labels => [Theme\X::class, 'method']`, returning field => value => name. Responses (`labels`)
and hits (`hit.labels`) then carry those names, so facets and filter chips show "Plug-in hybride"
instead of `plugin_hybride`. Needs the gaffer-search plugin of the same day (`0.0.0+dfb5bca` or
newer); an older plugin refuses the index (`doctor --wp`: unknown key "labels"), so update the
plugin first. `doctor --wp` now warns about values without a name in the archive's `facets` and
in labelled fields: such a warning on an existing site is a slug visitors see today.

Find: the string fields a template shows from search data: `grep -n "facets\|meta" config/search.php`,
then `grep -rn "hit\.\|facets\." views assets` for those field names shown as they are.

```php
// config/search.php
'labels' => [Theme\Search::class, 'labels'],

// app/Search.php
public static function labels(): array
{
    return ['fuel' => acf_get_field('field_post_type_product__brandstof')['choices'] ?? []];
}
```

Check: `php gaffer doctor --wp` (no "values without a name" warning), and the archive's facets
show names.

### A staged copy reads the live site's object cache

`php gaffer` (and `wp --require=…/bin/stage.php`) run from a staged copy of the theme used to
switch the persistent object cache (Redis) off, so the copy read old cached values from the
database that the live site never reads: on one site a product lost six attributes in
`render:diff --theme=<copy>` because of a pre-Redis WooCommerce transient. Now the copy reads
live's cache; what it writes stays in the copy's process, and each key it writes is dropped
from live's cache, so live reads the database again (a build from the copy no longer leaves live
with an old cached option). Nothing to change. A guideline in `.ai/` saying not to trust renders
through a theme copy can go: `grep -rn "theme copy\|--theme" .ai`. Check: a `render:diff --theme`
of a page that differed only through the copy now matches.

## 2026-11-04

### Search: products' prices, stock, SKU and rating are built in; remove their `meta` lines

With the gaffer-search plugin of the same day (`0.0.0+2ea4fa9` or newer, see its
`docs/migration.md`), every products index has `price`, `regular_price`, `sale_price`, `rating`
(floats), `stock_status`, `sku` (strings) and `on_sale` (`yes`/`no`), read from WooCommerce's
product objects instead of post meta. A `meta` line for one of them, or for WooCommerce's own
keys, is now an error in `doctor --wp`; the engine ignores the line and uses the built-in field
(so the plugin can be updated before the theme). Remove the lines. Also from that plugin version
on, an archive's term lock is never dropped: a product taxonomy archive whose taxonomy the index
doesn't store shows no products (before: every product); `doctor --wp` warns about those.

Find: `grep -n "_price\|_regular_price\|_sale_price\|_stock_status\|_sku\|_wc_average_rating" config/search.php`

```php
// before
'meta' => [
    '_sku' => 'sku',
    '_price' => ['price', 'float'],
    '_regular_price' => ['regular_price', 'float'],
    '_sale_price' => ['sale_price', 'float'],
    '_stock_status' => 'stock_status',
    '_stock' => ['stock_quantity', 'int'],
],
// after: only what isn't built in (an empty 'meta' => [] can go)
'meta' => [
    '_stock' => ['stock_quantity', 'int'],
],
```

Field names stay the same, so `facets`, `sorts`, filters, `bounds`, templates and JS need no
change; if a theme mapped them under other names (`'_price' => 'prijs'`), rename the field to the
built-in one everywhere (`grep -rn "prijs" config views assets`). Also:
- WooCommerce's old archive URLs (`?min_price=`, `?orderby=price`) redirect to the `price` field
  without any `meta` line, and `?orderby=rating` to `rating:desc` (before: dropped).
- A variable product's `regular_price`/`sale_price` are now its cheapest (or, with
  `variation_price: 'default'`, its default) variation's; before they were usually missing
  (WooCommerce doesn't store them on the parent). A card or filter reading them can show more.

Check: `wp gaffer-search rebuild`, then `php gaffer render:diff <snapshot from before>` and the
archives' totals and orders (shop, a category, price sorts both ways, a price range, the in-stock
and sale filters, a search); `php gaffer doctor --wp`.

### fuzor-wp migration support removed; `console.raw_baseline` removed

Every site is on gaffer-search, so what only helped the move from fuzor-wp is gone:
- The `fuzor` guideline and `fuzor-search` skill: `php gaffer ai:update` removes the generated
  files.
- `doctor` no longer warns about a search plugin's own theme API (`fuzor_*()`, `window.Fuzor`,
  `data-fuzor-*`, `FuzorSearch\`, `gaffer_search_*()`, `window.GafferSearch`), and `app/` methods
  calling `fuzor_*()`/`gaffer_search_*()` no longer count as lookups. Code like that does nothing
  any more (neither plugin has that API): remove it. Find:
  `grep -rnE "fuzor|gaffer_search_|GafferSearch" app inc views assets woocommerce *.php`.
- Old `config/search.php` keys get a plain unknown-key error (with did-you-mean) instead of
  "belongs in seo/urls now"; an index's `card` (now `cards`) shows up in `doctor --wp` as the
  engine's unknown key.
- `console.raw_baseline` (deprecated since 2026-10-31): `doctor` lists each `|raw`/`new Markup`
  without a trust comment, and the key itself is an unknown config key. Find:
  `grep -n raw_baseline config/console.php`. Before: `'raw_baseline' => 19`. After: a
  `{# trusted: why #}` (Twig) or `// trusted: why` (PHP) on or above each use, and the key
  removed. Check: `php gaffer doctor` shows no `raw` warnings.

## 2026-11-03

### Search: `eager` on every page and per layout; cards as their own list item; links keep base params

For sites on `config/search.php`:
- `archive.eager` now applies on every page, not only the first (later pages are landing pages
  too with `seo.index_pages`), and takes a count per layout: `'eager' => ['grid' => 4, 'list' => 2]`.
  Check: the eager image count per page against before.
- `archive({ item: null })` puts the cards in the list as they are, for card templates that are
  their own `<li>`; with `fields` it's the filter form too.
- `Search::archive_url($term, ['show' => 'all', 'filter' => …])` keeps the `urls.base_params`
  you pass (before: dropped, so an overview page's filter links lost `?show=all`).
- A submit of the archive form without JavaScript (its hidden `token` and empty fields in the URL)
  301s to the normalized URL of the same search, without the default page size (`limit` equal to
  `per_page`), so a sale filter lands on the indexable sale page.
- `doctor` no longer calls a `cards` template unused (a list layout no PHP renders).
- With the gaffer-search plugin `0.0.0+f39884a` or newer, the live theme's requests no longer
  empty the search endpoint's index list while a staged copy is checked (searches from the stage
  failed with `index_unknown` before the switch).

### Search: `archive({ fields })` and `pageUrl()`; `eager` on the main image only; checks

From a fuzor-wp site's switch. All but one are additions:
- `archive({ fields: [...] })` is a filter form too (`filters`, `options()`, `activeFilters()`,
  `removeFilter()`, as `createFilterForm()`), and `pageUrl(n)` gives page n of the visitor's
  current search for page links the component renders. Find:
  `grep -rn "createFilterForm\|pageUrl" assets/js`: a hand-made combination of
  `createFilterForm()` and `archive()`, or an own `pageUrl()`, can become
  `archive({ fields, … })`. `pageUrl()` and `viewUrl()` follow every search (also a filter
  changed on page 1: before, their links kept the old filters until the page changed). Check:
  `npm run build`, then change a filter and look at a page link's `href`.
- `archive.eager` now makes only a card's first `loading="lazy"` eager (its main image), not every
  image in it (a hover image stays lazy). Check: the main image comes first in the card template.
- `render:snapshot` refuses to save when most pages redirect to one URL (a login, maintenance or
  geo plugin: `--user=<id>` or turn it off), and `render:diff` says so.
- `doctor --wp` warns about a product taxonomy with used archive pages the archive's index
  doesn't store: each of its pages showed the whole shop.
- The MCP server (`.mcp.json`) starts with WordPress's admin memory limit, like Gaffer's own CLI:
  run `php gaffer ai:update`. With fuzor-wp and gaffer-search both active, `ai:update` writes both
  skills (fuzor-wp's is `fuzor-search` now; they overwrote each other).

### Search: page and archive links, slider filters, eager first row; the form works without JavaScript

New, optional: `search.page_url(n)` (a crawlable link to page n of the archive's search, with its
base params and layout), `Search::archive_url($term, ['filter' => …])` (a link to another
archive, normalized like the canonical; `null` for the shop), `Search::cards(…, filter: […])` (a
slider's "on sale" tab), `archive.eager => N` (the first N cards of page 1 load their images
eagerly). `doctor --wp` and `render:diff` now say when their renders don't see the gaffer-search
plugin the command itself sees (a plugin activated only for that process: activate it for the
site instead, it does nothing without `config/search.php`). With `theme.cache` on, a changed
template recompiles without `twig:clear`. `ai:update` in a staged copy with `config/search.php`
writes the gaffer-search guideline and skill, not fuzor-wp's.

Breaking, small: the archive component has no `view` property any more (always `""` since views
left the responses): use the server's `search.view`. Find: `grep -rn "\bview\b" assets/js views | grep -v viewUrl`.
The archive form's endpoint can now come from `data-gaffer-search-endpoint`, so the form's
`action` stays the page itself and a submit without JavaScript renders the archive. Before:
`<form … action="{{ search.endpoint }}">`; after:
`<form … action="" data-gaffer-search-endpoint="{{ search.endpoint }}">`. Check: the archive
filters with JavaScript off, then `php gaffer doctor --wp`.

### Search: `urls.redirect_params` after WooCommerce's params, and every change it makes is a 301

For sites with `urls.redirect_params`. It now gets the query after WooCommerce's params became
the archive's (`min_price=10` is `filter[price][min]=10` by then; your own old params are still
there as they came), so it can drop or change what they became. And whatever it changes is a 301
to exactly the params it returned: a param it drops (`?yith_wcan=1`) redirects to the URL without
it, where before it rendered as a duplicate page. `doctor --wp` now also says when an index is
built and current.
Find: `grep -n redirect_params config/search.php`, then the method. Check: it returns the query
unchanged when nothing applies (and keeps `utm_*` and other params you want kept); it handles
`filter[price]` instead of `min_price`/`max_price`; old URLs 301 as before
(`php gaffer doctor:render '/shop/?yith_wcan=1'` shows the redirect).

### Search: `cards` replaces `card`, the archive's `seo` and `urls` groups; archive URLs are Gaffer's

Breaking, for sites on `config/search.php`. Update the gaffer-search plugin in the same deploy:
Gaffer no longer asks it for archive URLs (it computes the canonical, `noindex` and the redirects
itself) and the plugin no longer answers those calls.

Find: `grep -nE "'card'|index_pages|indexable_filters|base_params|redirect_params|redirect_search" config/search.php`.
Before (`cards` here is the list layouts of the entry below, from earlier the same day; most sites
have only `card`):

```php
'products' => [..., 'card' => 'product/card.twig', 'cards' => ['list' => 'product/list-item.twig']],
'archive' => [..., 'redirect_search' => true, 'index_pages' => true, 'indexable_filters' => [...], 'base_params' => ['show'], 'redirect_params' => [...]],
```

After (the first of `cards` is the default layout; its field stays `card`):

```php
'products' => [..., 'cards' => ['grid' => 'product/card.twig', 'list' => 'product/list-item.twig']],
'archive' => [..., 'seo' => ['index_pages' => true, 'indexable_filters' => [...]], 'urls' => ['redirect_search' => true, 'base_params' => ['show'], 'redirect_params' => [...]]],
```

`search.views` names the default layout too now (`grid`, not `''`); its new `value` is the
`?view=` param (`''` for the default): link with `viewUrl('{{ v.value }}')`. Check: `php gaffer doctor` (it says where each moved key
belongs), then `doctor --wp`; `render:diff` against a snapshot from before shows no changes to
canonicals, robots or redirects.

### Search: list layouts, archive bounds; `indexable_filters` on the shop only

For sites on `config/search.php`. New, optional: `cards => ['list' => 'product/list-item.twig']`
in an index renders more layouts of the card; the archive at `?view=list` shows that one
(`search.view`, `search.views` for the switch, `viewUrl()` in the archive component; canonical
without `view`, noindex). `archive.bounds => ['price']` gives `search.bounds` (the range over the
whole archive). `indexable_filters` now applies on the shop only: a category or brand page with
that filter is noindex again. `bin/stage.php` also loads the theme's `Theme\` classes, so config
files may use their constants.
Find: `grep -n indexable_filters config/search.php`. Check: `php gaffer doctor --wp`; a
category with `?filter[on_sale]=yes` renders `noindex`, the shop's sale page `index`.

## 2026-11-02

### Search: computed fields, direct terms, archive indexing and page sizes; compact cards

For sites on `config/search.php` (gaffer-search with these contract features; `doctor --wp` says
when the plugin is too old). New, all optional: `document` + `fields` in an index (fields the
theme computes, e.g. a price per m² to filter on), `direct_terms` (a category without its
subcategories' products), and in `archive`: `limits`, `facet_values`, `max_docs`,
`index_pages`, `indexable_filters`, `base_params`, `redirect_params` (the `gaffer-search`
skill; fuzor-wp sites: its "Moving from fuzor-wp" table maps every option and hook).
Cards are now stored without the template's indentation and blank lines (about a tenth of the
index and of each response): every index rebuilds once by itself.
Find: `grep -rn "view" config/search.php views/woocommerce` (a `?view=` the plugin no longer
reads). Check: `php gaffer doctor --wp` shows the index current after the rebuild
(`wp gaffer-search rebuild` does it now); a page with `<pre>`-formatted card text looks the same.

### `php gaffer` from a staged copy loads the copy

Run from a full copy of the active theme next to it (`cp -r theme theme-stage`, same `Theme Name`
in `style.css`), every command loads WordPress with the copy as the active theme (the live theme's
menus and customizer settings) and without the persistent object cache (Redis): before, the live
theme loaded too (`Cannot redeclare class ComposerAutoloader…`) and renders could put the copy's
values in the live site's cache. For WP-CLI from the copy:
`wp --require=vendor/onstage2426/gaffer/bin/stage.php <command>`. `migrate:* --run` is refused
from a copy (run migrations from the active theme, after the deploy).
Nothing to change. Check: in the copy, `wp --require=vendor/onstage2426/gaffer/bin/stage.php eval
'echo get_stylesheet();'` prints the copy's directory name.

### `doctor`: fuzor-wp's API, `ai:update` after `composer update`

`doctor` lists fuzor-wp's theme API (`fuzor_*()` and its hooks, `window.Fuzor`, `data-fuzor-*`,
`FuzorSearch\`) like gaffer-search's old one: a site on fuzor-wp gets these warnings until it
moves to `config/search.php` (baseline them until then: `php gaffer doctor --baseline`). It also
warns when a theme with `config/ai.php` doesn't run `ai:update` from Composer.
Find: `grep -n post-update-cmd composer.json`. After: `"scripts": { "post-update-cmd": ["@php
gaffer ai:update --ansi"] }`. Check: `php gaffer doctor` has no `config` warning.

### New: `Blocks::markup()` for content written by code

`Gaffer\Blocks::markup('acf/content-faq', ['titel' => …, 'vragen' => [['vraag' => …]]])` gives
the block comment WordPress stores, with every derived field key, checked against the block's
`fields.php` (an unknown field or a value the field can't take throws). Nothing to change; replace
hand-written block comments in seeds and scripts.
Find: `grep -rn "wp:acf/" app inc *.php` (outside templates). Write with
`wp_update_post(['ID' => $id, 'post_content' => wp_slash($content)])`: WordPress unslashes it.

### Search: card methods rebuild, `search.available`, the `@gaffer` alias

For sites on `config/search.php` (gaffer-search). A change to the file of a `card_context` or
`card_variants` method now rebuilds the index like a template change. `doctor` warns when a
theme uses `Search::archive()` but no template reads `search.available` (without the plugin the
shop would show no products and nothing says why). The unavailable archive's `results` is the
normal empty response now (a template reading `search.results.offset` no longer fails).

Find: `grep -rn "vendor/onstage2426/gaffer/resources/js" assets`.
Before: `import { archive } from "../../../vendor/onstage2426/gaffer/resources/js/search.js";`
After: in `vite.config.js` `resolve: { alias: { "@gaffer": path.resolve("vendor/onstage2426/gaffer/resources/js") } }`,
then `import { archive } from "@gaffer/search.js";`. Check: `npm run build`; `php gaffer doctor`
has no `search` warning (add `{% if not search.available %}…{% endif %}` to the archive template
and leave the searchbar out when it's unavailable).

### New: `Gaffer\Search` for the gaffer-search plugin

Search, the shop archive and product sliders are now configured in
`config/search.php` and come to templates from `Gaffer\Search\Search`
(`archive()`, `cards()`, `searchbar()`); the browser side is Gaffer's
`resources/js/search.js`, imported into the theme's components. Gaffer renders
the cards into the index, rebuilds it when the card template (or anything it
includes) changes, and adds the archive's redirects, canonical URL and robots.
The plugin's own theme API (`gaffer_search_*()`, its hooks,
`window.GafferSearch`) is gone from the plugin (its `docs/migration.md`), and so
is fuzor-wp's under its new name: the theme moves to `config/search.php` in the
same deploy that switches the plugin.

For sites on gaffer-search or on fuzor-wp (`fuzor_*()`, `window.Fuzor`): a
site staying on fuzor-wp has nothing to do until it switches.
Find: `grep -rnE "gaffer_search_|GafferSearch|fuzor_|Fuzor|data-fuzor-" app inc views assets woocommerce *.php`
(`php gaffer doctor` lists them too).

Before (`inc/…` and `Theme\Search`):

```php
add_action('gaffer_search_register_indexes', fn() => gaffer_search_register_index(new IndexDefinition(...), new ProductIndexBuilder(...)));
add_filter('gaffer_search_index_document', Search::with_cards(...), 10, 2);
$archive = gaffer_search_archive('products', [...]);
```

After (`config/search.php`, then `Search::archive()` in the template file):

```php
'indexes' => ['products' => ['type' => 'products', 'searchable' => [...], 'card' => 'product/card.twig']],
'archive' => ['index' => 'products', 'per_page' => 24, 'sorts' => [...]],
```

The `gaffer-search` skill has the whole mapping (card variants, the searchbar,
the JavaScript). Check: `php gaffer doctor --wp` shows no `search` errors or
old-API warnings; `render:diff` against a snapshot from before shows the same
products; `render:screenshot` of the searchbar and an archive sort.

## 2026-11-01

### New: `ajax:call` / `ajax:end`, and `--session` for screenshots

`php gaffer ajax:call CartAdd product=65 quantity=2 --session=t` calls an ajax
action over HTTP like htmx does and prints status, `HX-*`/`X-Gaffer-*` headers
and HTML; `--session` keeps the visitor's cookies, so a sequence (add, drawer,
update, remove) runs as one visitor. `php gaffer ajax:end t` deletes its
WooCommerce session and cart. `render:screenshot --session=t` (and the
`gaffer-screenshot` tool's `session`) shows that visitor's cart.

Nothing to change in a theme. Replace any throwaway scripts or notes that test
actions with their own cookie handling: `grep -rniE "set-cookie|curl|file_get_contents\(.*ajax" .ai`.
Check: `php gaffer ajax:call <a GET action>` prints status 200.

### New: `render:screenshot` and the `gaffer-screenshot` MCP tool (optional)

Screenshots of a rendered page at several widths (default 390 and 1440 px),
with its console errors and failed requests, so an agent can check how a
change looks. `--click`/`--fill` run first (in order) to open a menu or drawer;
`--full` takes the whole page. Nothing to change in a theme; to turn it on:

```
npm install --save-dev playwright
npx playwright install chromium
```

Where Chromium can't run (missing system libraries), point
`PLAYWRIGHT_WS_ENDPOINT` in `.env` at a Playwright server of the same version,
e.g. `docker run --rm -p 127.0.0.1:3000:3000 --init mcr.microsoft.com/playwright:v<version>-noble npx -y playwright@<version> run-server --port 3000 --host 0.0.0.0`
and `PLAYWRIGHT_WS_ENDPOINT=ws://127.0.0.1:3000/`. Add the key (commented) to
`.env.example`. `storage/screenshots/` is created with a `.gitignore`.

Check: `php gaffer render:screenshot /` prints two PNG paths; `php gaffer
doctor` no longer shows the "render:screenshot is off" info line.

## 2026-10-31

### `|raw` needs a trust comment; `console.raw_baseline` is deprecated

Every `|raw` in a template and `new Markup(...)` in theme PHP says why its HTML
is trusted, on its line or alone on the line above. `doctor` warns about each
one without a comment. While `console.raw_baseline` is set, `doctor` still
counts the unmarked uses against it, with a deprecation warning.

Find: `grep -rn '|\s*raw' views blocks woocommerce` and
`grep -rnE 'new\s+\\?(Twig\\)?Markup\s*\(' app inc blocks`.

```twig
{# before #}
{{ post.content()|raw }}
{# after #}
{{ post.content()|raw }}{# trusted: WordPress content #}
```

```php
// trusted: a card rendered by Theme\Search
$card = new Markup($html, 'UTF-8');
```

Name where the HTML comes from (WordPress content, a Gravity Forms
confirmation, WooCommerce notices); a value from a visitor or an unescaped
field is no reason, print it escaped. Then remove `raw_baseline` from
`config/console.php`. Check: `php gaffer doctor` has no `raw` warnings.

### `doctor`: `meta()` in templates, nested forms

- `term.meta('tint')` (any `.meta(` call) in a template is a warning: add a
  method to the type that reads the field (`tint()`) and call that. Find:
  `grep -rn '\.meta(' views blocks`.
- An include inside a `<form>` whose template (or one it includes) has a
  `<form>` itself is a warning: browsers drop the inner form. Move the include
  out of the form.

### `doctor --wp` stops when `SITE_URL` isn't this install's

When `SITE_URL`'s host (or `--url`'s) isn't the `home_url()` of the WordPress
install the theme is in (a `.env` copied with the theme), or `SITE_URL` isn't
set, `doctor --wp` reports an error and skips the WordPress checks and
renders; `init` refuses such a URL. Fix: `php gaffer init --url=<the site's
URL>`. Check: `php gaffer doctor --wp` runs its WordPress checks.

### CLI: memory limit and fatal errors

Commands that load WordPress raise `memory_limit` to `WP_MAX_MEMORY_LIMIT`
(256M unless `wp-config.php` sets it), as wp-admin and WP-CLI do, also in the
render processes, and print a fatal error instead of exiting with code 255 and
no output. Nothing to change; a `-d memory_limit=…` you pass is kept.

### `migrate:remove-field` on `fields/` targets: recursive, and without the file

Removing a repeater or group also removes its sub fields' rows (as it already
did for blocks), and the command works after the target's file is deleted.
`doctor --wp` warns about stored values of a `fields/` target whose file is
gone, with its fields. Nothing to change; if `doctor --wp` shows such values,
remove them per top-level field (dry run first).

### Forms: `X-Gaffer-Spam` outside production

A submission caught as spam (honeypot, Turnstile) still gets the normal
confirmation; outside production the answer now has an `X-Gaffer-Spam:
honeypot|turnstile` header, so a test can tell. Nothing to change.

### `doctor --wp`: no WP-CLI here is a warning

When `wp` isn't on the PATH of the process running `doctor --wp` (a container
without WP-CLI), the MCP server check is a warning instead of an error.

## 2026-10-20

### `render:diff`: reorders and long lines

Changes that only reorder lines are marked "(reorder only)" and
`--hide-reorders` leaves them out (`--json`: `reorder_only` per change); a
changed long line shows only the part that differs. The `gaffer-migrate` skill
lists typical `console.render_ignore` candidates. Nothing to change in sites;
consider moving recurring `--ignore` patterns into `console.render_ignore`.

### Field groups: conditional logic by name, titles from WordPress, more checks

- Conditional logic in `fields.php` and `fields/` can name the field:
  `'field' => 'is_hoofdcategorie'` (a sub field `'vragen.vraag'`, or a
  sibling's name inside a repeater); Gaffer resolves it to the derived key. A
  name or written-out key the file doesn't have is now an error. Find:
  `grep -rn "'field' => 'field_" fields blocks` (written-out keys still work;
  names survive renames of the file).
- The meta box of a `fields/` group is titled with the target's own name
  ("Product", "Productcategorie", the options page's title) instead of
  "Post type: product". Editors see the new title.
- `doctor`: field definitions (`fields/`, `blocks/*/fields.php`) no longer
  count as HTML in PHP or a hardcoded uploads path (a wysiwyg default);
  UI groups with conditional logic on a field that doesn't exist (a
  switched-off field) get a warning; groups `fields/` can't express are
  listed once as info.
- `doctor --wp`: ACF UI field groups, post types, taxonomies and options pages
  that are only in the database (no JSON in `storage/acf-json/`) get a
  warning. Open each in ACF and save it once (or, for a group whose target is
  already code, `migrate:fields` and delete it).

### Removing fields of `fields/` targets; deleting the moved UI groups

New for `fields/` targets (with the same dry run, backup, log and rollback as
for blocks; rollback re-inserts deleted rows with their own IDs):
- `migrate:remove-field post_type/product excluton_3d_sku`: every value and
  reference stored under that name (revisions included), once the field is
  gone from the file and the target's UI groups.
- `migrate:remove-leftovers post_type/product`: references under another name
  than their field's (old names, a bare `_`) with their values.
- `migrate:fields {target} --run --delete-groups`: deletes the moved UI groups
  once everything is written and checked (refused while old-name references
  remain: run `remove-leftovers` first).
- `migrate:fields` now repairs references under a field's name whose key no
  field has (an import) instead of only listing them.
- `doctor --wp` warns about `acf-field` posts whose group no longer exists.

Find: sites that deleted meta by hand (`wp eval` with `delete_post_meta()`):
revisions' meta may have gone to the parent post; nothing to do now, but use
the commands from here on.

### `migrate:fields` for `fields/` targets: only the target's own references

`migrate:fields` rewrote every reference holding an old key, wherever it was
stored. It now rewrites only the target's own (that post type and its
revisions, that taxonomy, that options page's `post_id` per language, stored
under the field's own name) and reports the rest without touching it:
references outside the target, under another name (an old field name), and
broken keys in the target. `--run` also compares what ACF reads for every
changed field before and after, and fails as `verify_failed` (with the
rollback command) on any difference. The dry run prints one line per field
(`-v`: every location) and shows progress; it's fast on large sites (the
quadratic check is gone: 60,000 references plan in 2 seconds).

Find: only sites that still have UI field groups to move (`php gaffer doctor`
lists them). A site that already ran `migrate:fields`: run it again as a dry
run per target and read the notes (references it now reports were rewritten
by the old version; they're harmless, but are stale data).

Check: the notes of the dry run before `--run`.

### `Post::query()` filters by language (query filters on)

`Post::query()` passes `suppress_filters => false` (get_posts() turns query
filters off): WPML/Polylang now return the current language only, and other
plugins' `pre_get_posts`/`posts_*` filters apply to these queries too.
`children()` uses it, so it's language-filtered too.

Find: `grep -rn "Post::query\|::query(\[" --include=*.php app inc blocks *.php woocommerce`,
`grep -rn "suppress_filters" app inc blocks` (`=> false` is now the default:
remove it; `=> true` keeps the old behavior), and `pre_get_posts` hooks without
an `is_main_query()` check (they now also change these queries).

Check: `render:snapshot` before updating, `render:diff` after. On a WPML test
multisite the Dutch pages stopped showing the English/German product translations.

### Renders run templates in the global scope; more template globals

`doctor --wp`, `render:*` and the MCP render tool now load WordPress and the
template like a web request (global scope). A template that assigns a WordPress
global (`$page`, `$pages`, `$more`, `$id`, `$authordata`, ...) and reads it after
`get_header()` or a loop now fails in the render like it does live. `doctor`
reads the globals from WordPress's source and warns about more of them.

Find: `php gaffer doctor` (`[globals]` warnings), `php gaffer doctor --wp`.
Fix: rename the variable (`$current`, `$item`).

### doctor: `|t` on anything but a literal string

New warning: `(a ? 'x' : 'y')|t`, `label|t`. Write `a ? 'x'|t : 'y'|t`;
content (ACF values, names) is translated as content, not with `|t`.

### `Image::path(size)`

New: the file on disk `src(size)` serves. Replace URL→path rewriting
(`grep -rn "basedir\|baseurl" app inc`).
  On WPML sites, renders before this showed `lang="en-US"` (the in-function load
  broke WPML's language detection); now they show the site's language, like a
  web request: expect `<html lang>` (and other language-dependent output) in
  the first render:diff after updating.

## 2026-10-19

### Field groups in code: `fields/{options_page|post_type|taxonomy}/{name}.php`

Field groups of options pages, post types and taxonomies are code now, like
block fields. `doctor` warns about every field group made in the ACF UI that
could be code, and errors when a UI group targets something that has a file.

Find: `ls storage/acf-json/group_*.json` (`php gaffer doctor` lists them).
Many warnings at once on a site with many UI groups: baseline them
(`php gaffer doctor --baseline`), then move one target at a time with the
`gaffer-fields` skill and `--prune` as you go.

Per target (one file, sections as tabs, same field names and types):
1. Database dump first.
2. `php gaffer render:snapshot before-fields`.
3. Write `fields/post_type/review.php` etc.
4. `php gaffer migrate:fields` (dry run), then `--run`: rewrites the `_name`
   reference rows to the derived keys.
5. Delete the old groups in ACF (ACF → Field Groups, or
   `acf_delete_field_group($post_id)`; removes the JSON too).
6. `php gaffer render:diff before-fields`, `php gaffer doctor --wp`.

Deploy order on a live site: code (with fields/) first, then migrate:fields
--run, then delete the groups. Between migrating and deleting, the edit screen
shows both groups: don't let editors save then.

### Ajax actions moved: `ajax/` → `app/Ajax/`, templates → `views/`

Actions are `app/Ajax/{Name}/{Name}.php` (same namespace `Theme\Ajax\{Name}`),
loaded by Composer (`Theme\` → `app/`); Gaffer's own ajax autoloader is gone.
The `@ajax` Twig namespace is gone: action templates live in `views/` by
concept. `doctor` errors while `ajax/` exists.

Find: `ls ajax/`, `grep -rn "@ajax/" --include=*.php --include=*.twig .`,
`grep -n "ajax/" vite.config.js .ai -r`.

1. `git mv ajax/{Name} app/Ajax/{Name}` for each action.
2. Move its `.twig` files to `views/{concept}/` (the starter:
   `@ajax/CartDrawer/drawer.twig` → `cart/drawer.twig`,
   `@ajax/CartDrawer/product.twig` → `cart/drawer-product.twig`) and update
   `View::render()`/`include()` names.
3. `vite.config.js`: drop the `./ajax/**` reload globs (`./app/**/*.php` and
   `./views/**/*.twig` cover them).
4. `composer dump-autoload`, then `.ai/` mentions of `ajax/` (`doctor` lists
   stale ones).

Check: every action through the web server (GET/POST), `doctor --wp`.
URLs (`ajax_url('X')`, `ajax.php?action=X`) don't change.

## 2026-10-18

### AGENTS.md split: reference and procedures are skills

`php gaffer ai:update` now writes the generated reference as the skill
`gaffer-reference` (AGENTS.md only points to it), and the migrate/render-compare
details (`gaffer-migrate`) and Fuzor's full guide (`gaffer-search`) as skills.
Gaffer's part of AGENTS.md went from ~57 KB to ~34 KB.

Find: `grep -rn "reference below\|Reference (generated" .ai/` (theme guidelines
pointing at the old section).

After: `php gaffer ai:update`; `.claude/skills/` (etc.) has `gaffer-reference`.
Check: theme `.ai/guidelines/` are the rest of AGENTS.md: keep them to rules and
decisions too (procedures → `.ai/skills/`).

## 2026-10-17

### `Term::children()`: direct children only

`Term::children()` returned every descendant (`get_term_children()`), in no set
order. It now returns the direct children, empty ones included, in
`get_terms()` order (by name; WooCommerce's category order for `product_cat`).

Find: `grep -rn "children()" --include=*.php --include=*.twig app inc views blocks woocommerce *.php`
(only calls on a `Term` matter; `Post` and `MenuItem` are unchanged).

Before: a subcategory list showed grandchildren too ("Kleding" → Shirts, Broeken, Chinos).
After: Shirts, Broeken. Code that wanted all levels: `get_term_children()` yourself
and map through `Term::from()`. Code that worked around it with
`Term::query(['parent' => …])`: can use `children()` again.

Check: category archives that list subcategories.

### `|t` filter

Gaffer has a `t` Twig filter: `__($text, <style.css Text Domain>)`. It throws
when `style.css` has no `Text Domain` header.

Find: `grep -rn 'AsTwigFilter("t")\|AsTwigFilter(.t.)' app` (a theme's own `t`
filter overrides Gaffer's silently: remove it), and
`grep -n "Text Domain" style.css`.

Check: `doctor --wp` renders; translated strings still translated (WPML String
Translation keeps them by domain + text, so the same domain changes nothing).

### doctor: `>` inside attributes in block markup

New error from `doctor`: a `>` inside an attribute value in a block template
or anything it includes (wptexturize breaks the tag there). Fix: `&gt;`, or
move it out of the markup (Tailwind: `[&>svg]:x` → `*:x` or `[&_svg]:x`).
