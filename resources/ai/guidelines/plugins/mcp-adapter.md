## MCP server (`gaffer`)

The MCP Adapter plugin is active, so Gaffer serves read-only tools as the MCP
server `gaffer` (written to each agent's config by `ai:update`; not on
production). Prefer them over ad-hoc scripts: `gaffer-last-errors` (look here
before guessing why something broke), `gaffer-render` (one front-end URL with
strict variables: errors, timing, the SEO head as printed, a selector's HTML;
`user`/`cart`), `gaffer-block-usage` (where a block is stored, with values),
`gaffer-doctor`, `gaffer-forms`, `gaffer-search`, and the browser tools
(`gaffer-screenshot`, `gaffer-compare`, `gaffer-styles`). Ajax actions write,
so they're tested with `php gaffer ajax:call`, not a tool.
