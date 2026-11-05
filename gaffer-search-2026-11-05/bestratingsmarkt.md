# gaffer-search 0.0.0+f5884e7: the rebuild loop and the empty last pages (Bestratingsmarkt)

Live WooCommerce multisite with WP Rocket and Redis. A plugin-only update: the theme doesn't
change. Do it at a quiet moment.

Thank you for the report of 2026-11-04. Two of its findings are fixed in this plugin:

- **The rebuild loop.** You were right: the plugin kept what each index was built from in two
  autoloaded options (one array for all indexes). With Redis, a two-minute build writes back the
  whole `alloptions` it loaded at its start, so a newer value from another process was lost and
  the index looked out of date after every build. It's now one option per index, not autoloaded;
  the old options are moved on the first request after the update.
- **The empty last pages.** A search with words matching more than `max_docs` products now returns
  hits on every page up to `total`, sorted when a sort is asked (the Fuzor library inside the
  plugin was fixed). Your search case "in" (6,287 total, 462 IDs) should now return as many IDs as
  `archives.php` pages through (it stops at 2,000).

Nothing else changes: same fields, same archives.

## Files

`gaffer-search.zip` (version `0.0.0+f5884e7`) and `archives.php`, `compare.py` are next to this
prompt. **Ask the user where they are on your server**; don't download them from anywhere. Your
before-lists are in `~/theme-backups/gaffer-search-standard-fields-2026-11-04/`; take fresh ones
first if products changed since (step 2).

## Steps

1. **Back up** the current plugin folder (`wp-content/plugins/gaffer-search`, `0.0.0+2ea4fa9`) and
   note `wp option get gaffer_search_built_signatures --format=json` and
   `wp option get gaffer_search_built_versions --format=json` per site.
2. **Before:** per site with an index, `wp eval-file archives.php before-f5884e7-<site>.json <your cases file> --url=$u`.
3. **Install** the zip over the plugin folder (network deactivate, replace, network activate; or
   Network Admin → Plugins → Upload → "Replace current"). Replace the folder rather than unzip over
   it, as thenewbride did, so no removed file stays behind.
4. **Check, per site:**
   - `wp option get gaffer_search_built_producten --url=$u --format=json` has `signature` and
     `version`, and the two old options are gone (`wp option get gaffer_search_built_signatures` errors);
   - `wp db query "SELECT option_name, autoload FROM <prefix>options WHERE option_name LIKE 'gaffer\_search\_built\_%'"`:
     `autoload` is `off` (or `no`);
   - `php gaffer doctor --wp --url=$u`: "built and current", and **no rebuild starts**: run
     `doctor --wp` twice more over ten minutes, and check the build log or the index file's mtime
     doesn't move (a content change may run an update, not a full rebuild);
   - `wp eval-file archives.php after-f5884e7-<site>.json <your cases file> --url=$u`, then
     `python3 compare.py before-f5884e7-<site>.json after-f5884e7-<site>.json`: every case `same`
     except the search cases, which should now list more IDs (same total). Check that the first
     462 IDs of the search case are the same as before (the ranked part), and that no ID repeats.
   - in the browser, logged out: the last page of a broad search (`?q=in`, the last page link)
     shows products.
5. Clear WP Rocket's page cache per site. Never `wp cache flush` on this Redis.

## One more question (no change needed)

On 2026-11-04 `render:diff --theme=bestratingsmarkt-stage` showed In-Lite product 117713 without 6
of its 18 spec rows, also with an unchanged copy of live. If you have a few minutes: where do those
rows come from (which template, ACF field group, or plugin filter), and which 6 are missing? If you
can see why a theme copy loses them (e.g. a path or theme-name check in that code), say so. Don't
change anything for it.

## Report back, in this shape

1. **Before:** the cases' totals and ID counts per site, and the old options' values.
2. **Steps**, with times.
3. **Checks:** the new option per site and its autoload; whether any full rebuild started after
   the update; the compare result per case (search cases: ID counts before/after, first 462 the
   same, no repeats); the last page of a broad search.
4. **doctor:** the `doctor --wp` summary line per site.
5. **The spec rows**, if you looked; anything unclear or unexpected.
