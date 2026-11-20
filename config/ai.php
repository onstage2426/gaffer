<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | AI Agents
    |--------------------------------------------------------------------------
    |
    | The coding agents this theme writes guidelines and skills for, set by
    | `php gaffer ai:install`: "claude", "codex", "grok". `php gaffer ai:update`
    | writes AGENTS.md (read by every agent) plus each agent's own files. All
    | output is generated and gitignored; the sources are Gaffer's guidelines
    | and the theme's .ai/guidelines/ and .ai/skills/.
    |
    */

    // 'agents' => ['claude'],

    /*
    |--------------------------------------------------------------------------
    | MCP On Production
    |--------------------------------------------------------------------------
    |
    | Gaffer's MCP server (with the MCP Adapter plugin active) is off on
    | production (WP_ENVIRONMENT_TYPE). true serves it there too, e.g. while
    | migrating a live site's theme. Its tools only read, over STDIO through
    | WP-CLI: whoever can start them already has a shell on the server.
    | `doctor --wp` reports it while it's on. Run `php gaffer ai:update`
    | after changing it.
    |
    */

    // 'mcp_production' => false,

];
