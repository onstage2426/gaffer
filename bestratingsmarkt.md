New version (plugin 2ea4fa9, prompt updated). Reading what changed:

The fix is in place: a lock that can't be applied now finds nothing. Next, the drift check and the price fields:

No drift: every case is the same and no product changed since 20:14. Checking the stage is still in step with live (apart from the intended changes):

Stage matches live except the intended changes. Step 4: installing plugin 2ea4fa9 on live, then checking right away.

Installed at 20:47:33 UTC; every case is the same. Moving the old copy out of `plugins/`, then checking a live category page over HTTP:

Live is correct (category total 1,170). The plugin has scheduled its own background rebuild, as the prompt says. Step 5: the build from the stage.

All 10 cases are the same. Every one of the 7,712 documents differs somewhere, so I'm grouping the changes by field:

Prices, stock, on_sale, `prijs_m2`/`prijs_stuk` and `eenheid` are unchanged on every document. Checking that the 10 new `regular_price` values are variable products:

All explained: the 10 are variable products with an empty `_regular_price` on the parent, and no product has reviews (so no `rating`). Next, the render diff and `doctor --wp` on the stage:

The product page diff looks like a data edit, not something from this change. Checking the product:

The product still has those attributes, so I'll check whether the live theme now renders it differently from the snapshot too (in which case it isn't the stage):

The live theme renders the spec rows; the stage doesn't. I need to find out why before switching:

Nothing search-related there. I'll render that page from the stage again to see if the gap is reproducible:

Reproducible: the stage drops 6 of the 18 spec rows. Comparing the attributes the product returns under each theme:

Same 18 attributes either way in the CLI, so the difference only shows in `render --theme`. Diffing the two fresh renders of that page in full:

An unchanged copy of live drops the same 6 rows, so this comes from rendering through a theme copy, not from this change. Removing the test copy, then `doctor --wp` on the stage:

Stage `doctor --wp`: 0 errors, the index is built and current, 52/52 URLs render. Step 6: switching the live theme (`vendor/`, `composer.lock`, `config/search.php`) in one go.

Switched at 20:57:37 UTC. Now the live checks:

Several rebuilds ran one after another (the plugin's background one at 20:53, another at 20:57, and the one `doctor` started at 20:58). I'll wait for the current one to finish:

Waiting for the build to finish. The other 5 `doctor` warnings (OptiMonk's HTML in PHP, the ACF items only in the database) are older than this change and don't involve search.

The temp build's WAL file hasn't grown since 20:58:51. Checking whether that build is still running:

Only a minute has passed (20:59 UTC); the build runs in a WP-cron web request, which `ps` doesn't show by name. I'll wait for the notification.

I'm setting a watch that also fires if the build stalls, so a dead build doesn't look like one still running:

The watch is running; I'll continue when it reports.

Another build started at 21:00:02, right after the last one finished. Reading how Gaffer decides an index is stale:

Meanwhile, reading (without running WP) where the plugin schedules a rebuild on a version change, and the transient check:

Each side's version looks deterministic from the code. Another explanation: PHP-FPM's OPcache may still be running the old Gaffer files in web requests. Checking the OPcache settings:

OPcache revalidates on every request, so the web side runs the new code. Dumping what goes into the version in the CLI to find a context-dependent part:

The CLI's parts look deterministic. Next I'll look at what the plugin itself stored as the current definition, to see the web side's version:

The build records the version of the request that ran it. If WP-cron's web requests see the theme through another path (a symlink), the card file list could hash differently. Checking:

The stored version `004279bc…` might be the stage's rather than the web's. Computing the stage's version:

The stage and the live CLI agree (`1d21ead3…`), so `004279bc…` is what web requests compute. I'll let the watch finish to see whether builds also start with no CLI run in between.

WP Rocket answered the web-like load from its cache. Again with a POST, which Rocket doesn't serve from cache:

The web runs the new Gaffer code, so it's not the code being stale. Maybe Gaffer caches the config itself. Checking:

`Indexes::all()` also gives `1d21ead3…`. So the plugin's registry must hold on to an older definition. Checking whether it persists definitions:

Everything is stable. Updating the restore note and memory:

The update is live: since 20:57 UTC the search index reads products' prices and stock from WooCommerce itself. Every archive check is the same as before, and `doctor --wp` shows 0 errors. One issue came up after the switch: a stale cache value made the index rebuild over and over. That's fixed now. Report in the prompt's shape:

**1. Before (bestratingsmarkt.com, 7,712 documents)**

| Case | Total | First IDs |
|---|---|---|
| shop | 6,387 | 2540, 116376, 33297 |
| category (term 2266, keramische-tegels) | 1,170 | 116383, 116382, 116384 |
| price ascending | 6,387 | 5617, 5621, 662 |
| price descending | 6,387 | 19639, 20186, 20187 |
| category, price ascending | 1,170 | 30594, 30616, 30620 |
| price range 420.74–1261.62 | 158 | 109299, 109432 |
| in stock | 4,383 | 116376, 116379 |
| on sale | 990 | 116376, 116379 |
| search "in" / sorted by price | 6,287 | 12967, 33369 |

- The search case shows the known empty-pages issue: a total of 6,287, but only 462 (484 sorted by price) IDs come back.
- Deurmarkt and Sierbestratingsmarkt have no index, so there was nothing to compare there.
- Nothing changed between 20:15 and the second try: the cases matched again and no product was modified.

**2. Steps (UTC)**
1. 20:14–20:15: backups of the database, the plugin, the theme's `vendor/` and config, and the index files.
2. 20:19:52: first try with plugin 3ab31fd. It dropped the category limit on archives, so I rolled it back at 20:20:40 and deleted the WP Rocket cache files from that window.
3. 20:47:33: plugin 2ea4fa9 installed. Straight after, all 10 cases were the same on live.
4. 20:47:51–20:49:57: rebuild from the stage (123 s).
5. 20:57:37: live theme switched (Gaffer 2b1a09c, the four `meta` lines removed), then `ai:update`, `twig:clear` and `twig:lint` (0 errors). Committed as `0a5e9f2`.
6. 20:58 and 21:07: WP Rocket purged for bestratingsmarkt.com and Sierbestratingsmarkt (Deurmarkt has no WP Rocket).
7. 21:04–21:07: the stale-value problem, fixed (see point 5).

**3. Differences, explained**
- **Archives:** all 10 cases are `same`, in the stage build and on live after the switch. Price sorts and the price range match exactly. Even "in stock" and "on sale" returned the same totals.
- **Unchanged on every document:** `price`, `stock_status`, `on_sale`, `prijs_m2`, `prijs_stuk`, `eenheid`.
- **`sku`:** added to all 7,712 documents. It wasn't stored before, because the theme had no `_sku` line.
- **`regular_price`:** added for 10 variable products (10658, 13105, 75734, 95948, …). The parent product has an empty `_regular_price`; the value now comes from the cheapest variation.
- **`sale_price`:** added for 1 product (10658, 44.50).
- **`rating`:** none, because no product has reviews.
- **Render diff:**
  - The only real change: `?orderby=rating` now redirects to `sort[]=rating:desc`. That's expected from Gaffer.
  - `?orderby=price` and `?min_price=` redirect as before; the theme still drops the old price filter on purpose.
  - The rest is known noise: Gravity Forms tokens, random field IDs, `ms` timings, a choice order.
  - One apparent difference wasn't real: on In-Lite product 117713, six spec rows disappeared when rendering through the stage. An unchanged copy of live drops the same rows, so it comes from rendering through a theme copy (`render:diff --theme`), not from this change. Live renders them correctly.

**4. doctor**
- Stage: `0 errors, 5 warnings, 3 info` ("built and current, 7712 documents").
- Live, final run: `0 errors, 5 warnings, 4 info` ("built and current, 7712 documents").
- The 5 warnings were there before this change: OptiMonk's HTML in PHP, and ACF items that are only in the database.

**5. Unexpected**
- **Rebuild loop after the switch.** `doctor` kept saying the index was "built from an older definition", and each `doctor` run started another two-minute rebuild.
  - Cause: the database held the right version (`1d21ead3…`), but Redis's cached copy of WordPress's autoloaded options held the old one (`004279bc…`, which is what the old config gives). This is a lost update between processes.
  - Fix: `wp cache delete alloptions options`. After that the index is "built and current" and no more rebuilds started.
  - Worth passing on to the maintainer: since the plugin keeps its built version in an autoloaded option, this can happen on any site with Redis.
- **`prijs_m2` and `prijs_stuk`:** checked on all 7,712 documents (I added them to `docs.php`'s field list); every value is the same as before.
- **`_price` elsewhere:** no `meta` line under another name, and the theme doesn't read `_price` anywhere itself.

Rollback steps and all backups are in `~/theme-backups/gaffer-search-standard-fields-2026-11-04/RESTORE.md`.
