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
            'card' => 'product/card.twig',        // rendered into every document, with `post`
        ],
    ],
    'archive' => [
        'index' => 'products',
        'per_page' => 24,
        'sorts' => ['' => 'Populair', 'price:asc' => 'Prijs oplopend', 'price:desc' => 'Prijs aflopend'],
        'default_sort' => ['total_sales:desc', 'title_sort:asc'],
        'redirect_search' => true,                // WordPress's ?s= opens the shop's results
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
- **Cards:** `card` is rendered with `post` (the theme's type for it).
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
  - `index_pages => true`: later pages are indexable too (offsets that are
    multiples of the page size).
  - `indexable_filters => ['on_sale' => ['yes']]`: a filtered page search
    engines may index (a sale page).
  - `base_params => ['show']`: params that make another page
    (`/category/?show=all`), kept in the canonical and page links.
  - `redirect_params => [Theme\X::class, 'method']`: gets the query, returns it
    with the theme's old params translated (a former filter plugin's
    `?onsale_filter=1` → `filter[on_sale]=yes`); a different page is a 301.
  - `facet_values => ['size' => 400]`: values listed per facet (default 100).
  - `max_docs => 5000`: the best matches a search with words ranks, sorts and
    counts over (default 500): a large catalog's first page then matches an
    exhaustive search.

## Templates

```php
// woocommerce/archive-product.php
$search = Search::archive();   // Gaffer\Search\Search; null on other pages
```

```twig
<script type="application/json" id="archive-results">{{ search.initial() }}</script>
<div x-data="archive({ cardField: '{{ search.card_field }}' })">
    <form id="archive-form" method="get" action="{{ search.endpoint }}" data-gaffer-search-history="push" @submit.prevent="run()" @change="run()">
        <input type="hidden" name="token" value="{{ search.token }}">
        <input type="hidden" name="offset" :value="offset" value="{{ search.results.offset }}" data-gaffer-search-default="0">
        {% for sort in search.sorts %}
            <label><input type="radio" name="sort[]" value="{{ sort.value }}" data-gaffer-search-default="" {{ sort.value == search.sort ? 'checked' }}> {{ sort.label }}</label>
        {% endfor %}
    </form>
    <ul id="archive-list">{% for card in search.cards %}<li>{{ card }}</li>{% endfor %}</ul>
    {# pages: x-for over pageNumbers(), @click="goTo(page)" #}
</div>
```

- `search.available` is false without the plugin (or when it doesn't speak
  Gaffer's contract): say so in the archive (`{% if not search.available %}`
  "products can't be shown right now"; `doctor` warns when no template reads
  it) and leave the searchbar out (`Search::searchbar()` has `available` too).
- Sliders: `Search::cards('products', 10, ['total_sales:desc'], $category, except: $post)`.
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

Alpine.data("archive", (options) => archive({ ...options, itemClass: "flex flex-col", onRender: (list) => htmx.process(list) }));
```

- `archive()` fills `#archive-list` with each response's cards and calls
  `onRender` (htmx for the cards' forms); `goTo(page)` pages and scrolls.
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
gaffer-search has no `fuzor_`/`gaffer_search_` theme API. Stage the theme
(`cp -r theme theme-stage`, see `gaffer-migrate`): `php gaffer` in the copy runs
it as the active theme without the object cache, and builds the index before
the switch with `wp --require=vendor/onstage2426/gaffer/bin/stage.php
gaffer-search rebuild`. Snapshot the live site first (`render:snapshot
--from`), compare searches and archive counts after.

| fuzor-wp | config/search.php |
|---|---|
| `fuzor_register_index()` on `fuzor_register_indexes`, builder options | `indexes.{name}` with `type` and the same keys: `searchable`, `facets`, `sortable`, `meta`, `taxonomy` → `taxonomies`, `synonyms` (`oneWay` → `one_way`), `thumbnail_size`, `variation_price` |
| builder `language` | `language`: keep the old index's (`wp fuzor describe`; often `''`), or every search changes |
| builder `directTerms` | `direct_terms` |
| `fuzor_index_document` adding the card | `card` (+ `card_context`, `card_variants`/`card_variant`) |
| `fuzor_index_document` adding other fields, `fuzor_index_schema` | `document` + `fields` |
| `fuzor_index_version` | nothing: Gaffer versions the definition, card and methods |
| `fuzor_archive()` | `Search::archive()` with `archive` config |
| token `limit` / `limits` | `archive.per_page` / `archive.limits` |
| token `facet_values` | `archive.facet_values` |
| token `max_docs` | `archive.max_docs` |
| `fuzor_archive_indexable_page_size` | `archive.index_pages` |
| `fuzor_archive_indexable_facets` | `archive.indexable_filters` |
| `fuzor_archive_url_params` | `archive.base_params` |
| `fuzor_redirect_archive_query` | `archive.redirect_params` |
| `fuzor_search()` for sliders | `Search::cards()` |
| searchbar token | `Search::searchbar()` |
| `window.Fuzor`, `data-fuzor-*` | `@gaffer/search.js`, `data-gaffer-search-*` |
| token `views` (a list layout) | not supported: one card per index; a list view is CSS on that card |
| a token with `filter[id]` open (recently viewed) | not supported: an ajax action that renders the card template for the IDs |
| token `bounds` (a range over the whole archive) | not supported: `facetStats` are over the results |
| facet search tokens | not supported: `facet_values` and filtering the list in the browser |
| `fuzor_audience_filter` | not supported: `card_variants` for prices per customer group |

## Checking

- `php gaffer doctor --wp`: the plugin (and the features the config needs),
  the definitions, every index built and current, one card rendered with
  strict variables, old plugin API calls (gaffer-search's and fuzor-wp's).
- `gaffer-search` (MCP): search an index (query, filter, facets) and see its
  status: for synonyms, facets, "why isn't X found".
- `php gaffer render:screenshot / --fill='#search=…'` and on an archive
  `--click` a sort: the browser side, with the endpoint's failures listed.
