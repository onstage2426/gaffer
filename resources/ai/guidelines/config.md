## Config (`config/`)

Each `config/{file}.php` returns an array; the file name is the top-level key:
`Gaffer\Config::get('theme.debug')`, `{{ config('turnstile.site_key') }}` in Twig.
The keys Gaffer reads are documented (commented out) in its stubs in
`vendor/onstage2426/gaffer/config/`; `php gaffer config:show` flags unknown
keys with a did-you-mean. The theme can add its own files (e.g. company
details). The theme's current config files and keys are in the reference below.

Server-specific settings never go in theme config (it's committed and the same
everywhere): they are constants in `wp-config.php` (`WP_ENVIRONMENT_TYPE`,
`GAFFER_STORAGE`, secrets like `TURNSTILE_SECRET_KEY`).
