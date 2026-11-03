# Changelog

What a theme has to do after updating Gaffer, newest first. Each entry: what
changed, how to find the code it affects, and what to check afterwards. Read
the entries since the version in your `composer.lock` after every
`composer update onstage2426/gaffer` (this file is in
`vendor/onstage2426/gaffer/CHANGELOG.md`). Until 1.0, changes may break; after
1.0 they're deprecations first (see `API.md`).

## 2026-11-03

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
