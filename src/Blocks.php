<?php

declare(strict_types=1);

namespace Gaffer;

use Gaffer\Console\ConfigStubs;
use InvalidArgumentException;

/**
 * ACF blocks as content, written by code (demo and launch pages, seeds): the block comment WordPress
 * stores, with every value next to its derived field key, checked against the block's fields.php.
 * Content written by code goes through here, never through hand-written block comments: a typo in
 * a key would lose its value without a word.
 */
final class Blocks
{
    /** Field types that hold no value (layout only). */
    private const array LAYOUT = ['tab', 'message', 'accordion'];

    /**
     * The block comment for an ACF block with these values, by field name. Repeater rows are a
     * list of arrays by sub field name; a true/false field takes a bool; images, files and posts
     * their ID; a link ['title' => …, 'url' => …, 'target' => …]. Fields left out get their
     * default, as in the editor. wp_insert_post() and wp_update_post() unslash what they get:
     * write it with wp_slash(), or the JSON's escapes (\u003c) lose their backslashes.
     *
     *   $content = Blocks::markup('acf/content-faq', [
     *       'titel' => 'Veelgestelde vragen',
     *       'vragen' => [['vraag' => 'Bezorgen jullie?', 'antwoord' => '<p>Ja.</p>']],
     *   ]);
     *
     * @param array<string, mixed> $values
     * @throws InvalidArgumentException for an unknown block or field, or a value the field can't take
     */
    public static function markup(string $block, array $values): string
    {
        $dir = self::dir($block);
        $data = self::data(BlockFields::group($dir)['fields'], $values, '', $block);
        $attributes = ['name' => $block, 'data' => $data, 'mode' => 'preview'];

        return "<!-- wp:{$block} " . self::attributes($attributes) . ' /-->';
    }

    /**
     * blocks/{dir} of a block name.
     *
     * @throws InvalidArgumentException when no block has the name
     */
    private static function dir(string $block): string
    {
        $names = [];
        foreach (glob(Paths::blocks() . '/*/block.json') ?: [] as $file) {
            $json = json_decode((string) file_get_contents($file), true);
            $name = is_array($json) && is_string($json['name'] ?? null) ? $json['name'] : null;
            if ($name === $block && is_file(dirname($file) . '/fields.php')) {
                return basename(dirname($file));
            }
            if ($name !== null) {
                $names[] = $name;
            }
        }
        $suggestion = ConfigStubs::did_you_mean($block, $names);

        throw new InvalidArgumentException("No ACF block {$block} with a fields.php" . ($suggestion !== null ? " (did you mean {$suggestion}?)" : ''));
    }

    /**
     * ACF's block data for these values: value and "_name" => key, repeater rows as
     * name_{i}_sub.
     *
     * @param list<array<string, mixed>> $fields keyed fields (FieldGroups::keyed())
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private static function data(array $fields, array $values, string $prefix, string $where): array
    {
        $by_name = [];
        foreach ($fields as $field) {
            if (is_string($field['name'] ?? null) && $field['name'] !== '') {
                $by_name[$field['name']] = $field;
            }
        }

        $data = [];
        foreach ($values as $name => $value) {
            $name = (string) $name;
            $field = $by_name[$name] ?? null;
            if ($field === null) {
                $suggestion = ConfigStubs::did_you_mean($name, array_keys($by_name));
                throw new InvalidArgumentException("{$where}: no field \"{$name}\"" . ($suggestion !== null ? " (did you mean \"{$suggestion}\"?)" : ''));
            }
            $type = (string) $field['type'];
            $stored = $prefix . $name;
            $at = "{$where}: {$stored}";

            if (in_array($type, self::LAYOUT, true)) {
                throw new InvalidArgumentException("{$at} is a {$type}: it holds no value");
            }
            if ($type === 'group') {
                throw new InvalidArgumentException("{$at} is a group: Blocks::markup() doesn't write group fields yet");
            }
            if ($type === 'repeater') {
                if (!is_array($value) || !array_is_list($value)) {
                    throw new InvalidArgumentException("{$at} is a repeater: give a list of rows, each an array by sub field name");
                }
                $data[$stored] = count($value);
                $data["_{$stored}"] = $field['key'];
                foreach ($value as $i => $row) {
                    if (!is_array($row) || array_is_list($row) && $row !== []) {
                        throw new InvalidArgumentException("{$at}, row {$i}: a row is an array by sub field name");
                    }
                    $data += self::data((array) ($field['sub_fields'] ?? []), $row, "{$stored}_{$i}_", $where);
                }
                continue;
            }

            $data[$stored] = self::value($type, $value, $at);
            $data["_{$stored}"] = $field['key'];
        }

        return $data;
    }

    /** A value as ACF stores it in block data. */
    private static function value(string $type, mixed $value, string $at): mixed
    {
        if ($type === 'true_false') {
            return is_bool($value) ? ($value ? '1' : '0') : throw new InvalidArgumentException("{$at} is a true/false field: give a bool");
        }
        if (in_array($type, ['image', 'file'], true)) {
            return is_int($value) && $value > 0 ? $value : throw new InvalidArgumentException("{$at} is an {$type} field: give the attachment ID");
        }
        if ($type === 'link') {
            if (!is_array($value) || !is_string($value['url'] ?? null) || array_diff(array_keys($value), ['title', 'url', 'target']) !== []) {
                throw new InvalidArgumentException("{$at} is a link field: give ['title' => …, 'url' => …, 'target' => …]");
            }
            return ['title' => (string) ($value['title'] ?? ''), 'url' => $value['url'], 'target' => (string) ($value['target'] ?? '')];
        }
        if (is_scalar($value)) {
            return is_bool($value) ? throw new InvalidArgumentException("{$at} is a {$type} field: a bool fits only a true/false field") : $value;
        }
        if (is_array($value) && array_is_list($value) && array_filter($value, is_scalar(...)) === $value) {
            // Several values (checkbox, relationship, taxonomy): ACF stores IDs as strings.
            return array_map(strval(...), $value);
        }

        throw new InvalidArgumentException("{$at} is a {$type} field: give a value or a list of values");
    }

    /**
     * Block attributes as WordPress's serialize_block_attributes() writes them: JSON whose
     * characters can't end or break the comment.
     *
     * @param array<string, mixed> $attributes
     */
    private static function attributes(array $attributes): string
    {
        $json = (string) json_encode($attributes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return str_replace(['--', '<', '>', '&', '\\"'], ['\\u002d\\u002d', '\\u003c', '\\u003e', '\\u0026', '\\u0022'], $json);
    }
}
