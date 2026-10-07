## Search (Fuzor)

The Fuzor plugin (`fuzor-wp`) is active: search and faceted archives from its
own index, with signed tokens and its own frontend script (`window.Fuzor`). Its
API is documented in the plugin, `wp-content/plugins/fuzor-wp/docs/` (`php.md`,
`js.md`, and `migration.md` after updating it); this covers how it fits a
Gaffer theme.

- **The index** is registered in `inc/fuzor.php`, inside
  `if (function_exists('fuzor_register_index'))` (`inc/` loads before `init`,
  which registration needs). The right-hand side of the builder's `taxonomy`
  map (`'pa_kleur' => 'color'`) is the field name everywhere else:
  `filter[color]`, facets, the JS fields. Filter values, facet keys, URLs and
  theme maps (icons per term, the SEO allowlist) use term **slugs**; the names
  come with every response as `labels`: `opt.label` in option lists,
  `hit.labels.brand` on a hit, `label` on a facet-typeahead hit. Never show a
  slug. Only facet fields sort: alphabetical sorting needs `sortable: ['title']`
  and sorts on `title_sort`.
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
  `run()`.
- **Hits are index documents, not Gaffer types:** every stored field is plain
  text (`{{ hit.title }}`, `x-text`); only `hit.formatted.*` is HTML (`|raw`,
  `x-html`).
- **SEO (Yoast):** strip `token` from the canonical (`wpseo_canonical`), set
  robots with `fuzor_archive_seo_directives($index)` (`wpseo_robots_array`),
  and on an archive that pages with `?offset=`, redirect old URLs with
  `fuzor_redirect_legacy_archive_params()` on `template_redirect`. Which facet
  values get an indexable page is the site's call
  (`fuzor_archive_indexable_facets`).
