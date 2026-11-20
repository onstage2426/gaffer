## Config (`config/`)

Each `config/{file}.php` returns an array, the file name is the top-level key:
`Gaffer\Config::get('theme.cache')`, `{{ config('turnstile.site_key') }}`.

- **Values only.** Gaffer's keys are documented in its stubs
  (`vendor/onstage2426/gaffer/config/`, shown by `config:show`): don't copy
  their comments. The theme's own keys get a one-line `// comment` above each.
- **The same on every server and database:** no IDs (what the client picks is
  an ACF options field), nothing per server. Server settings are constants in
  `wp-config.php` (`WP_DEBUG`, which also turns on Twig's `strict_variables`;
  `WP_ENVIRONMENT_TYPE`, `GAFFER_STORAGE`, secrets). Per checkout, the theme's
  `.env` (not committed; keep `.env.example` current): `SITE_URL` (the CLI and
  Vite), optionally `PHP_ERROR_LOG` (where the web server's PHP logs, for
  `gaffer-last-errors`), `PLAYWRIGHT_WS_ENDPOINT` and `DESIGN_COOKIE` (the
  `gaffer-browser` skill).
