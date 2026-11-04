The helper scripts only read data, and `config/search.php` has no `meta` lines. Next: back up, then check the theme for other places that read prices.

The theme never reads prices, stock, SKU or rating. Backups are in `~/backups/gaffer-search-20261104-2211`. Now the before-lists:

Before the change the index stores no price, stock, SKU or rating at all. The price range fell back to 20–60 because there was nothing to measure. Next, the render snapshot:

Snapshot saved (38 pages). Before installing the plugin, I'll check whether the current plugin folder has files the zip lacks:

Plugin is at `2ea4fa9`. Next, the theme: update Gaffer with composer.phar, following my notes on this server.

Gaffer is now at `f9f1087` (2026-11-04 20:31). Next, check that it includes the change and read the changelog entries since `ee7a2bc` (18:40):

Both 2026-11-04 entries apply. Checking the second one's leftovers too:

Nothing left over, and there are no `meta` lines to remove. Rebuilding the index and running the after-lists:

Every product changed. Grouping the field changes by reason, and checking the prices WooCommerce itself has:

All 562 products are simple, and none has a price, sale price or rating in WooCommerce. All have a SKU and are in stock. Checking that the price sorts now just follow the shop's default order:

The first 150 lines were all card order on the price-sort pages. Now the full diff's page groups:

The migration is done. The plugin is at `0.0.0+2ea4fa9` and Gaffer at `f9f1087`, the index is rebuilt, and `doctor --wp` shows 0 errors. Nothing has changed for visitors: no product in this catalog has a price or rating. I haven't committed (see point 5).

## 1. Before
The site has 562 products, and the index had 562 documents. None of them had a price, stock, SKU or rating field, because `config/search.php` had no `meta` lines.

| Case | Total | First IDs |
|---|---|---|
| shop | 562 | 730, 733, 736, 740, 743, 746 |
| category (term 58, `trouwjurken`) | 440 | 730, 733, 736, 749, 754, 758 |
| price_asc / price_desc | 562 | 3324, 3317, 3311, 3306… (warning: sort field `price` not declared, ignored) |
| category_price_asc | 440 | 3324, 3317, 3311… (same warning) |
| price_range (20–60) | 0 | — |
| in_stock / on_sale | 0 / 0 | — |
| search `demetrios` / search_price_desc | 11 / 11 | 2376, 2497, 2502, 730, 3264, 3255 |

The price range of 20–60 is a placeholder: the script falls back to it when no prices exist.

## 2. Steps
1. **Backups** are in `~/backups/gaffer-search-20261104-2211/`, outside the web root:
   - `db.sql`
   - `plugin-gaffer-search.tgz` (the old plugin, `0.0.0+7966591`)
   - `vendor-gaffer.tgz`, `composer.lock` and `search.php`
   - the before/after JSON files

   To restore:
   - **Plugin:** `rm -rf wp-content/plugins/gaffer-search && tar xzf plugin-gaffer-search.tgz -C wp-content/plugins`
   - **Theme:** copy `composer.lock` back and `tar xzf vendor-gaffer.tgz` in the theme folder, then `wp gaffer-search rebuild`.
   - **Database:** `wp db import db.sql --socket=/var/lib/mysql/mysql.sock`
2. **Before-lists and snapshot:** ran `archives.php` and `docs.php`, then `render:snapshot before-c --user=1` with the 11 paths from `paths.php`, which saved 38 pages.
3. **Plugin:** deactivated it, replaced the folder with the zip's contents and activated it. I replaced the folder rather than unzipping over it because the new version drops `src/Functions.php`, `src/Audience.php` and a composer autoload file, which would have been left behind.
4. **Theme:** ran `composer update onstage2426/gaffer` with a checksum-verified `composer.phar`, which moved Gaffer from `ee7a2bc` to `f9f1087`. `ai:update` ran automatically afterwards. I read both 2026-11-04 changelog entries; neither needed a change here (no `meta` lines, no fuzor or `raw_baseline` leftovers).
5. **Rebuild and compare:** ran `wp gaffer-search rebuild` (562 documents), the after-lists, both compare scripts and `render:diff`.
6. **Checks:** ran `twig:lint` and `doctor --wp`.

## 3. Differences, explained
**What WooCommerce actually holds:** all 562 products are simple products. None has a price (`_price` is empty everywhere) or a rating. All have a SKU and all are in stock.

**Archive cases:**
- **Same:** shop, category, price_range (0), on_sale (0), search, and search_price_desc (only its warning is gone).
- **in_stock:** went from 0 to 562. It's now filterable, and every product is in stock.
- **price_asc, price_desc and category_price_asc:** same products in a different order.
  - Before, the undeclared sort was ignored and the order came out as the shop order reversed.
  - Now `price` exists but is missing on every product. All products tie, so the order falls back to the default: exactly the shop and category order.
  - No product's price explains a difference, because there are no prices.

**Document fields:** 562 out of 562 changed, all for the same reason. Each gained `stock_status: instock`, `on_sale: no` and its `sku` (e.g. 730, 733, 736, 740, 743). The other reasons in the brief don't occur:
- no `price`, `regular_price` or `sale_price` on any product (no variable products, no stale `_price`)
- no empty SKUs
- no ratings

**Render diff:** 29 pages the same and 9 different. The changes that aren't noise:
- **Price-sort pages** (`/winkel/` both directions, `/collectie/trouwjurken/` ascending): different card order, explained above.
- **`?filter[stock_status]=instock`:** shows 562 products instead of an empty list.
- **`?orderby=price`:** now redirects (301) to `?sort[]=price:asc` instead of to `/winkel/`.
- **`?min_price=20`:** now redirects (301) to `?filter[price][min]=20` instead of to `/winkel/`. That page will be empty while products have no prices.

The noise:
- **`/contact/`:** Gravity Forms state tokens.
- **`/real-brides/…`:** the slider order is random.
- **Product page related slider:** it's a `WP_Query` sorted by `total_sales`, which is 0 for every product, so the tie order shifts between renders. It doesn't use the index.

## 4. doctor
`0 errors, 0 warnings, 4 info`, with 1 known warning in the baseline. The search check reports the index as "built and current, 562 documents". `twig:lint` gives `0 errors, 0 warnings, 0 info`.

## 5. Unclear or unexpected
- No `meta` lines under other names, and nothing in the theme reads `_price` or the other keys itself. The empty-pages issue didn't show up (the search returned 11 hits).
- With no prices in the catalog, the price sorts and the price filter do nothing on this site. That's expected for a catalog-only shop, but don't offer them in the UI.
- **Not committed:** the working tree already had uncommitted changes before I started (the Gaffer `ee7a2bc` update, the `pa_lengte` taxonomy, `archive.js`, `archive.twig` and the search guideline). My only theme change is `composer.lock`, and committing it would bundle that earlier work into the same commit. Do you want one commit, or should I split it?
- `gaffer-search-standard-fields/` is untracked in the theme folder, and the zip in it carries the updater token. Leave it out of git; it can be deleted now.
