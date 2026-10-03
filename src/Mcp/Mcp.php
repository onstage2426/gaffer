<?php

declare(strict_types=1);

namespace Gaffer\Mcp;

use Gaffer\Mcp\Tools\BlockUsage;
use Gaffer\Mcp\Tools\Doctor;
use Gaffer\Mcp\Tools\Forms;
use Gaffer\Mcp\Tools\LastErrors;
use Gaffer\Mcp\Tools\Render;

/**
 * Gaffer's MCP server for theme development: its tools are abilities
 * (`gaffer/*`, WordPress's Abilities API) served by the MCP Adapter plugin as the
 * server `gaffer`, over STDIO through WP-CLI only (no HTTP endpoint). Only when
 * the plugin is active and the site isn't production.
 */
final class Mcp
{
    public const string SERVER = 'gaffer';

    public static function enabled(): bool
    {
        return class_exists('WP\MCP\Core\McpAdapter') && \wp_get_environment_type() !== 'production';
    }

    public static function register(): void
    {
        if (!self::enabled()) {
            return;
        }

        add_action('wp_abilities_api_categories_init', static function (): void {
            \wp_register_ability_category('gaffer', [
                'label' => 'Gaffer',
                'description' => 'Theme development tools from the Gaffer framework.',
            ]);
        });

        add_action('wp_abilities_api_init', static function (): void {
            foreach (self::tools() as $tool) {
                \wp_register_ability("gaffer/{$tool->name()}", [
                    'label' => $tool->label(),
                    'description' => $tool->description(),
                    'category' => 'gaffer',
                    'input_schema' => $tool->input_schema(),
                    'execute_callback' => $tool->run(...),
                    // WP-CLI: whoever runs it can already do anything on this server (no --user needed).
                    'permission_callback' => static fn(): bool => (defined('WP_CLI') && WP_CLI) || \current_user_can('edit_theme_options'),
                    'meta' => ['annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
                ]);
            }
        });

        add_action('mcp_adapter_init', static function (\WP\MCP\Core\McpAdapter $adapter): void {
            $adapter->create_server(
                self::SERVER,
                'gaffer',
                'mcp',
                'Gaffer',
                'Development tools for this Gaffer theme: block usage, doctor, rendering pages, recent errors' . (class_exists('GFAPI') ? ', Gravity Forms forms' : '') . '.',
                '1.0.0',
                [], // no transport: STDIO through `wp mcp-adapter serve` only, no REST endpoint
                null,
                null,
                array_map(static fn(Tool $tool): string => "gaffer/{$tool->name()}", self::tools()),
            );
        });
    }

    /**
     * How an agent starts the server, the same on every machine: WP-CLI finds
     * WordPress from the theme directory the agent runs it in.
     *
     * @return array{command: string, args: list<string>}
     */
    public static function launch(): array
    {
        return ['command' => 'wp', 'args' => ['mcp-adapter', 'serve', '--server=' . self::SERVER]];
    }

    /** @return list<Tool> */
    public static function tools(): array
    {
        return array_values(array_filter(
            [new BlockUsage(), new Doctor(), new Render(), new LastErrors(), new Forms()],
            static fn(Tool $tool): bool => $tool->available(),
        ));
    }
}
