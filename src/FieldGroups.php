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
        $titles = [];
        foreach (array_keys(self::targets()) as $target) {
            $group = self::group($target);
            acf_add_local_field_group($group);
            $titles[$group['key']] = $target;
        }

        // The meta box title editors see: the post type's, taxonomy's or options page's own
        // name, asked for when the group is loaded (they're registered after ACF's init).
        $title = static function (array $group) use ($titles): array {
            $target = $titles[$group['key'] ?? ''] ?? null;
            if ($target !== null) {
                $group['title'] = self::title($target) ?? $group['title'];
            }
            return $group;
        };
        add_filter('acf/load_field_group', $title);
        add_filter('acf/load_field_groups', static fn(array $groups): array => array_map($title, $groups), 30);
    }

    /**
     * What WordPress calls the target: "Product", "Productcategorie", the options page's
     * title. Null when it isn't registered (yet).
     */
    public static function title(string $target): ?string
    {
        [$param, $value] = self::split($target);
        $name = match ($param) {
            'post_type' => \get_post_type_object($value)?->labels->singular_name ?? null,
            'taxonomy' => \get_taxonomy($value) ? \get_taxonomy($value)->labels->singular_name : null,
            default => function_exists('acf_get_options_page') && is_array($page = acf_get_options_page($value)) ? ($page['page_title'] ?? null) : null,
        };

        // Plain text: ACF escapes the title when it prints it (an options page's is stored as "B&amp;S").
        return is_string($name) && $name !== '' ? html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8') : null;
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
     * Whether a theme file defines ACF fields (fields/, a block's fields.php): data, so a
     * default value with markup or an uploads URL isn't markup in PHP.
     */
    public static function is_definition(string $file): bool
    {
        return str_starts_with($file, Paths::fields() . '/')
            || (basename($file) === 'fields.php' && dirname($file, 2) === Paths::blocks());
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
     * Adds the derived keys: {parent key}__{name}, or {type}-{label} for layout fields (tabs, messages),
     * and resolves conditional logic by field name. Shared with BlockFields.
     *
     * A conditional_logic rule's 'field' is a field of the same file: a name path from the
     * top ("is_hoofdcategorie", "vragen.vraag") or a sibling's name inside a repeater or
     * group; it becomes the derived key. A key written out must be one of the file's.
     *
     * @param list<mixed> $fields
     * @return list<array<string, mixed>>
     */
    public static function keyed(array $fields, string $parent, string $file): array
    {
        $keyed = self::key_fields($fields, $parent, $file);
        $paths = self::key_paths($keyed);

        return self::resolve_conditions($keyed, $paths, '', $file);
    }

    /**
     * @param list<mixed> $fields
     * @return list<array<string, mixed>>
     */
    private static function key_fields(array $fields, string $parent, string $file): array
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
                $field['sub_fields'] = self::key_fields(array_values($field['sub_fields']), $key, $file);
            }

            $keyed[$key] = ['key' => $key, ...$field];
        }

        return array_values($keyed);
    }

    /**
     * Every data field's key by name path ("vragen.vraag").
     *
     * @param list<array<string, mixed>> $fields
     * @return array<string, string>
     */
    private static function key_paths(array $fields, string $path = ''): array
    {
        $paths = [];
        foreach ($fields as $field) {
            if (is_string($field['name'] ?? null) && $field['name'] !== '') {
                $paths[$path . $field['name']] = (string) $field['key'];
                if (is_array($field['sub_fields'] ?? null)) {
                    $paths += self::key_paths($field['sub_fields'], "{$path}{$field['name']}.");
                }
            }
        }

        return $paths;
    }

    /**
     * @param list<array<string, mixed>> $fields
     * @param array<string, string> $paths
     * @return list<array<string, mixed>>
     */
    private static function resolve_conditions(array $fields, array $paths, string $path, string $file): array
    {
        foreach ($fields as $i => $field) {
            $name = (string) ($field['name'] ?? $field['label'] ?? '?');
            if (is_array($field['conditional_logic'] ?? null)) {
                foreach ($field['conditional_logic'] as $g => $rules) {
                    foreach (is_array($rules) ? $rules : [] as $r => $rule) {
                        $target = is_array($rule) && is_string($rule['field'] ?? null) ? $rule['field'] : throw new LogicException("{$file}: a conditional_logic rule of '{$name}' has no 'field'.");
                        $key = match (true) {
                            str_starts_with($target, 'field_') => in_array($target, $paths, true) ? $target : null,
                            default => $paths[$target] ?? $paths[$path . $target] ?? null,
                        };
                        if ($key === null) {
                            throw new LogicException("{$file}: the conditional logic of '{$name}' refers to '{$target}', which isn't a field of this file (use its name, e.g. 'is_hoofdcategorie' or 'vragen.vraag').");
                        }
                        $fields[$i]['conditional_logic'][$g][$r]['field'] = $key;
                    }
                }
            }
            if (is_array($field['sub_fields'] ?? null) && is_string($field['name'] ?? null)) {
                $fields[$i]['sub_fields'] = self::resolve_conditions($field['sub_fields'], $paths, "{$path}{$field['name']}.", $file);
            }
        }

        return $fields;
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
