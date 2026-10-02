## Config (`config/`)

Each `config/{file}.php` returns an array; the file name is the top-level key:
`Gaffer\Config::get('theme.cache')`, `{{ config('turnstile.site_key') }}` in Twig.

- **Values only.** The keys Gaffer reads are documented once, in its stubs
  (`vendor/onstage2426/gaffer/config/`); `php gaffer config:show` and the
  reference below show those descriptions. Don't copy the stub comments into
  the theme: copies go stale.
- **The theme's own keys** (its own files, e.g. company details) get a one-line
  `// comment` above each top-level key; the reference below shows it.
- **Nothing that differs per server or checkout.** Theme config is committed
  and the same everywhere:
  - server settings are constants in `wp-config.php`: `WP_DEBUG` (also turns
    on Twig debug and `strict_variables`), `WP_ENVIRONMENT_TYPE`,
    `GAFFER_STORAGE`, secrets like `TURNSTILE_SECRET_KEY`;
  - developer settings go in the theme's `.env` (not committed; keep
    `.env.example` up to date): `SITE_URL` for the CLI and Vite's dev server.
- `config:show` and `doctor` flag unknown keys (with a did-you-mean) and keys
  that moved out of config (`theme.debug`, `console.url`).
