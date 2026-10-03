<?php

declare(strict_types=1);

namespace Gaffer\Ai;

use Gaffer\Mcp\Mcp;

/**
 * Gaffer's server entry in an agent's project MCP config: JSON ({"<key>": {"gaffer": {...}}})
 * or TOML ([<key>.gaffer]), by file extension. Every other server and setting in
 * the file is kept; TOML is edited as text (only Gaffer's own table is touched).
 */
final class McpConfig
{
    private const string SERVER = Mcp::SERVER;

    /**
     * @param array{command: string, args: list<string>} $launch
     * @return bool false when the file isn't valid JSON (left alone)
     */
    public static function write(string $file, string $key, array $launch): bool
    {
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0755, true);
        }

        if (str_ends_with($file, '.toml')) {
            $table = '[' . $key . '.' . self::SERVER . "]\n"
                . 'command = ' . self::toml_string($launch['command']) . "\n"
                . 'args = [' . implode(', ', array_map(self::toml_string(...), $launch['args'])) . ']';
            $rest = rtrim(self::without_toml_table(is_file($file) ? (string) file_get_contents($file) : '', $key));
            file_put_contents($file, ($rest === '' ? '' : "{$rest}\n\n") . $table . "\n");
            return true;
        }

        $config = self::read_json($file);
        if ($config === null) {
            return false;
        }
        $config[$key] = [...(is_array($config[$key] ?? null) ? $config[$key] : []), self::SERVER => ['type' => 'stdio', ...$launch]];
        file_put_contents($file, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

        return true;
    }

    /**
     * Removes Gaffer's entry; deletes the file when nothing else is left.
     *
     * @return 'removed'|'updated'|null removed the file, kept the rest, or there was no entry
     */
    public static function remove(string $file, string $key): ?string
    {
        if (!is_file($file)) {
            return null;
        }

        if (str_ends_with($file, '.toml')) {
            $content = (string) file_get_contents($file);
            $rest = self::without_toml_table($content, $key);
            if ($rest === $content) {
                return null;
            }
            return self::store($file, trim($rest) === '' ? null : rtrim($rest) . "\n");
        }

        $config = self::read_json($file);
        if (!is_array($config[$key] ?? null) || !isset($config[$key][self::SERVER])) {
            return null;
        }
        unset($config[$key][self::SERVER]);
        if ($config[$key] === []) {
            unset($config[$key]);
        }

        return self::store($file, $config === [] ? null : json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }

    /** The content without Gaffer's table (and its sub tables like [<key>.gaffer.env]). */
    public static function without_toml_table(string $content, string $key): string
    {
        $header = preg_quote($key . '.' . self::SERVER, '/');

        return (string) preg_replace('/^\[' . $header . '(\.[^\]]*)?\][^\n]*\n(?:(?!\[)[^\n]*\n?)*/m', '', $content);
    }

    /** @return 'removed'|'updated' */
    private static function store(string $file, ?string $content): string
    {
        if ($content === null) {
            unlink($file);
            // An agent directory we created (.codex/) goes when it's empty.
            $dir = dirname($file);
            if ((scandir($dir) ?: []) === ['.', '..']) {
                rmdir($dir);
            }
            return 'removed';
        }
        file_put_contents($file, $content);

        return 'updated';
    }

    /**
     * The file's config: empty when there's no file, null when it isn't valid JSON.
     *
     * @return array<string, mixed>|null
     */
    private static function read_json(string $file): ?array
    {
        if (!is_file($file)) {
            return [];
        }
        $config = json_decode((string) file_get_contents($file), true);

        return is_array($config) ? $config : null;
    }

    private static function toml_string(string $value): string
    {
        return '"' . strtr($value, ['\\' => '\\\\', '"' => '\\"', "\n" => '\n', "\r" => '\r', "\t" => '\t']) . '"';
    }
}
