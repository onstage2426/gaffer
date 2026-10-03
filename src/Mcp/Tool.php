<?php

declare(strict_types=1);

namespace Gaffer\Mcp;

use WP_Error;

/**
 * One tool on Gaffer's MCP server, registered as the ability `gaffer/{name}`.
 * Tools only read: anything that writes stays in the CLI.
 */
interface Tool
{
    /** Kebab-case: the ability is `gaffer/{name}`, the MCP tool `gaffer-{name}`. */
    public function name(): string;

    public function label(): string;

    /** What the agent reads to decide when to call it: say what it returns and when to use it. */
    public function description(): string;

    /** @return array<string, mixed> JSON Schema of the input object */
    public function input_schema(): array;

    /**
     * @param array<string, mixed> $input validated against input_schema()
     * @return array<string, mixed>|WP_Error a WP_Error becomes a tool error the agent can read
     */
    public function run(array $input): array|WP_Error;

    /** Whether the site can offer it (e.g. its plugin is active). */
    public function available(): bool;
}
