## MCP server (`gaffer`)

The MCP Adapter plugin is active, so Gaffer serves read-only development tools
as the MCP server `gaffer` (abilities `gaffer/*`). `php gaffer ai:update` adds it
to the agent's MCP config (`.mcp.json`, gitignored: it holds this machine's
WordPress path); it runs `wp mcp-adapter serve` as the site's first
administrator, over STDIO only. It is never served on production.

Prefer these tools over ad-hoc scripts:

- `gaffer-block-usage`: where a block is used (posts, widgets, URLs), with
  `values` the stored field data. Before renaming or removing a block or field,
  and to find a page to test a block on.
- `gaffer-doctor`: `doctor --wp` as structured findings.
- `gaffer-render`: one URL in a fresh WordPress with strict variables: status,
  HTTP code, redirect, error with file and line, theme notices, and the HTML
  (pass a CSS `selector`; whole pages are large).
- `gaffer-last-errors`: the recent PHP error log (repeats merged) and the
  theme's `storage/logs/`. Look here before guessing why something broke.
- `gaffer-forms` (with Gravity Forms): forms and their fields, as
  `forms:show`.

`render` and `doctor` render as a logged-out visitor without cookies: test a
cart or a logged-in page through the web server instead.
