## Search (Fuzor)

The Fuzor plugin (`fuzor-wp`) is active: search and faceted archives from its
own index, with signed tokens and its own frontend script (`window.Fuzor`).
Before changing search, a searchbar, an archive's filters or `inc/fuzor.php`,
follow the `gaffer-search` skill. Always:

- **PHP prepares it, in `Theme\Search`** (tokens, `fuzor_archive()`). Never
  call `fuzor_*` from Twig, even though Fuzor's own docs do.
- **Values are term slugs, shown as labels:** filters, facet keys, URLs and
  theme maps use slugs; every response has the names (`labels`). Never show a
  slug.
- **`Fuzor` is the plugin's global:** never bundle or import it. Components
  spread its Alpine helpers and add only UI state; filters, pagination and
  the request are Fuzor's.
- **URLs in PHP come from `fuzor_archive_url()`**, never
  `http_build_query()`/`add_query_arg()`.
