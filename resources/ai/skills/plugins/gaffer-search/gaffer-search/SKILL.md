---
name: gaffer-search
description: Build or change search in a Gaffer theme with the gaffer-search plugin: config/search.php (indexes, rendered cards, the shop archive), Gaffer\Search\Search in templates (archive, cards, searchbar), Gaffer's search JavaScript, and checking it. Use before touching any of them.
---

# Search with gaffer-search

The plugin is the engine (indexes, the search endpoint, rebuilds); Gaffer is
the integration. The theme writes config, templates and small components.

## config/search.php

```php
return [
    'indexes' => [
        'products' => [
            'type' => 'products',                 // or 'posts' (with 'post_types')
            'language' => 'nl',
            'searchable' => ['title', 'short_description', 'origin'],
            'facets' => ['price', 'category', 'on_sale'],
            'meta' => ['_price' => ['price', 'float'], '_stock_status' => 'stock_status', 'herkomst' => 'origin'],
            'taxonomies' => ['product_cat' => 'category'],
            'sortable' => ['title'],
            'synonyms' => ['equivalences' => [['garnalen', 'gamba', 'scampi']]],
            'variation_price' => 'default',       // cards show the default variation's price
            'rebuild' => 'twicedaily',
            'cards' => ['grid' => 'product/card.twig'], // rendered into every document, with `post`
        ],
    ],
    'archive' => [
        'index' => 'products',
        'per_page' => 24,
        'sorts' => ['' => 'Populair', 'price:asc' => 'Prijs oplopend', 'price:desc' => 'Prijs aflopend'],
        'default_sort' => ['total_sales:desc', 'title_sort:asc'],
        'urls' => ['redirect_search' => true],    // WordPress's ?s= opens the shop's results
    ],
];
```

- `language` sets the stemming (`'nl'` finds "tegels" for "tegel"), so it
  changes which products a search finds and their order. Moving a site from
  another index (fuzor-wp), keep the old index's language (`wp fuzor describe`;
  often `''`) and compare searches before changing it.
- The right-hand side of `meta`/`taxonomies` is the field name everywhere:
  `filter[category]`, facets, sorts, the JS. Prices and stock come from `meta`.
  Only fields the index declares sort and filter; text sorts on
  `{field}_sort` (`sortable`), products also on `menu_order` and
  `total_sales` (popularity). `doctor --wp` asks the engine what's wrong with
  a definition (an unknown field, with a suggestion).
- **Cards:** `cards` maps a layout to its template; the first is the
  default. Each is rendered with `post` (the theme's type for it).
  `card_context` (`[Theme\X::class, 'method']`, gets the post, returns the
  template's variables) prepares it differently (a default size).
  **Variants** (prices excl. VAT for business customers):
  `card_variants => ['business' => [Theme\Business::class, 'excl_vat']]`
  (gets a closure that renders the card, returns its HTML, e.g. with a tax
  display filter around it) and `card_variant => [Theme\Business::class,
  'variant']` (returns `'business'` or null for this visitor). Each variant is
  a field of its own (`card_business`); its visitors' tokens expire after a
  day, so such pages mustn't be cached for others. A change to the card
  template, anything it includes, or the files of `card_context`/variant
  methods rebuilds the index by itself.
- **Computed fields** (a price per m² to filter on): `document => [Theme\X::class,
  'method']` gets the post and the document (`price`, the mapped fields) and
  returns the fields to add; declare them in `fields` (`['price_m2' =>
  'float']`) and they work in `facets`, `sortable` and `searchable`. Return
  null for a product without one. Its file counts in the version like a card
  method.
- **More layouts** (a list view with other markup than the grid card):
  `cards => ['grid' => 'product/card.twig', 'list' => 'product/list-item.twig']`
  renders a second card per document (`card_list`, also per variant; the
  default's field is `card`). The archive at `?view=list` is that layout's
  page: its cards and token use `card_list`, the canonical is the page without
  `view`, and it's noindex. `search.views` lists the layouts (`name`, `value`
  (the `?view=`, `''` for the default), `url`, `current`) and `search.view` is
  the current one; link with `:href="viewUrl('{{ v.value }}')" href="{{ v.url }}"` (the
  component's `viewUrl()` keeps the visitor's current filters). A layout
  that's only CSS needs no second card: a class from `search.view`. Each card
  costs index size and build time.
- **Hierarchical taxonomies** store a term's ancestors too: a parent
  category's archive shows its children's products. `direct_terms =>
  ['product_cat']` keeps each category to its own products (WooCommerce with
  `include_children: false`); a filter on the parent then also matches only
  its own.
- **The archive** (`archive`): the shop, product categories and the shop's
  search results (`?q=`). Gaffer adds the redirects (`/page/N/` → `offset`,
  WooCommerce's `orderby`/`min_price`/`filter_*`), the canonical URL and robots
  (Yoast, or WordPress's own): no hooks of the theme's own for them. Indexable:
  the first page without query, sort or extra filter. Optional keys:
  - `limits => [12, 24, 48]`: page sizes the visitor picks (`<select
    name="limit">` in the form; `per_page` must be one); the canonical leaves
    `limit` out.
  - `facet_values => ['size' => 400]`: values listed per facet (default 100).
  - `max_docs => 5000`: the best matches a search with words ranks, sorts and
    counts over (default 500): a large catalog's first page then matches an
    exhaustive search.
  - `bounds => ['price']`: `search.bounds.price` (`{min, max}`) over the whole
    archive, for a price slider's ends (`facetStats` follow the results). It
    costs one more search per archive page: leave it out without a slider.
  - `seo => ['index_pages' => true]`: later pages are indexable too (offsets
    that are multiples of the page size).
  - `seo => ['indexable_filters' => ['on_sale' => ['yes']]]`: a filtered shop
    page search engines may index (the sale page); on categories and brands it
    stays noindex.
  - `urls => ['base_params' => ['show']]`: params that make another page
    (`/category/?show=all`), kept in the canonical and page links.
  - `urls => ['redirect_params' => [Theme\X::class, 'method']]`: gets the
    query after WooCommerce's params became the archive's (`min_price` is
    `filter[price]` by then; its own old params are still there as they came)
    and returns it with the theme's old params translated or dropped (a former
    filter plugin's `?onsale_filter=1` → `filter[on_sale]=yes`, `?yith_wcan=1`
    gone). Any change is a 301 to exactly what it returned, so return tracking
    params (`utm_*`) you want kept, and the query unchanged when nothing applies.

## Templates

```php
// woocommerce/archive-product.php
$search = Search::archive();   // Gaffer\Search\Search; null on other pages
```

```twig
<script type="application/json" id="archive-results">{{ search.initial() }}</script>
<div x-data="archive({ cardField: '{{ search.card_field }}' })">
    <form id="archive-form" method="get" action="" data-gaffer-search-endpoint="{{ search.endpoint }}" data-gaffer-search-history="push" @submit.prevent="run()" @change="run()">
        <input type="hidden" name="token" value="{{ search.token }}">
        <input type="hidden" name="offset" :value="offset" value="{{ search.results.offset }}" data-gaffer-search-default="0">
        {% for sort in search.sorts %}
            <label><input type="radio" name="sort[]" value="{{ sort.value }}" data-gaffer-search-default="" {{ sort.value == search.sort ? 'checked' }}> {{ sort.label }}</label>
        {% endfor %}
    </form>
    <ul id="archive-list">{% for card in search.cards %}<li>{{ card }}</li>{% endfor %}</ul>
    {# pages: x-for over pageNumbers(), :href="pageUrl(page)", @click.prevent="goTo(page)" (server render: search.page_url(n)) #}
</div>
```

- The form's `action=""` is the page itself, so it works without JavaScript;
  the browser searches `data-gaffer-search-endpoint`. Base params (`?show=all`)
  need a hidden input to survive a submit without JavaScript.
- **Page links:** `search.page_url(2)` is page 2 of this search (filters,
  sort, page size, base params and the layout kept): real `<a href>`s, which
  crawlers need with `seo.index_pages`; the component's `goTo()` takes over
  the click.
- **Links to other archives:** `Search::archive_url($brand_term, ['filter' =>
  ['brand' => ['x']]])` (PHP prepares them; null for the shop's own when the
  theme has no archive); `Search::archive_url(null, ['filter' => ['on_sale' =>
  'yes']])` is the shop's sale page.
- **The first row:** `archive.eager => 4` makes the main image of the first 4
  stored cards `loading="eager"` (the first also `fetchpriority="high"`) on
  every page (later pages are landing pages too); per layout
  `['grid' => 4, 'list' => 2]`, so the LCP image isn't lazy. It rewrites the card template's first
  `loading="lazy"`: put the main image first and write the attribute literally;
  later images (a hover image) stay lazy.

- `search.available` is false without the plugin (or when it doesn't speak
  Gaffer's contract): say so in the archive (`{% if not search.available %}`
  "products can't be shown right now"; `doctor` warns when no template reads
  it) and leave the searchbar out (`Search::searchbar()` has `available` too).
- Sliders: `Search::cards('products', 10, ['total_sales:desc'], $category, except: $post)`;
  `filter: ['on_sale' => 'yes']` for an "on sale" tab (the Filter shape: a value,
  a list, `['not' => …]`, `['min' => …, 'max' => …]`).
- A searchbar: `Search::searchbar('products', 8, facets: ['price', 'category'],
  attributes: ['id', 'url', 'thumbnail', 'price', 'category'])` gives
  `endpoint`, `token`, `results_url` (the shop: append `?q=`).
- Hits in a searchbar are index documents: plain text (`x-text`), only
  `hit.formatted.*` is HTML (`x-html`); names in `hit.labels`.
- Show "refresh the page" for the error codes `invalid_token` and
  `token_expired`, "try again later" for any other.

## JavaScript

The theme's `vite.config.js` gets an alias for Gaffer's JavaScript:

```js
resolve: { alias: { "@gaffer": path.resolve("vendor/onstage2426/gaffer/resources/js") } },
```

```js
import htmx from "htmx.org";
import { archive } from "@gaffer/search.js";

Alpine.data("archive", (options) => archive({
    ...options,
    fields: [{ field: "brand", type: "multi" }, { field: "price", type: "range" }, { field: "on_sale", type: "toggle" }],
    itemClass: "flex flex-col",
    onRender: (list) => htmx.process(list),
}));
```

- `archive()` fills `#archive-list` with each response's cards and calls
  `onRender` (htmx for the cards' forms); `goTo(page)` pages and scrolls.
  With `fields` it's a filter form too: `filters`, `options(field)`,
  `activeFilters()`, `removeFilter()` (the same fields as `createFilterForm()`).
- Page links: `<a :href="pageUrl(page)" href="{{ search.page_url(n) }}"
  @click.prevent="goTo(page)">`: `pageUrl()` follows the visitor's search after
  paging and filtering, `search.page_url()` is the server's first render.
- A searchbar spreads `createAlpineSearch({ minQueryLength: 1 })` and adds its
  UI state; `getFacetOptions()`, `formatCurrency()`,
  `createDualRangeSlider()` (`slider.syncFromStats(data.facetStats.price)`),
  `getPageNumbers()` are there too (starting styles for the slider:
  `vendor/onstage2426/gaffer/resources/js/search/range-slider.css`). Filters, pages, back/forward and the
  request are the helpers': don't rebuild them. Hooks as methods
  (`onSuccess(data) { this.… }`), not arrow functions.
- A field the form shouldn't send leaks in as a console warning "Parameters
  this endpoint doesn't read".

## Moving from fuzor-wp

fuzor-wp sites (`fuzor_*()`, `window.Fuzor`, `data-fuzor-*`; `doctor` lists
them) move to `config/search.php` in the same deploy that switches the plugin:
gaffer-search has no `fuzor_`/`gaffer_search_` theme API. **Activate
gaffer-search for the site first** (network-wide on a multisite): it does nothing
for a theme without `config/search.php`, and every process (renders,
`doctor --wp`, WP-CLI) then sees it; a plugin activated only for one process
(a prepend) isn't active in the renders it starts, which `doctor --wp` reports.
Stage the theme (`cp -r theme theme-stage`, see `gaffer-migrate`): `php gaffer`
in the copy runs it as the active theme without the object cache, and builds
the index before the switch with `wp --require=vendor/onstage2426/gaffer/bin/stage.php
gaffer-search rebuild`. Content edited between that build and the switch
isn't tracked (the plugin isn't active for the live theme yet): right after the
switch, `wp gaffer-search verify --fix` updates what differs (one full
verify, then only the fixed documents are checked again). Snapshot the live
site first (`render:snapshot --from`), compare searches and archive counts after.
Back up fuzor-wp's index with SQLite's `VACUUM INTO` (a `tar` of a live index with
WAL can be torn) through PHP when the system `sqlite3` is old: `php -r '(new
PDO("sqlite:/path/index.db"))->exec("VACUUM INTO \"/backup/index.db\"");'`.

| fuzor-wp | config/search.php |
|---|---|
| `fuzor_register_index()` on `fuzor_register_indexes`, builder options | `indexes.{name}` with `type` and the same keys: `searchable`, `facets`, `sortable`, `meta`, `taxonomy` → `taxonomies`, `synonyms` (`oneWay` → `one_way`), `thumbnail_size`, `variation_price` |
| builder `language` | `language`: keep the old index's (`wp fuzor describe`; often `''`), or every search changes |
| builder `directTerms` | `direct_terms` |
| `fuzor_index_document` adding the card | `cards` (+ `card_context`, `card_variants`/`card_variant`) |
| `fuzor_index_document` adding other fields, `fuzor_index_schema` | `document` + `fields` |
| `fuzor_index_version` | nothing: Gaffer versions the definition, card and methods |
| builder `rebuildInterval` | `rebuild` |
| builder `chunkSize` | nothing: the plugin's own batch size |
| `fuzor_index_document` adding fields only the card shows (a hover image) | nothing: the card template reads them from the post |
| `meta` mapped only for cards rendered from hits | drop it: cards are stored, rendered from the post |
| `fuzor_visibility_filter('search' / 'catalog')` | nothing: `Search::searchbar()` hides what search hides, the archive and `Search::cards()` what the catalog hides |
| `fuzor_redirect_archive_params()` on `template_redirect` | nothing: Gaffer's archive redirects (`/page/N/`, WooCommerce's params) |
| `fuzor_archive()` | `Search::archive()` with `archive` config |
| token `limit` / `limits` | `archive.per_page` / `archive.limits` |
| token `facet_values` | `archive.facet_values` |
| token `max_docs` | `archive.max_docs` |
| `fuzor_archive_indexable_page_size` | `archive.seo.index_pages` |
| `fuzor_archive_indexable_facets` | `archive.seo.indexable_filters` (the shop only) |
| `fuzor_archive_url_params` | `archive.urls.base_params` |
| `fuzor_redirect_archive_query` | `archive.urls.redirect_params` |
| `fuzor_search()` for sliders | `Search::cards()` |
| searchbar token | `Search::searchbar()` |
| `window.Fuzor`, `data-fuzor-*` | `@gaffer/search.js`, `data-gaffer-search-*` |
| token `views` (a list layout) | `cards => ['grid' => …, 'list' => …]`, the page at `?view=list` (or CSS on one card) |
| a token with `filter[id]` open (recently viewed) | not supported: an ajax action that renders the card template for the IDs |
| token `bounds` (a range over the whole archive) | `archive.bounds`, read as `search.bounds` |
| facet search tokens | not supported: `facet_values` and filtering the list in the browser |
| `fuzor_audience_filter` | not supported: `card_variants` for prices per customer group |

## Checking

- `php gaffer doctor --wp`: the plugin (also in the renders it starts), the
  definitions, every index built and current, one card rendered with
  strict variables, old plugin API calls (gaffer-search's and fuzor-wp's).
- `gaffer-search` (MCP): search an index (query, filter, facets) and see its
  status: for synonyms, facets, "why isn't X found".
- `php gaffer render:screenshot / --fill='#search=…'` and on an archive
  `--click` a sort: the browser side, with the endpoint's failures listed.
