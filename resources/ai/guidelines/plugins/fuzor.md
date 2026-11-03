## Search (Fuzor)

The Fuzor plugin (`fuzor-wp`) is active: search and faceted archives from its
own index, with signed tokens and its own frontend script (`window.Fuzor`).
Before changing search, a searchbar, an archive's filters or `inc/fuzor.php`,
follow the `fuzor-search` skill. Always:

- **PHP prepares it, in `Theme\Search`** (tokens, `fuzor_archive()`). Never
  call `fuzor_*` from Twig, even though Fuzor's own docs do.
- **Values are term slugs, shown as labels:** filters, facet keys, URLs and
  theme maps use slugs; every response has the names (`labels`). Never show a
  slug.
- **`Fuzor` is the plugin's global:** never bundle or import it. Components
  spread its Alpine helpers and add only UI state; filters, pagination and
  the request are Fuzor's.
- **Cards with forms stay outside the search form:** the component sits on a
  wrapper with `createAlpineSearch({ form })`; a nested `<form>` adds its
  fields to the search.
- **URLs in PHP come from `fuzor_archive_url()`**, never
  `http_build_query()`/`add_query_arg()`.
- **fuzor-wp is replaced by gaffer-search** (`config/search.php` and
  `Gaffer\Search`; `doctor` warns about every `fuzor_*` use). Don't build new
  search on fuzor-wp. Moving a site: the mapping of every fuzor-wp option and
  hook, and what isn't supported, is in
  `vendor/onstage2426/gaffer/resources/ai/skills/plugins/gaffer-search/gaffer-search/SKILL.md`
  ("Moving from fuzor-wp"); read it before you start.
