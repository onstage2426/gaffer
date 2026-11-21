Audit gaffer-search (`plugins/gaffer-search/`) and Gaffer's side of it (`src/Search/`,
`resources/js/search*`, `SearchCheck`, `tests/fixtures/search-contract/`) for long-term stability.
The goal: a site's search never breaks or shows something else after a plugin, Gaffer, Fuzor or
platform update, and a plugin update never breaks a site on an older Gaffer (nor a newer Gaffer
the plugin's previous release). What runs on the site is the endpoint, the index files, the
endpoint config, tokens in cached pages, the update queue and its cron job, the builders, the
updater, Gaffer's `Search` classes and the search JavaScript. The dashboard, WP-CLI and the bench
are tooling: flag them only when they can break running code or stored data.

Don't change code in this session. The output is the triage below and the plugin's
`docs/compat-debt.md`; I decide what gets built.

1. **Read first:** Gaffer's `AGENTS.md` ("Plugins", Design principles, Settled, Gotchas: the
   autoloaded options, the cache flush, the two paths of one theme), the plugin's `AGENTS.md`,
   `design.md`, `docs/contract.md`, `docs/hosting.md`, `docs/migration.md`, its
   `docs/compat-debt.md` (continue it from its "Open" list and check its dates; create it the first
   time), Gaffer's `docs/compat-debt.md` (its search entries: don't plan twice), `local/sites.md`
   and `local/AGENTS_LOCAL.md` (which sites run the plugin, how it's deployed there, the history of
   gaffer-search), and your memory (the gaffer-search notes). Don't re-propose what was decided
   there.

   **The plugin's working tree first:** `git -C plugins/gaffer-search status` and `log`. Uncommitted
   work isn't released and may not be yours: say what's in it and ask whether it's part of the
   audit. Audit the last tag (what sites run) and `0.x`, and say where they differ.

2. **Where the sites are.** Per site that runs the plugin (local ones and the reports in
   `feedback/`; copies in `themes/` have no plugin): the plugin version (a stamped `0.x.y.z` from
   `deploy.sh`, or a release), the Gaffer reference, each index's `FILE_FORMAT` and build state
   (`php gaffer doctor --wp` shows it), languages, multisite, how updates reach it (the updater,
   `deploy.sh`, a zip) and how content changes are applied (WP-cron, a system cron,
   `wp gaffer-search update`). Which `docs/migration.md` entries is each one behind on? Does each
   site's `composer.lock` reference still download, and does each release's zip still install?

3. **Map what the running code depends on**, with the exact functions, hooks, files and fields:
   - **The boundary with Gaffer, all of it.** It has three halves on three clocks: the plugin
     (its updater installs a release on its own, any day), Gaffer's PHP (`composer update`) and
     Gaffer's JavaScript (the theme's next build). Cached pages add a fourth: HTML with tokens
     and the build's JavaScript, kept for hours. Map each crossing:
     - PHP ↔ PHP: `bin/dev/contract gaffer-search` (it exits 1 on a mismatch), the recorded
       fixtures in both repos, `supports()` features, the `gaffer_search_engine` filter, the
       closures Gaffer hands over (labels, documents, cards) and what the plugin assumes of
       their answers;
     - browser ↔ endpoint (`docs/contract.md`, "The browser side"): the request keys Gaffer's
       JavaScript sends, the answer keys and error codes it reads, the attributes
       `search.attrs()` writes. Are they recorded anywhere a test reads, or only in prose?
     - Gaffer's PHP ↔ Gaffer's JavaScript (`initial()`'s JSON, `offset`, history attributes):
       what a build older than the PHP does.

     Try the combinations for real on the integration site, each with `doctor --wp` and a
     search in the browser: the plugin's `0.x` with Gaffer's previous pushed state, Gaffer's
     `0.x` with the plugin's last tag, and a theme build older than Gaffer's PHP. Say for each
     crossing what a change on one side does to a site that hasn't updated the others.
   - **The endpoint without WordPress:** what it reads (`sites/{id}.php` and its `format`, the
     index folder, `{slug}.labels.json`, `{index}.state.json`, the symlinked `{index}.db`), the
     request keys and answer keys the browser side relies on, its error codes, what it assumes of
     PHP (extensions: `pdo_sqlite`, the SQLite version and its FTS/JSON features) and of the server
     (PHP allowed in `wp-content/plugins`, the config folder found from the plugins folder).
   - **Tokens:** they never expire unless signed with `ttl`, so cached pages keep them for good.
     What in a token can change in a release (claims, signing, `site`/`language`) without
     invalidating every cached page? What happens after a secret rotation?
   - **Stored data:** index files and their `FILE_FORMAT` (what a format change costs a large site:
     time, memory, disk twice the index, a search during the rebuild), the queue table, options
     (one non-autoloaded option per key), snapshot generations on several servers, what
     `uninstall.php` removes.
   - **Fuzor** (`vendor/onstage2426/fuzor`, its own repository and branch): which of its APIs the
     plugin calls, how often they changed (its `git log`), and which of its settings show in the
     plugin's answers (`warnings`).
   - **WordPress, WooCommerce, WPML/WCML:** what the builders and `ChangeTracker` read (product
     data: prices, stock, variations, attributes and the `wc_attribute_taxonomies` cache, term
     hierarchy, visibility), which hooks report a change (and which writes fire none: WCML's sync,
     direct SQL, imports, scheduled sales), WP-cron, multisite switching, `wp_upload_dir()`.
   - **Gaffer's side:** `src/Search/`, `resources/js/search*` (Alpine, history, `offset`),
     `ArchiveUrl` (WooCommerce's own query keys), the archive's term lock, `search.attrs()`.

4. **What changes often:** `git log` per file in the plugin (and Fuzor) over `src/`, `endpoints/`,
   and Gaffer's search files; the `docs/migration.md` and CHANGELOG entries that broke something.
   Public and churning is a risk: say why it churns.

5. **What the platforms are changing.** Search the web (`extended` for the hard ones): PHP 8.5/8.6
   and the SQLite versions hosts ship (and PDO changes), WordPress (WP-cron, uploads, multisite,
   the Abilities/MCP APIs if the plugin touches them), WooCommerce (product data stores, the
   product lookup tables, HPOS where orders matter, scheduled sales, the Store API), WPML/WCML,
   ACF fields the builders read, Alpine and the browser APIs the search JavaScript uses (History,
   `URLSearchParams`). Measure what you can: `_deprecated_function`/`@deprecated` in the installed
   plugins for what the builders call, notices during a build and an update run.

6. **Fails silently?** Look for these first; each needs a check that runs on its own
   (`doctor --wp`, the dashboard's verify, the endpoint) rather than docs:
   - an index that stops following content (cron off or failing, a write that fires no hook, a
     queue position behind the pruned log, a build that dies on memory);
   - an endpoint that answers errors the visitor never sees (the JavaScript shows an empty list);
   - an index or a config served over HTTP (the deny `.htaccess` is Apache/LiteSpeed only, and an
     index holds hidden documents): fetch them like a visitor on each local site, with the rule
     removed for a moment to prove the check;
   - a rebuild loop or a lock that's never released; two servers that disagree;
   - a plugin update that undefines an index or changes what an archive shows.

   Compare what you find with what `doctor --wp` (`SearchCheck`, `WordPressCheck::search()`) and
   the dashboard already check.

6b. **Search like a visitor:** `bin/dev/site-checks/search.mjs` on every local site that runs it
   (searchbar, archive filters, sort, paging; no `token=` in the address bar), the browser console,
   and a search on each language of a multisite with WPML (`bin/dev/site-checks/search-pages.mjs`:
   each page's own token, no browser). Change a product (price, stock, a term)
   and watch it reach the index through the queue. Whatever only a browser found is a candidate
   for a check.

7. **Present the triage to me briefly**, most dangerous first. Per item: what can break, how likely
   and when (with the source), the smallest fix that keeps existing sites working, whether it
   needs a contract addition (the `contract-change` skill), a `FILE_FORMAT` bump or a 1.x of the
   plugin. Then wait for my answer.

8. **Write the plugin's `docs/compat-debt.md`** (tracked in its repository; public knowledge only):
   what we keep for compatibility, platform deprecations with dates and our plan, pairs that
   aren't self-adapting yet, each entry dated with its reason and what removing it would take.
   Fixed entries are removed and mentioned in `docs/migration.md`. Record what was decided there,
   what affects Gaffer in Gaffer's `docs/compat-debt.md`, and a memory note if you have memory.

9. **Improve this prompt** if the audit showed it missed something, and add a line under
   "Lessons". Keep reusable test scripts in a `bin/` of the repository they test (never only in
   `/tmp`).

## Lessons

Carried over from Gaffer's own audits (`prompts/audit.md`):
- Look for "fails silently" first, and measure instead of guessing: read the source of the
  internal you depend on, and fetch over HTTP what a visitor could fetch.
- Follow the deploy: ask how each site builds and updates, then look at what an update deletes
  and when the halves arrive, not only at what it adds.
- History is part of the contract: a lockfile or an updater that points at a commit or release
  that's gone breaks the next install.
- Before a test that writes (a submit, a product change), note what exists and undo by ID.

From the first run (2026-11-21):
- Try the update itself, not only the code: deploy the plugin's `0.x` to a site, list its cron
  events right after the first request, then roll back to the release. A signature change that
  rebuilt every index on every site only showed that way.
- Read the dependency's own history first (Fuzor's `git log` past the pinned commit, its
  CHANGELOG): the file-format promise asked for earlier had already landed there.
- Measure a hookless write (direct SQL on a post, not on a variable product's price: WooCommerce
  caches those itself) and see what notices it: `status`, `doctor --wp`, the daily sample.
- Locally only one site has an archive with filters: archive checks (a 503 while the index
  can't be read, the error state) need that site; the WPML network needs `search-pages.mjs`.
- The fixtures are recorded from PHP arrays: an empty map is `[]` there and `{}` on the wire.
- Tracked files and commit messages carry public knowledge only (AGENTS.md, "No private
  details"): what's particular to a host or a site's history goes in `local/`.
