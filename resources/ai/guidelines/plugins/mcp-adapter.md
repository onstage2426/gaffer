## MCP server (`gaffer`)

The MCP Adapter plugin is active, so Gaffer serves read-only development tools
as the MCP server `gaffer` (STDIO, written to each agent's MCP config by
`ai:update`; not on production unless `ai.mcp_production` is `true`). Prefer
its tools over ad-hoc scripts: `gaffer-block-usage` (where a block is used,
with its stored values: before renaming or removing a block or field),
`gaffer-doctor`, `gaffer-render` (one URL with strict variables: errors,
timing, the HTML of a CSS `selector`; `user`/`cart` for logged-in and cart
pages, `theme` for a staged copy), `gaffer-last-errors` (look here before
guessing why something broke) and `gaffer-forms`.
