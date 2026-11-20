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
        $servers = $config->{$key} ?? null;
        if (!$servers instanceof \stdClass) {
            $servers = new \stdClass();
        }
        $servers->{self::SERVER} = (object) ['type' => 'stdio', ...$launch];
        $config->{$key} = $servers;
        file_put_contents($file, self::encode($config));

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
        $servers = $config === null ? null : ($config->{$key} ?? null);
        if (!$servers instanceof \stdClass || !property_exists($servers, self::SERVER)) {
            return null;
        }
        unset($servers->{self::SERVER});
        if (get_object_vars($servers) === []) {
            unset($config->{$key});
        }

        return self::store($file, get_object_vars($config) === [] ? null : self::encode($config));
    }

    /**
     * The content without Gaffer's table: `[<key>.gaffer]` and its sub tables (`[<key>.gaffer.env]`),
     * keys quoted or not, plus an inline `gaffer = {...}` in `[<key>]` and a top-level
     * `<key>.gaffer...` line. Comments right above the next table stay: they're that table's.
     */
    public static function without_toml_table(string $content, string $key): string
    {
        $name = static fn(string $part): string => '(?:' . preg_quote($part, '/') . '|"' . preg_quote($part, '/') . '"|\'' . preg_quote($part, '/') . '\')';
        $ours = '/^\s*\[\s*' . $name($key) . '\s*\.\s*' . $name(self::SERVER) . '\s*(?:\.[^\]]*)?\]\s*(?:#.*)?$/';
        $parent = '/^\s*\[\s*' . $name($key) . '\s*\]\s*(?:#.*)?$/';
        $inline = '/^\s*' . $name(self::SERVER) . '\s*=/';
        $dotted = '/^\s*' . $name($key) . '\s*\.\s*' . $name(self::SERVER) . '\s*[.=]/';

        $out = '';
        $held = null;       // the lines of Gaffer's table being dropped
        $in_parent = false; // inside [<key>]
        $top = true;        // before the first table
        foreach (preg_split('/(?<=\n)/', $content, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $line) {
            $bare = rtrim($line, "\r\n");
            if (preg_match('/^\s*\[/', $bare)) {
                $top = false;
                if ($held !== null) {
                    $out .= self::trailing_comments($held);
                    $held = null;
                }
                if (preg_match($ours, $bare)) {
                    $held = [];
                    continue;
                }
                $in_parent = (bool) preg_match($parent, $bare);
            }
            if ($held !== null) {
                $held[] = $line;
            } elseif (!(($in_parent && preg_match($inline, $bare)) || ($top && preg_match($dotted, $bare)))) {
                $out .= $line;
            }
        }

        return $out;
    }

    /**
     * The comment lines at the end of a dropped table (with the blank lines between them),
     * which belong to the table that follows.
     *
     * @param list<string> $lines
     */
    private static function trailing_comments(array $lines): string
    {
        $tail = [];
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $trimmed = trim($lines[$i]);
            if ($trimmed !== '' && !str_starts_with($trimmed, '#')) {
                break;
            }
            array_unshift($tail, $lines[$i]);
        }
        while ($tail !== [] && trim($tail[0]) === '') {
            array_shift($tail);
        }

        return implode('', $tail);
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
     * The file's config as objects (so `{}` stays `{}`): empty when there's no file, null when
     * it isn't a JSON object.
     */
    private static function read_json(string $file): ?\stdClass
    {
        if (!is_file($file)) {
            return new \stdClass();
        }
        $config = json_decode((string) file_get_contents($file));

        return $config instanceof \stdClass ? $config : null;
    }

    private static function encode(\stdClass $config): string
    {
        return json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }

    private static function toml_string(string $value): string
    {
        return '"' . strtr($value, ['\\' => '\\\\', '"' => '\\"', "\n" => '\n', "\r" => '\r', "\t" => '\t']) . '"';
    }
}
