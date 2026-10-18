---
name: gaffer-search
description: Build or change search, a searchbar or a faceted archive with the Fuzor plugin in a Gaffer theme (inc/fuzor.php index, Theme\Search tokens and fuzor_archive(), Alpine components on window.Fuzor, archive URLs and SEO). Use before touching any of them.
---

# Search with Fuzor

The Fuzor plugin (`fuzor-wp`) is active: search and faceted archives from its
own index, with signed tokens and its own frontend script (`window.Fuzor`). Its
API is documented in the plugin, `wp-content/plugins/fuzor-wp/docs/` (`php.md`,
`js.md`, and `migration.md` after updating it); this covers how it fits a
Gaffer theme.

- **The index** is registered in `inc/fuzor.php`, inside
  `add_action('fuzor_register_indexes', function (): void { … })` (the plugin
  fires it at `init:0`; with the plugin inactive it never runs, so no
  `function_exists()` check), with `FuzorSearch\IndexDefinition` and a builder
  from `FuzorSearch\Builders\` (`ProductIndexBuilder`). The right-hand side of
  the builder's `taxonomy` map (`'pa_kleur' => 'color'`) is the field name
  everywhere else: `filter[color]`, facets, the JS fields. Filter values, facet keys, URLs and
  theme maps (icons per term, the SEO allowlist) use term **slugs**; the names
  come with every response as `labels`: `opt.label` in option lists,
  `hit.labels.brand` on a hit, `label` on a facet-typeahead hit. Never show a
  slug. Only facet fields sort: alphabetical sorting needs `sortable: ['title']`
  and sorts on `title_sort`; products also sort on `menu_order` (the shop's own
  order) and `total_sales` (popularity). The order while browsing is the
  token's `default_sort` (e.g. `['menu_order:asc', 'title_sort:asc']`), not
  hidden `sort[]` inputs: a query still ranks by relevance. A locked filter (a token's, `fuzor_archive()`'s)
  names facet fields only: any other field fails the search.
- **PHP prepares it, in one class** (`Theme\Search`): a searchbar's endpoint
  and token (`fuzor_generate_token()`), an archive's `fuzor_archive()` (token,
  the results for this URL, facet tokens; on a term archive the token is locked
  to that term). Never call `fuzor_*` from Twig, even though Fuzor's own docs
  do: templates don't look anything up. Every token hides products with
  `fuzor_visibility_filter('search')` or `('catalog')`. With customer groups
  (the builder's `audience`), `Theme\Search` also adds
  `fuzor_audience_filter($groups)` and a `ttl` for a group's token; such pages
  must not be served from a page cache to other visitors. Show "refresh the
  page" for both `invalid_token` and `token_expired`.
- **Server first, the browser enhances:** an archive is a
  `<form method="get">` with the token in a hidden input. Twig renders the
  first page from `results`, and the same `results` go to the Alpine component
  as `initial`, through a JSON island
  (`<script type="application/json" id="…">{{ search.results|json_encode|raw }}</script>`),
  so the browser never fetches the page it already has.
- **`Fuzor` is the plugin's global** (its own script, in `<head>`): use it
  directly, never bundle or import it. Theme components spread
  `Fuzor.createAlpineSearch()`, `createFilterForm()` or `createFacetSearch()`
  and add only their UI state (open/close, scrolling). Filters, pagination
  (`goToPage()`), back/forward and the request itself are Fuzor's: don't
  rebuild them. Prefer the `onBeforeRun`/`onSuccess` hooks over overriding
  `run()`; write them as methods (`onSuccess(data) { this.… }`): an arrow
  function gets the component as its last argument, not as `this`.
  `createFilterForm()` fields have a `type` (`multi`, `single`, `range` for
  min/max inputs, `toggle` for one checkbox like "on sale") and the component
  keeps `sort`: no own URL seeding for them. Page sizes and grid/list are
  token keys (`limits`, `views`), chosen with plain `limit`/`view` selects —
  one token, not one per choice. A price slider takes its bounds from
  `fuzor_archive()`'s `bounds` (`'bounds' => ['price']`), not the first
  response. A facet lists 100 values unless the token's `facet_values` says
  more (`['size' => 400]`); a response's `truncatedFacets` names the fields cut.
- **Hits are index documents, not Gaffer types:** every stored field is plain
  text (`{{ hit.title }}`, `x-text`); only `hit.formatted.*` is HTML (`|raw`,
  `x-html`).
- **URLs in PHP** (page links, canonical, redirects) come from
  `fuzor_archive_url($query)`, never `http_build_query()`/`add_query_arg()`:
  those number lists (`filter[x][0]`), which the form doesn't read back.
- **SEO (Yoast):** the canonical is `fuzor_archive_url()` on `wpseo_canonical`
  at priority **20** (Yoast's WooCommerce integration overwrites 10 on the shop
  page), robots from `fuzor_archive_indexable($index)` (`wpseo_robots_array`),
  and `fuzor_redirect_archive_params($perPage, $index)` on `template_redirect`
  (redirects `/page/N/` to `?offset=`, translates WooCommerce's own filter,
  price and `orderby` params; another filter plugin's old URLs are the
  theme's to redirect, with `fuzor_archive_url()`). Which facet values get an indexable page is the site's
  call (`fuzor_archive_indexable_facets`); a search, `limit` or `view` is
  never indexable, so no own `noindex` for them.
