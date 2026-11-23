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
 *
 * @internal
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
        $name = self::name($dir);
        if (!str_starts_with($name, 'acf/')) {
            throw new LogicException("blocks/{$dir}/block.json: \"{$name}\" must start with \"acf/\" (field keys are derived from the name after it).");
        }
        $fields = require Paths::base($file);
        if (!is_array($fields) || !array_is_list($fields)) {
            throw new LogicException("{$file} must return a list of ACF fields.");
        }
        return [
            'key' => 'group_' . substr($name, strlen('acf/')),
            'title' => 'Block: ' . ($block['title'] ?? $dir),
            'fields' => FieldGroups::keyed($fields, self::prefix($name), $file),
            'location' => [[['param' => 'block', 'operator' => '==', 'value' => $name]]],
        ];
    }

    /**
     * A block's name: acf/ + its folder in kebab case (contentFaq → acf/content-faq), or the
     * "name" its block.json still has (doctor errors when the two differ).
     */
    public static function name(string $dir): string
    {
        $json = json_decode((string) @file_get_contents(Paths::blocks() . "/{$dir}/block.json"), true);

        return is_array($json) && is_string($json['name'] ?? null) ? $json['name'] : self::folder_name($dir);
    }

    /** contentFaq → acf/content-faq */
    public static function folder_name(string $dir): string
    {
        return 'acf/' . strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $dir));
    }

    /**
     * Every block's name (name()), by folder: each blocks/*\/block.json that is valid JSON.
     *
     * @return array<string, string>
     */
    public static function names(): array
    {
        $names = [];
        foreach (glob(Paths::blocks() . '/*/block.json') ?: [] as $file) {
            if (is_array(json_decode((string) file_get_contents($file), true))) {
                $names[basename(dirname($file))] = self::name(basename(dirname($file)));
            }
        }

        return $names;
    }

    /**
     * Start of every field key of a block: acf/content-faq → field_content-faq.
     */
    public static function prefix(string $block_name): string
    {
        return 'field_' . substr($block_name, strlen('acf/'));
    }
}
