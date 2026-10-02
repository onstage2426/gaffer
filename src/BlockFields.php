<?php

declare(strict_types=1);

namespace Gaffer;

use LogicException;

/**
 * A block's ACF fields in code: blocks/{dir}/fields.php returns a list of ACF
 * field arrays. Gaffer registers them as a local field group with the block as
 * its location, and derives every key from the block and field names
 * (field_content-faq__vragen__vraag), so the keys stored in post content never
 * change and nothing is synced through the database.
 */
final class BlockFields
{
    public static function register(): void
    {
        if (!function_exists('acf_add_local_field_group')) {
            return;
        }
        foreach (glob(Paths::blocks() . '/*/fields.php') ?: [] as $file) {
            acf_add_local_field_group(self::group(basename(dirname($file))));
        }
    }

    /**
     * The local field group for blocks/{dir}/fields.php.
     *
     * @return array<string, mixed>
     */
    public static function group(string $dir): array
    {
        $file = "blocks/{$dir}/fields.php";
        $block = json_decode((string) file_get_contents(Paths::blocks() . "/{$dir}/block.json"), true);
        $name = is_array($block) && is_string($block['name'] ?? null) ? $block['name'] : throw new LogicException("{$file}: blocks/{$dir}/block.json has no name.");
        $fields = require Paths::base($file);
        if (!is_array($fields) || !array_is_list($fields)) {
            throw new LogicException("{$file} must return a list of ACF fields.");
        }
        return [
            'key' => 'group_' . substr($name, strlen('acf/')),
            'title' => 'Block: ' . ($block['title'] ?? $dir),
            'fields' => self::keyed($fields, self::prefix($name), $file),
            'location' => [[['param' => 'block', 'operator' => '==', 'value' => $name]]],
        ];
    }

    /**
     * Start of every field key of a block: acf/content-faq → field_content-faq.
     */
    public static function prefix(string $block_name): string
    {
        return 'field_' . substr($block_name, strlen('acf/'));
    }

    /**
     * Adds the derived keys: {parent key}__{name}, or {type}-{label} for layout fields (tabs, messages).
     *
     * @param list<mixed> $fields
     * @return list<array<string, mixed>>
     */
    private static function keyed(array $fields, string $parent, string $file): array
    {
        $keyed = [];

        foreach ($fields as $field) {
            if (!is_array($field) || !is_string($field['type'] ?? null)) {
                throw new LogicException("{$file}: every field needs a 'type'.");
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
}
