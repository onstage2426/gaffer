<?php

declare(strict_types=1);

namespace Gaffer\Console\Migrate;

use Gaffer\FieldGroups;
use RuntimeException;

/**
 * A fields/ target as ACF sees it (WordPress loaded): the file's fields, the fields of the
 * UI groups with the same target, and an options page's post_id prefixes. Builds the
 * FieldReferences the migrate:* commands sort its stored rows with.
 *
 * @internal
 */
final class FieldTarget
{
    /**
     * @throws RuntimeException when the target has no file in fields/ or the file is invalid
     */
    public static function sorter(string $target): FieldReferences
    {
        if (!isset(FieldGroups::targets()[$target])) {
            throw new RuntimeException("{$target} has no file in fields/ (expected fields/{$target}.php)");
        }
        try {
            $group = FieldGroups::group($target);
        } catch (\LogicException $e) {
            throw new RuntimeException($e->getMessage(), previous: $e);
        }
        [$param, $value] = explode('/', $target, 2);

        return new FieldReferences($target, self::old_fields($target), FieldReferences::paths($group['fields']), $param === 'options_page' ? self::option_prefixes($value) : []);
    }

    /**
     * The fields of the UI (JSON or database) groups with this target, by key.
     *
     * @return array<string, array{path: string, type: string, pattern: string, key: string, title: string}>
     */
    public static function old_fields(string $target): array
    {
        $old = [];
        foreach (self::ui_groups($target) as $group) {
            foreach (FieldReferences::paths(self::fields_of($group)) as $field) {
                $old[$field['key']] = [...$field, 'title' => (string) $group['title']];
            }
        }

        return $old;
    }

    /**
     * Field groups made in the ACF UI (JSON or database) for exactly this target.
     *
     * @return list<array<string, mixed>>
     */
    public static function ui_groups(string $target): array
    {
        $code = 'group_' . substr(FieldGroups::prefix($target), strlen('field_'));

        return array_values(array_filter(
            acf_get_field_groups(),
            static fn(array $group): bool => ($group['key'] ?? null) !== $code && FieldGroups::target_of($group['location'] ?? null) === $target,
        ));
    }

    /** The title of a UI group of the target that still has this field path, if any. */
    public static function ui_group_with(string $target, string $path): ?string
    {
        foreach (self::old_fields($target) as $field) {
            if ($field['path'] === $path) {
                return $field['title'];
            }
        }

        return null;
    }

    /**
     * The option name prefixes of an options page: its post_id ("options"), and per language
     * when WPML or Polylang translate options ("options_nl").
     *
     * @return list<string>
     */
    public static function option_prefixes(string $page): array
    {
        $settings = function_exists('acf_get_options_page') ? acf_get_options_page($page) : false;
        $base = is_array($settings) && is_string($settings['post_id'] ?? null) ? $settings['post_id'] : 'options';
        $base = $base === 'option' ? 'options' : $base;
        $languages = array_keys((array) apply_filters('wpml_active_languages', null, ['skip_missing' => 0]));
        if (function_exists('pll_languages_list')) {
            array_push($languages, ...(array) pll_languages_list());
        }

        return [$base, ...array_map(static fn(mixed $language): string => "{$base}_{$language}", array_unique($languages))];
    }

    /**
     * A field group's fields with their sub fields, as ACF loads them (a JSON or database group).
     *
     * @param array<string, mixed> $group
     * @return list<array<string, mixed>>
     */
    public static function fields_of(array $group): array
    {
        $load = static function (array $parent) use (&$load): array {
            $fields = [];
            foreach (acf_get_fields($parent) ?: [] as $field) {
                if (in_array($field['type'] ?? null, ['repeater', 'group'], true)) {
                    $field['sub_fields'] = $load($field);
                }
                $fields[] = $field;
            }
            return $fields;
        };

        return $load($group);
    }
}
