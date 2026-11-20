## CLI (`php gaffer`)

Run from the theme directory; commands that need WordPress load it themselves
(the site from `SITE_URL` in `.env`, set by `php gaffer init`). `php gaffer list`
shows every command, `php gaffer <command> --help` its options.

| Command | For |
|---|---|
| `twig:lint`, `doctor [--wp]` | The checks (see Check your work). `--json` for one object. |
| `doctor --baseline` / `--prune` / `--all` | Record the current warnings in `doctor-baseline.json` (committed) so later runs show only new ones. Errors are never baselined; a warning you decided to keep goes in with the reason in the commit. |
| `twig:trace <template>` | Who renders or includes a template, what it includes and which variables it reads. |
| `config:show [key]` | The theme's config and what each key means. |
| `forms:show [name]` | Gravity Forms' forms and fields as the theme sees them. |
| `ajax:call <Action> key=value … [--session=]`, `ajax:end <session>` | Call an ajax action over HTTP as a visitor (the `gaffer-ajax` skill). |
| `render:screenshot`, `render:compare`, `render:styles` | The page in a headless browser (the `gaffer-browser` skill). |
| `render:snapshot`, `render:diff` | Rendered pages before and after a change (the `gaffer-migrate` skill). |
| `migrate:*` | Change stored content: rename, remove, set, rollback (the `gaffer-migrate` skill). |
| `ai:update` | Regenerate these guidelines and skills (gitignored; `composer update` runs it). |

Exit codes: 0 fine, 1 errors (or refused, or different), 2 misuse or
trouble; `migrate:*` 2 = written but the read-back failed (roll back).

- WP-CLI boots WordPress on every call (seconds with WooCommerce): many writes
  of one kind go in one `wp eval-file` script.
- A URL changed across content: `wp search-replace '/old/' '/new/' $(wp db prefix)posts $(wp db prefix)postmeta --dry-run`
  (block data stores URLs as written), then `doctor --wp`.
