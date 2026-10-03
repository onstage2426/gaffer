<?php

declare(strict_types=1);

namespace Gaffer\Mcp\Tools;

use Gaffer\Console\Migrate\ContentStore;
use Gaffer\Console\Migrate\Location;
use Gaffer\Mcp\Tool;

final class BlockUsage implements Tool
{
    #[\Override]
    public function name(): string
    {
        return 'block-usage';
    }

    #[\Override]
    public function label(): string
    {
        return 'Block usage';
    }

    #[\Override]
    public function description(): string
    {
        return 'Where a block is used: every post (any type and status) and block widget whose content contains it, '
            . 'with how often and the URL. With values=true also the stored data of each occurrence (for ACF blocks the '
            . 'field values, repeater rows flattened as "name_0_sub"). Use before changing, renaming or removing a block or '
            . 'field, and to find a page to test a block on.';
    }

    #[\Override]
    public function input_schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'block' => ['type' => 'string', 'pattern' => '^[a-z0-9-]+/[a-z0-9-]+$', 'description' => 'Block name, e.g. "acf/content-faq" or "core/paragraph"'],
                'values' => ['type' => 'boolean', 'default' => false, 'description' => 'Include the stored data of each occurrence'],
            ],
            'required' => ['block'],
        ];
    }

    #[\Override]
    public function run(array $input): array
    {
        $block = (string) $input['block'];
        $values = (bool) ($input['values'] ?? false);
        // Core blocks are stored without their namespace: <!-- wp:paragraph -->.
        $stored = str_starts_with($block, 'core/') ? substr($block, 5) : $block;

        $locations = [];
        foreach (ContentStore::find("<!-- wp:{$stored} ") as $location) {
            $found = self::occurrences(\parse_blocks(ContentStore::read($location)), $block);
            if ($found !== []) {
                $locations[] = self::describe($location) + ['count' => count($found)] + ($values ? ['values' => array_map(self::data(...), $found)] : []);
            }
        }

        return ['block' => $block, 'total' => array_sum(array_column($locations, 'count')), 'locations' => $locations];
    }

    #[\Override]
    public function available(): bool
    {
        return true;
    }

    /**
     * Every block named $name, nested ones included.
     *
     * @param array<array<string, mixed>> $blocks parse_blocks() output
     * @return list<array<string, mixed>>
     */
    public static function occurrences(array $blocks, string $name): array
    {
        $found = [];
        foreach ($blocks as $block) {
            if (($block['blockName'] ?? null) === $name) {
                $found[] = $block;
            }
            if (is_array($block['innerBlocks'] ?? null)) {
                array_push($found, ...self::occurrences($block['innerBlocks'], $name));
            }
        }

        return $found;
    }

    /**
     * An ACF block's field values (without ACF's "_name" field-key references), other blocks' attributes.
     *
     * @param array<string, mixed> $block
     * @return array<string, mixed>
     */
    public static function data(array $block): array
    {
        $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];
        if (!is_array($attrs['data'] ?? null)) {
            return $attrs;
        }

        return array_filter($attrs['data'], static fn(string|int $key): bool => !str_starts_with((string) $key, '_'), ARRAY_FILTER_USE_KEY);
    }

    /** @return array<string, mixed> */
    private static function describe(Location $location): array
    {
        $post = $location->kind === 'post' ? \get_post($location->id) : null;
        if ($post === null) {
            return ['kind' => $location->kind, 'id' => $location->id];
        }

        return ['kind' => 'post', 'id' => $post->ID, 'title' => $post->post_title, 'type' => $post->post_type,
            'status' => $post->post_status, 'url' => \wp_make_link_relative((string) \get_permalink($post))];
    }
}
