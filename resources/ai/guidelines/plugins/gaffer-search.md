## Search (gaffer-search)

The gaffer-search plugin serves search, faceted archives and product sliders
through Gaffer. Before changing search, a searchbar, an archive or
`config/search.php`, follow the `gaffer-search` skill. Always:

- **Configured, not coded:** indexes and the shop archive are
  `config/search.php`; templates get their data from `Gaffer\Search\Search`.
  Never call the plugin itself.
- **Cards are rendered into the index** and come back as trusted markup
  (`{{ card }}`). A changed card template rebuilds the index on a later visit
  (WP-cron; renders don't run it): while working on one,
  `wp gaffer-search rebuild <index>`.
- **Values are slugs, shown as labels** (term names, the index's `labels`):
  never show a slug.
- The browser continues the server's page with Gaffer's JavaScript
  (`@gaffer/search.js`); components add only UI state. Cards with forms stay
  outside the search form.
- **Without the plugin** templates get `available` false: say so, never break.
- After the plugin updates, read `wp-content/plugins/gaffer-search/docs/migration.md`
  from the version you had.
