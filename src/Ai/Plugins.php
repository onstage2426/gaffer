<?php

declare(strict_types=1);

namespace Gaffer\Ai;

use Gaffer\Mcp\Mcp;
use Gaffer\Search\Engine;
use Gaffer\Search\Indexes;

/**
 * The plugins Gaffer integrates with, one entry each: how to tell it's active. An active
 * plugin's guideline (resources/ai/guidelines/plugins/{name}.md) and skills
 * (resources/ai/skills/plugins/{name}/) go into the theme's AI files. Adding a plugin is an
 * entry here plus those files (AiTest checks they match).
 *
 * @internal
 */
final class Plugins
{
    /**
     * Name => whether it's active for the booted site.
     *
     * @return array<string, \Closure(): bool>
     */
    public static function all(): array
    {
        return [
            'woocommerce' => static fn(): bool => function_exists('WC'),
            'acf' => static fn(): bool => class_exists('ACF'),
            'gravityforms' => static fn(): bool => class_exists('GFAPI'),
            // config/search.php too: a staged copy is on gaffer-search before the live site's plugin is.
            'gaffer-search' => static fn(): bool => Engine::find() !== null || Indexes::all() !== [],
            'mcp-adapter' => static fn(): bool => Mcp::enabled(),
        ];
    }

    /** @return list<string> */
    public static function active(): array
    {
        return array_keys(array_filter(self::all(), static fn(\Closure $active): bool => $active()));
    }
}
