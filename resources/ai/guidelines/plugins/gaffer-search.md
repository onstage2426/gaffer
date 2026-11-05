## Search (gaffer-search)

The gaffer-search plugin is active: search, faceted archives and product
sliders from its own index, through Gaffer. Before changing search, a
searchbar, an archive or `config/search.php`, follow the `gaffer-search` skill.
Always:

- **Configured, not coded:** indexes and the shop archive are
  `config/search.php`; templates get their data from
  `Gaffer\Search\Search` (`archive()`, `cards()`, `count()`, `counter()`, `searchbar()`). Never call
  the plugin itself: Gaffer is its only client.
- **Cards are rendered into the index** from the `cards` templates: they come
  back as trusted markup (`{{ card }}`, no `|raw`). A changed card template, or
  anything it includes, rebuilds the index by itself.
- **Values are slugs, shown as labels:** filters, facet keys and URLs use
  slugs; responses carry the names (`labels`): term names, and for other
  fields (an ACF select) what the index's `labels` method returns. Never show
  a slug.
- **The browser continues the server's page** with Gaffer's JavaScript
  (`@gaffer/search.js`, the Vite alias for
  `vendor/onstage2426/gaffer/resources/js`): components add only UI state.
- **Without the plugin** templates get `available` false: say so, never break.
- **Cards with forms stay outside the search form:** the component sits on a
  wrapper around form, list and pages.
- **Check it:** `php gaffer doctor --wp` (index built and current, one card
  rendered with strict variables), `gaffer-search` (MCP) to search an index,
  `render:screenshot` with `--fill`/`--click` for the browser side.
