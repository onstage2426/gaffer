<?php

declare(strict_types=1);

namespace Gaffer\Console\Migrate;

use Closure;

/**
 * Rewrites the data of ACF blocks as parse_blocks() returns them. ACF stores every
 * value twice in a block's "data" attribute: the value under the field's name
 * ("vragen_0_vraag") and a reference to its field under "_" + the name
 * ("_vragen_0_vraag": "field_content-faq__vragen__vraag"). Pure PHP, no WordPress.
 */
final class BlockData
{
    /**
     * Applies $fn to every block, inner blocks first.
     *
     * @param array<mixed> $blocks
     * @param Closure(array<string, mixed>): array<string, mixed> $fn
     * @return list<array<string, mixed>>
     */
    public static function walk(array $blocks, Closure $fn): array
    {
        $walked = [];
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            $block['innerBlocks'] = self::walk(is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : [], $fn);
            $walked[] = $fn($block);
        }

        return $walked;
    }

    /**
     * Why this data can't be rewritten safely; [] when every value has a reference
     * to a field of this block (key prefix "field_{block}") and every reference a value.
     *
     * @param array<mixed> $data
     * @return list<string>
     */
    public static function problems(array $data, string $prefix): array
    {
        $problems = [];

        foreach ($data as $name => $value) {
            $name = (string) $name;
            if (str_starts_with($name, 'field_')) {
                $problems[] = "a value is stored by field key (\"{$name}\") instead of by name";
            } elseif (str_starts_with($name, '_')) {
                if (!array_key_exists(substr($name, 1), $data)) {
                    $problems[] = "\"{$name}\" has no value next to it";
                } elseif (!is_string($value) || !str_starts_with($value, "{$prefix}__")) {
                    $problems[] = "\"{$name}\" points to " . json_encode($value) . ", not to a field of this block";
                }
            } elseif (!array_key_exists("_{$name}", $data)) {
                $problems[] = "\"{$name}\" has no field reference (\"_{$name}\")";
            }
        }

        return $problems;
    }

    /**
     * Renames values and their references. $rename gets each value's name and field key
     * and returns the new [name, key], null to leave it, or a string describing a problem.
     *
     * @param array<mixed> $data
     * @param Closure(string, string): (array{string, string}|string|null) $rename
     * @return array{data: array<mixed>, changes: int, problems: list<string>}
     */
    public static function rename(array $data, Closure $rename): array
    {
        $renamed = [];
        $problems = [];

        foreach ($data as $name => $key) {
            $name = (string) $name;
            if (!str_starts_with($name, '_') || !is_string($key)) {
                continue;
            }
            $result = $rename(substr($name, 1), $key);
            if (is_string($result)) {
                $problems[] = $result;
            } elseif ($result !== null && $result !== [substr($name, 1), $key]) {
                $renamed[substr($name, 1)] = $result;
            }
        }

        $targets = [];
        foreach ($renamed as $old => [$new]) {
            if ($new !== $old && array_key_exists($new, $data) && !isset($renamed[$new])) {
                $problems[] = "\"{$old}\" would become \"{$new}\", which already has a value";
            }
            if (isset($targets[$new])) {
                $problems[] = "\"{$targets[$new]}\" and \"{$old}\" would both become \"{$new}\"";
            }
            $targets[$new] = $old;
        }

        if ($problems !== []) {
            return ['data' => $data, 'changes' => 0, 'problems' => $problems];
        }

        $result = [];
        foreach ($data as $name => $value) {
            $name = (string) $name;
            $reference = str_starts_with($name, '_');
            $base = $reference ? substr($name, 1) : $name;
            if (!isset($renamed[$base])) {
                $result[$name] = $value;
                continue;
            }
            [$new_name, $new_key] = $renamed[$base];
            $result[$reference ? "_{$new_name}" : $new_name] = $reference ? $new_key : $value;
        }

        return ['data' => $result, 'changes' => count($renamed), 'problems' => []];
    }

    /**
     * For rename(): moves every reference from one key prefix to another (a renamed block).
     *
     * @return Closure(string, string): (array{string, string}|null)
     */
    public static function block_renamer(string $from_prefix, string $to_prefix): Closure
    {
        return static fn(string $name, string $key): ?array => str_starts_with($key, "{$from_prefix}__")
            ? [$name, $to_prefix . substr($key, strlen($from_prefix))]
            : null;
    }

    /**
     * For rename(): renames one field, given as its path of names (["vragen", "vraag"]), in
     * the value names ("vragen_0_vraag") and keys of that field and everything below it.
     *
     * @param list<string> $path
     * @return Closure(string, string): (array{string, string}|string|null)
     */
    public static function field_renamer(string $prefix, array $path, string $to): Closure
    {
        $old = $prefix . '__' . implode('__', $path);
        $depth = count($path) - 1;

        return static function (string $name, string $key) use ($prefix, $old, $depth, $to): array|string|null {
            if ($key !== $old && !str_starts_with($key, "{$old}__")) {
                return null;
            }

            // The value name is the key's names joined by "_" (groups) or "_{row}_" (repeaters).
            $segments = explode('__', substr($key, strlen($prefix) + 2));
            $parts = [];
            foreach ($segments as $i => $segment) {
                $parts[] = $i === $depth ? '(' . preg_quote($segment, '/') . ')' : preg_quote($segment, '/');
            }
            if (!preg_match('/^' . implode('(?:_\d+)?_', $parts) . '$/', $name, $m, PREG_OFFSET_CAPTURE)) {
                return "\"{$name}\" doesn't match its field key {$key}";
            }

            $segments[$depth] = $to;

            return [
                substr($name, 0, $m[1][1]) . $to . substr($name, $m[1][1] + strlen($m[1][0])),
                $prefix . '__' . implode('__', $segments),
            ];
        };
    }
}
