## CLI (`php gaffer`)

Run from the theme directory. Commands that need WordPress load it themselves
(site URL from `SITE_URL` in the theme's `.env`, set by `php gaffer init`, or
`--url`).

| Command | What it does |
|---|---|
| `init [--url]` | Sets up a checkout: `SITE_URL` in `.env` (checked: it must load the site that runs this theme; on a multisite, that site's URL), then `ai:install`. |
| `config:show [key] [--json]` | The theme's config; flags keys Gaffer doesn't know. |
| `twig:lint [path] [--json]` | Compiles every template. |
| `twig:clear` | Deletes the compiled Twig cache, nothing else. |
| `doctor [--json]` | Checks the conventions in these guidelines that code can check (blocks, ajax, templates, `inc/`, `app/`, assets, config, `\|raw` count, deprecated Gaffer API, stale mentions in the theme's `.ai/`). |
| `doctor --baseline` / `--prune` / `--all` | `--baseline` records the current warnings in `doctor-baseline.json` (committed); later runs show only new ones. `--prune` drops entries that no longer occur (use the same `--wp` as the baseline). `--all` shows everything. Errors are never baselined; never baseline a warning you just caused. |
| `doctor --wp [--no-render]` | Also what needs WordPress (registered blocks, stored content vs fields, ACF JSON vs database, menu locations, WooCommerce template names, forms, the MCP server) and renders every page plus a sample of other URLs with strict variables. |
| `forms:show [name]` | Gravity Forms' forms and fields as the theme sees them (Admin Field Labels, required, in the template). |
| `ai:install` / `ai:update` | Writes these AI guidelines and skills for the selected agents (`config/ai.php`) and removes those of deselected agents. The generated files are gitignored. |
| `migrate:*` | Rename or remove a block or field in stored content, and undo it (dry run unless `--run`; see the `gaffer-migrate` skill). |
| `render:snapshot` / `render:diff` | Save rendered pages, then show what a change did to them (see the `gaffer-migrate` skill). |
| `ai:clear` | Removes the generated AI files (keeps `.ai/`, `config/ai.php` and hand-written content). |
