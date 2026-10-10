<?php

declare(strict_types=1);

namespace Gaffer\Mcp;

use Gaffer\Config;
use Gaffer\Console\Env;
use Gaffer\Mcp\Tools\BlockUsage;
use Gaffer\Mcp\Tools\Doctor;
use Gaffer\Mcp\Tools\Forms;
use Gaffer\Mcp\Tools\LastErrors;
use Gaffer\Mcp\Tools\Render;
use Gaffer\Paths;
use RuntimeException;

/**
 * Gaffer's MCP server for theme development: its tools are abilities
 * (`gaffer/*`, WordPress's Abilities API) served by the MCP Adapter plugin as the
 * server `gaffer`, over STDIO through WP-CLI only (no HTTP endpoint). Only when
 * the plugin is active, and on production only with ai.mcp_production (a theme
 * migration on a live site).
 */
final class Mcp
{
    public const string SERVER = 'gaffer';

    public static function enabled(): bool
    {
        return class_exists('WP\MCP\Core\McpAdapter')
            && (\wp_get_environment_type() !== 'production' || Config::get('ai.mcp_production') === true);
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
     * How an agent starts the server: WP-CLI finds WordPress from the theme
     * directory the agent runs it in, and on a multisite the site from SITE_URL in
     * the theme's .env (without it, WP-CLI loads the main site). A `wp` that is a PHP
     * file (the phar, Composer's bin script) runs with the PHP running this, which is
     * the one the site works with: its `#!/usr/bin/env php` may find another version.
     *
     * @return array{command: string, args: list<string>}
     */
    public static function launch(): array
    {
        $url = Env::get('SITE_URL');
        $args = ['mcp-adapter', 'serve', '--server=' . self::SERVER, ...($url !== null ? ["--url={$url}"] : [])];
        $wp = self::wp_cli();

        return $wp !== null ? ['command' => PHP_BINARY, 'args' => [$wp, ...$args]] : ['command' => 'wp', 'args' => $args];
    }

    /** The first `wp` on the PATH when it's a PHP file, resolved; null otherwise. */
    private static function wp_cli(): ?string
    {
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
            $file = $dir !== '' ? realpath("{$dir}/wp") : false;
            if ($file === false || !is_file($file) || !is_executable($file)) {
                continue;
            }
            $head = (string) file_get_contents($file, false, null, 0, 200);

            return str_starts_with($head, '<?php') || preg_match('/^#![^\n]*\bphp\b/', $head) === 1 ? $file : null;
        }

        return null;
    }

    /**
     * Starts the server the way an agent does (launch(), from the theme directory)
     * and asks for its tools over the protocol: the names it serves.
     *
     * @return list<string>
     * @throws RuntimeException when it doesn't start or doesn't answer
     */
    public static function served_tools(): array
    {
        $launch = self::launch();
        $requests = implode("\n", array_map(json_encode(...), [
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-11-25', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'gaffer-doctor', 'version' => '1']]],
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'],
        ])) . "\n";

        $process = proc_open([$launch['command'], ...$launch['args']], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, Paths::base());
        if ($process === false) {
            throw new RuntimeException("Could not start `{$launch['command']}` (not on the PATH?).");
        }
        fwrite($pipes[0], $requests);
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        foreach (explode("\n", $stdout) as $line) {
            $message = json_decode($line, true);
            if (is_array($message) && ($message['id'] ?? null) === 2 && is_array($message['result']['tools'] ?? null)) {
                return array_values(array_map(static fn(array $tool): string => (string) ($tool['name'] ?? ''), $message['result']['tools']));
            }
        }

        // The last lines of stderr say why (command not found, unknown server, a PHP fatal); WordPress notices come first.
        $why = trim(implode("\n", array_slice(array_filter(explode("\n", $stderr), static fn(string $l): bool => trim($l) !== ''), -3)));
        throw new RuntimeException("`{$launch['command']} " . implode(' ', $launch['args']) . "` (exit {$exit}) didn't list its tools" . ($why !== '' ? ": {$why}" : '.'));
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
