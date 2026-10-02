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

    /**
     * Removes a field's values and references (with everything below it: a repeater's rows).
     *
     * @param array<mixed> $data
     * @param list<string> $path
     * @return array{data: array<mixed>, changes: int}
     */
    public static function remove_field(array $data, string $prefix, array $path): array
    {
        $old = $prefix . '__' . implode('__', $path);
        $drop = [];
        foreach ($data as $name => $key) {
            if (str_starts_with((string) $name, '_') && is_string($key) && ($key === $old || str_starts_with($key, "{$old}__"))) {
                $drop[substr((string) $name, 1)] = true;
            }
        }

        $kept = [];
        foreach ($data as $name => $value) {
            $name = (string) $name;
            if (!isset($drop[str_starts_with($name, '_') ? substr($name, 1) : $name])) {
                $kept[$name] = $value;
            }
        }

        return ['data' => $kept, 'changes' => count($drop)];
    }

    /**
     * Removes every block named $name (also nested ones) from parse_blocks() output, with
     * the blank line it leaves. A block that contains other blocks is kept and reported.
     *
     * @param array<mixed> $blocks
     * @return array{blocks: list<array<string, mixed>>, removed: int, problems: list<string>}
     */
    public static function remove_blocks(array $blocks, string $name): array
    {
        $result = [];
        $removed = 0;
        $problems = [];
        $blocks = array_values(array_filter($blocks, 'is_array'));

        for ($i = 0, $count = count($blocks); $i < $count; $i++) {
            $block = $blocks[$i];

            if ($block['blockName'] === $name) {
                if (!empty($block['innerBlocks'])) {
                    $problems[] = "{$name} contains other blocks: move or delete those first";
                    $result[] = $block;
                    continue;
                }
                $removed++;
                if (isset($blocks[$i + 1]) && self::blank($blocks[$i + 1])) {
                    $i++; // the blank line after it
                } elseif ($result !== [] && self::blank($result[array_key_last($result)])) {
                    array_pop($result); // or the one before it, when it was last
                }
                continue;
            }

            if (!empty($block['innerBlocks']) && is_array($block['innerBlocks'])) {
                $inner = self::remove_inner($block, $name);
                $block = $inner['block'];
                $removed += $inner['removed'];
                array_push($problems, ...$inner['problems']);
            }
            $result[] = $block;
        }

        return ['blocks' => $result, 'removed' => $removed, 'problems' => array_values(array_unique($problems))];
    }

    /**
     * remove_blocks() inside a parent: its innerContent has a null where each inner block
     * goes, between the parent's own HTML.
     *
     * @param array<string, mixed> $block
     * @return array{block: array<string, mixed>, removed: int, problems: list<string>}
     */
    private static function remove_inner(array $block, string $name): array
    {
        $inner = is_array($block['innerBlocks']) ? array_values($block['innerBlocks']) : [];
        $kept = [];
        $drop = [];
        $removed = 0;
        $problems = [];

        foreach ($inner as $j => $child) {
            if (is_array($child) && $child['blockName'] === $name && empty($child['innerBlocks'])) {
                $drop[$j] = true;
                $removed++;
                continue;
            }
            if (is_array($child) && $child['blockName'] === $name) {
                $problems[] = "{$name} contains other blocks: move or delete those first";
            }
            if (is_array($child) && !empty($child['innerBlocks'])) {
                $nested = self::remove_inner($child, $name);
                $child = $nested['block'];
                $removed += $nested['removed'];
                array_push($problems, ...$nested['problems']);
            }
            $kept[] = $child;
        }

        if ($drop !== []) {
            $content = is_array($block['innerContent'] ?? null) ? array_values($block['innerContent']) : [];
            $result = [];
            $slot = 0;
            $skip_next_blank = false;
            foreach ($content as $piece) {
                if ($piece === null) {
                    if (isset($drop[$slot++])) {
                        if (is_string(end($result)) && trim(end($result)) === '' && $result !== []) {
                            array_pop($result);
                        } else {
                            $skip_next_blank = true;
                        }
                        continue;
                    }
                } elseif ($skip_next_blank && trim($piece) === '') {
                    $skip_next_blank = false;
                    continue;
                }
                $skip_next_blank = false;
                $result[] = $piece;
            }
            $block['innerContent'] = $result;
            $block['innerHTML'] = implode('', array_filter($result, 'is_string'));
        }
        $block['innerBlocks'] = $kept;

        return ['block' => $block, 'removed' => $removed, 'problems' => $problems];
    }

    /** A top-level piece of nothing but whitespace between blocks. */
    private static function blank(mixed $block): bool
    {
        return is_array($block) && $block['blockName'] === null && trim((string) ($block['innerHTML'] ?? '')) === '';
    }
}
