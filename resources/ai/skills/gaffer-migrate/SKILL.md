---
name: gaffer-migrate
description: Change what's stored in a Gaffer site's content (rename or remove an ACF block or field, change a field's type, move a field group from the ACF UI to fields.php or fields/) and compare rendered pages before and after a change or a move to new code (render:snapshot, render:diff, a staged theme copy). Use before any of those.
---

# Gaffer content migrations and render comparison

## Changing a field's type

ACF stores values without their type, and nothing converts them:

- **In place** only when the stored value still means the same: text →
  textarea or wysiwyg, select → radio or button group, image → file (both an
  ID). Check how the block's PHP reads it.
- **Otherwise add a field with a new name** and stop reading the old one.
  `doctor --wp` then lists the pages that still store the old field: editors
  fill in the new one, then `migrate:remove-field` deletes the old values.
  Never write a converter.

## Renaming or removing a block or field

Change the code first, then migrate the stored content:

```
php gaffer migrate:field acf/content-faq titel kop                 # rename a field
php gaffer migrate:field acf/content-faq vragen.vraag vraag_tekst  # rename a sub field (repeater/group)
php gaffer migrate:block acf/content-faq acf/faq                   # rename a block (after its directory and block.json)
php gaffer migrate:remove-field acf/content-faq vragen.bron        # delete a removed field's values
php gaffer migrate:remove-block acf/content-team                   # delete a removed block from all content
php gaffer migrate:fields [acf/content-faq|post_type/review]       # after moving a field group from the ACF UI/JSON to fields.php or fields/
php gaffer migrate:remove-leftovers [acf/content-faq]              # delete values of other blocks'/field groups' fields fields.php doesn't have
php gaffer migrate:fields post_type/product --run --delete-groups  # a fields/ target: move, check values, then delete the UI groups
php gaffer migrate:remove-leftovers post_type/product              # a fields/ target: references under an old name, with their values
php gaffer migrate:remove-field post_type/product excluton_3d_sku  # a fields/ target: every value and reference of a removed field
php gaffer migrate:rollback [backup]                               # undo (no argument: list backups)
```

- Moving fields out of the ACF UI/JSON: `migrate:fields` first. Renames and
  removals refuse while a block still stores data under its old field keys.
- For `fields/` (options pages, post types, taxonomies), `migrate:fields`
  rewrites the `_name` rows next to the values from the UI group's keys to the
  derived ones, only where they're the target's own: on that post type (and
  its revisions), that taxonomy, or that options page (its `post_id`, per
  language), stored under the name of the field the key belongs to
  (`vragen_0_vraag` for a repeater's sub field). It stops on fields the file
  doesn't have or has with another type, naming where the values are. It
  repairs references under a field's name whose key no field has (a broken
  import) to that field's key, and reports and leaves alone references
  outside the target (a post type the group once also targeted) and
  references under another name (an old field name).
- The order for a `fields/` target: `migrate:fields` (dry run, `--run`), then
  `migrate:remove-leftovers {target}` for the old names it reported (it knows
  them by the UI groups' keys), then `migrate:fields {target} --run
  --delete-groups` deletes the UI groups (refused while old-name references
  remain). `migrate:rollback` restores references and values, not deleted
  groups: their JSON is in git.
- `migrate:remove-field {target} {field}` deletes every row stored under the
  field's name in the target (values and references, posts and their
  revisions, terms, options in every language), by row ID, once the field is
  gone from the file and from the target's UI groups. A sub field:
  `vragen.bron`. Never delete meta by hand (`delete_post_meta()` on a
  revision deletes the parent's).
- The dry run prints one line per field (old key → new key, how many, which
  kinds); `-v` lists every location. `--run` also reads every changed field
  through ACF (`get_field()`, formatted) before writing and after the
  read-back: any difference (a missed sub field, another return format) ends
  the run as `verify_failed` with the rollback command.
- `migrate:field`, `migrate:block` and `migrate:remove-block` are for blocks
  only (renaming a field of a `fields/` target: not yet).
- A block can keep values of fields that aren't its own (transformed or
  copied from another block, an old field group). The commands refuse them;
  `migrate:remove-leftovers` deletes the ones fields.php has no field for
  (by name), and leaves the rest to `migrate:fields`.
- One field per run: removing or renaming several fields of a block is one
  run each, in any order.
- Dry run by default; `--run` writes. It refuses (and writes nothing) when
  anything looks off: the code isn't changed yet, content WordPress doesn't
  reproduce exactly, data in an unexpected shape, a name that already exists,
  a page open in the editor, a block to remove that contains other blocks.
- `migrate:remove-block` reports pages and widgets left empty; deleting those
  is a person's decision.
- `--run` saves the old content to `storage/backups/migrate/` (never
  committed), writes everything in one transaction and reads it back.
  Rollback only restores content nobody has edited since.
- Every `--run` is logged in `storage/logs/migrate.log` (one JSON line:
  outcome `written`/`aborted`/`verify_failed`, reason, backup, the backup it
  `undoes`, user, theme commit, locations with checksums, or their count per kind
  above 200: the backup lists each one). Check it before
  assuming what state the content is in; `migrate:rollback` without an
  argument lists the backups with their outcome.
- Check the result the same way every time: `php gaffer render:snapshot`
  before changing code or content, `php gaffer render:diff` afterwards (see
  below). When moving a site to new code, snapshot the live site with
  `--from=<url>` and diff this copy against it. On a site without staging,
  stage the change in a full copy of the theme next to it (`cp -r theme
  theme-stage`, `vendor/` included), edit the copy, then `php gaffer
  render:snapshot` and `php gaffer render:diff --theme=theme-stage` from the
  live theme: staged against live, before switching. No throwaway compare
  scripts.
- Make a database backup first on a live site, and run it right after
  deploying the code (until then a renamed field shows empty).
- Page caches: the write bypasses `wp_update_post()`, so only cache plugins
  that purge on `clean_post_cache` (WP Rocket does) see it, and a purge can
  fail (e.g. cache files owned by the web server user). `--run` lists the
  changed URLs: check them, purge by hand if they're stale. Changed block
  widgets mean every page.
- Covers posts of every type and status and block widgets, not revisions.

## Comparing rendered pages

`render:snapshot [name] [--block=] [--path=] [--user=] [--cart=] [--theme=] [--from=]` /
`render:diff [name] [--select=] [--ignore=] [--lines=] [--theme=] [--json]`

- **`render:snapshot`** saves the HTML of doctor's URL sample, every published
  post (any public type) using an ACF block (or only `--block`), and each
  `--path` (product pages, checkout, filters) to `storage/snapshots/` (never
  committed).
  - As a visitor, or `--user=<id>` and WooCommerce `--cart=<product>[:<qty>]`
    (nothing is written: no session, saved carts untouched).
  - `--theme=<dir>`: a full staged copy of the theme next to it (its directory
    name reads as the live theme's in the comparison).
  - `--from=<site>`: fetched over HTTP (the live site on the old code).
- **`render:diff`** renders the same pages the same way again and shows the
  differences: entities and whitespace normalized, each distinct change once
  with the pages it's on, most widespread first (`--json`: every change with
  its pages and lines). Exit 1 on any difference.
  - `--select`: a CSS selector. `--ignore`: a regex for time-based or random
    content, matched against the page as rendered (mind single and double
    quotes). Gravity Forms' markup has per-request tokens and a honeypot field
    with a random label (`\x27` is a single quote):
    `--ignore='/name=\x27(?:gform_currency|state_\d+)\x27[^>]*value=\x27[^\x27]*\x27|gfield--type-honeypot.*?<\/label>/s'`.
    WPML's cookie script has a timestamp: `--ignore='/"expires":"[^"]*"/'`.
  - What changes on every request on this site goes in `console.render_ignore`
    (config), not on every command line. Typical candidates: signed CDN or
    social image URLs (Instagram's `?…&oh=…&oe=…`), timings (`"ms":\d+`),
    dates and times (delivery dates around midnight, `data-time`), plugin
    version strings (`banner_version`), per-request tokens and nonces.
  - Changes that only reorder lines (random suggestions, items with equal
    counts) are marked "(reorder only)"; `--hide-reorders` leaves them out (a
    page with nothing else counts as the same). A long line that changed is
    shown as the part that differs; `--json` has the full lines.
- Renders leave out the page cache and WP Rocket optimization; a `--from`
  snapshot of a live WP Rocket site still has Rocket's markup, so compare a
  content region with `--select`.
