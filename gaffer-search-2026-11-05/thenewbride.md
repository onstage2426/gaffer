# gaffer-search 0.0.0+f5884e7 (thenewbride)

Single site `thenewbride.srv28.bsmedia.nl`. A plugin-only update: the theme doesn't change.

Thank you for the report of 2026-11-04. This plugin version fixes two things another site found;
neither changes what this catalog shows:

- What an index was built from is now one option per index, not autoloaded (with a persistent
  object cache like Redis, the old autoloaded arrays could make an index rebuild in a loop). The
  old options are moved on the first request after the update.
- A search with words matching more than `max_docs` products (default 500) now returns hits on
  every page up to `total` (your searches return far fewer, so you won't see a difference).

Answers to your report: the price sorts and the price filter do nothing while no product has a
price, as you say; the theme doesn't offer them, which is right. The `gaffer-search-standard-fields/`
folder in the theme can be deleted (it held the old zip, with the updater's token). About the commit
of `composer.lock` together with the earlier uncommitted work: wait for the user's answer, which
they'll give you with this prompt.

## Files

`gaffer-search.zip` (version `0.0.0+f5884e7`), `archives.php` and `compare.py` are next to this
prompt. **Ask the user where they are on your server**; don't download them from anywhere.

## Steps

1. **Back up** the current plugin folder (`0.0.0+2ea4fa9`) and note
   `wp option get gaffer_search_built_signatures --format=json`.
2. **Before:** `wp eval-file archives.php before-f5884e7.json <your cases file>`.
3. **Install:** `wp plugin deactivate gaffer-search`, replace the folder with the zip's contents
   (as last time), `wp plugin activate gaffer-search`.
4. **Check:**
   - `wp option get gaffer_search_built_products --format=json` (your index name) has `signature`
     and `version`; `wp option get gaffer_search_built_signatures` errors (moved);
   - `php gaffer doctor --wp`: "built and current", 0 errors, and no full rebuild starts after it;
   - `wp eval-file archives.php after-f5884e7.json <your cases file>`, then
     `python3 compare.py before-f5884e7.json after-f5884e7.json`: every case `same`.
5. Delete `gaffer-search-standard-fields/` from the theme folder.

## Report back, in this shape

1. **Before:** the old option's value.
2. **Steps.**
3. **Checks:** the new option, whether a rebuild started, the compare per case.
4. **doctor:** the summary line.
5. Anything unclear or unexpected.
