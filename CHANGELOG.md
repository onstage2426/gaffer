# Changelog

What a theme has to do after updating Gaffer, newest first. Each entry: what
changed, how to find the code it affects, and what to check afterwards. Read
the entries since the version in your `composer.lock` after every
`composer update onstage2426/gaffer` (this file is in
`vendor/onstage2426/gaffer/CHANGELOG.md`). Until 1.0, changes may break; after
1.0 they're deprecations first (see `API.md`).

## 2026-10-20

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

Check: `render:snapshot` before updating, `render:diff` after. On blueprint-base
(WPML) the Dutch pages stopped showing the English/German product translations.

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
2. Move its `.twig` files to `views/{concept}/` (blueprint:
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
