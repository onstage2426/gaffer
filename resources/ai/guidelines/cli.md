## CLI (`php gaffer`)

Run from the theme directory. Commands that need WordPress load it themselves
(site URL from `SITE_URL` in the theme's `.env`, or `--url`).

| Command | What it does |
|---|---|
| `config:show [key] [--json]` | The theme's config; flags keys Gaffer doesn't know. |
| `twig:lint [path] [--json]` | Compiles every template. |
| `twig:clear` | Deletes the compiled Twig cache, nothing else. |
| `doctor [--json]` | Conventions: blocks, ACF location rules, ajax classes and `run()` signatures, config keys, hardcoded IDs and upload paths, `\|raw` count, templates and includes, HTML in PHP, WordPress globals assigned in template files, functions/classes declared in `inc/`, a missing or stale Vite build and unknown `Vite::` entries, method names in `app/`, Twig functions that look content up, `.get_*()` calls in templates, theme guidelines/skills mentioning files, classes or blocks that don't exist, theme guidelines/skills that replace Gaffer's, and theme code using deprecated Gaffer API. |
| `doctor --wp [--no-render]` | Also: registered/orphaned blocks, ACF JSON vs database, unassigned menu locations, ACF field types, WooCommerce template names, form templates vs Gravity Forms, the MCP server `gaffer` (when the MCP Adapter is active), and renders every page plus a sample of other URLs with strict variables. |
| `forms:show [name]` | Gravity Forms' forms and fields as the theme sees them (Admin Field Labels, required, in the template). |
| `ai:install` / `ai:update` | Writes these AI guidelines and skills for the selected agents (`config/ai.php`) and removes those of deselected agents. The generated files are gitignored. |
| `migrate:block` / `migrate:field` / `migrate:remove-block` / `migrate:remove-field` / `migrate:fields` / `migrate:rollback` | Rename or remove a block or field in stored content, move a block's data to its `fields.php` keys, or undo that (dry run unless `--run`; see ACF blocks). |
| `ai:clear` | Removes the generated AI files (keeps `.ai/`, `config/ai.php` and hand-written content). |
