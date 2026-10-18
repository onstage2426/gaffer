<?php

declare(strict_types=1);

namespace Gaffer;

use LogicException;

/**
 * ACF field groups in code for everything that isn't a block:
 * fields/{param}/{value}.php returns a list of ACF field arrays, where {param}
 * is the ACF location param (options_page, post_type, taxonomy) and {value}
 * what it targets (fields/post_type/review.php). One group per target; sections
 * are tabs. Keys are derived from the target and the names
 * (field_post_type_review__vragen__vraag), like block fields, so the keys
 * stored next to every value never change and nothing is synced through the
 * database.
 *
 * @internal
 */
final class FieldGroups
{
    /** The ACF location params a fields/ directory can be named after. */
    public const array PARAMS = ['options_page', 'post_type', 'taxonomy'];

    private const array TITLES = ['options_page' => 'Options', 'post_type' => 'Post type', 'taxonomy' => 'Taxonomy'];

    public static function register(): void
    {
        if (!function_exists('acf_add_local_field_group')) {
            return;
        }
        foreach (array_keys(self::targets()) as $target) {
            acf_add_local_field_group(self::group($target));
        }
    }

    /**
     * Every fields/{param}/{value}.php of a known param, as target ("post_type/review") => file.
     *
     * @return array<string, string>
     */
    public static function targets(): array
    {
        $targets = [];
        foreach (self::PARAMS as $param) {
            foreach (glob(Paths::fields() . "/{$param}/*.php") ?: [] as $file) {
                $targets["{$param}/" . basename($file, '.php')] = $file;
            }
        }
        ksort($targets);

        return $targets;
    }

    /**
     * Directories in fields/ that aren't a known location param (a typo, or a location
     * Gaffer doesn't support yet).
     *
     * @return list<string>
     */
    public static function unknown_dirs(): array
    {
        $dirs = array_map(basename(...), glob(Paths::fields() . '/*', GLOB_ONLYDIR) ?: []);

        return array_values(array_diff($dirs, self::PARAMS));
    }

    /**
     * The local field group for fields/{target}.php.
     *
     * @return array<string, mixed>
     */
    public static function group(string $target): array
    {
        [$param, $value] = self::split($target);
        $file = "fields/{$target}.php";
        $fields = require Paths::base($file);
        if (!is_array($fields) || !array_is_list($fields)) {
            throw new LogicException("{$file} must return a list of ACF fields.");
        }

        return [
            'key' => 'group_' . self::id($target),
            'title' => self::TITLES[$param] . ": {$value}",
            'fields' => self::keyed($fields, self::prefix($target), $file),
            'location' => [[['param' => $param, 'operator' => '==', 'value' => $value]]],
        ];
    }

    /**
     * Start of every field key of a target: post_type/review → field_post_type_review.
     */
    public static function prefix(string $target): string
    {
        return 'field_' . self::id($target);
    }

    /**
     * The target ("post_type/review") of an ACF location, or null when it isn't one rule
     * this layout can express: one OR group with one `==` rule on a known param.
     *
     * @param mixed $location a field group's 'location'
     */
    public static function target_of(mixed $location): ?string
    {
        if (!is_array($location) || count($location) !== 1 || !is_array($location[0] ?? null) || count($location[0]) !== 1) {
            return null;
        }
        $rule = $location[0][0] ?? null;
        if (!is_array($rule) || !in_array($rule['param'] ?? null, self::PARAMS, true) || ($rule['operator'] ?? '==') !== '==' || !is_string($rule['value'] ?? null)) {
            return null;
        }

        return "{$rule['param']}/{$rule['value']}";
    }

    /**
     * Adds the derived keys: {parent key}__{name}, or {type}-{label} for layout fields (tabs, messages).
     * Shared with BlockFields.
     *
     * @param list<mixed> $fields
     * @return list<array<string, mixed>>
     */
    public static function keyed(array $fields, string $parent, string $file): array
    {
        $keyed = [];

        foreach ($fields as $field) {
            if (!is_array($field) || !is_string($field['type'] ?? null)) {
                throw new LogicException("{$file}: every field needs a 'type'.");
            }
            if ($field['type'] === 'flexible_content') {
                throw new LogicException("{$file}: no flexible content (decided): make separate blocks, or use a repeater.");
            }
            if (isset($field['key'])) {
                throw new LogicException("{$file}: don't set 'key' (field '" . ($field['name'] ?? $field['label'] ?? '?') . "'); Gaffer derives it from the names.");
            }

            if (is_string($field['name'] ?? null) && (str_starts_with($field['name'], '_') || str_contains($field['name'], '__'))) {
                throw new LogicException("{$file}: field name '{$field['name']}' can't start with '_' or contain '__' (ACF and the derived keys use those).");
            }

            $id = is_string($field['name'] ?? null) && $field['name'] !== ''
                ? $field['name']
                : $field['type'] . '-' . trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower((string) ($field['label'] ?? ''))), '-');
            $key = "{$parent}__{$id}";

            if (isset($keyed[$key])) {
                throw new LogicException("{$file}: two fields get the key {$key} (same name, or two layout fields with the same label).");
            }
            if (isset($field['sub_fields']) && is_array($field['sub_fields'])) {
                $field['sub_fields'] = self::keyed(array_values($field['sub_fields']), $key, $file);
            }

            $keyed[$key] = ['key' => $key, ...$field];
        }

        return array_values($keyed);
    }

    /** @return array{string, string} */
    private static function split(string $target): array
    {
        $parts = explode('/', $target);
        if (count($parts) !== 2 || !in_array($parts[0], self::PARAMS, true) || $parts[1] === '' || str_contains($parts[1], '__')) {
            throw new LogicException("Unknown field group target {$target}: expected one of " . implode(', ', self::PARAMS) . '/{name}.');
        }

        return [$parts[0], $parts[1]];
    }

    private static function id(string $target): string
    {
        [$param, $value] = self::split($target);

        return "{$param}_{$value}";
    }
}
