<?php

declare(strict_types=1);

namespace Gaffer\Facades;

class Paths
{
    public static function views(): string
    {
        return Config::get('path.views') ?? self::base('views');
    }

    public static function namespaces(): array
    {
        return Config::get('path.namespaces') ?? [];
    }

    public static function includes(): string
    {
        return Config::get('path.includes') ?? self::base('inc');
    }

    public static function storage(): string
    {
        return Config::get('path.storage') ?? self::base('storage');
    }

    public static function blocks(): string
    {
        return Config::get('path.blocks') ?? self::base('blocks');
    }

    public static function ajax(): string
    {
        return Config::get('path.ajax') ?? self::base('ajax');
    }

    public static function public(): string
    {
        return Config::get('path.public') ?? self::base('public');
    }

    private static function base(string $dir): string
    {
        // get_template_directory() doesn't exist yet when Gaffer::boot() runs via Composer's
        // "files" autoload before wp-load.php (e.g. the ajax dispatch path) — fall back to
        // deriving the theme root from this package's own install location instead.
        $theme = function_exists('get_template_directory')
            ? \get_template_directory()
            : dirname(__DIR__, 5);

        return "{$theme}/{$dir}";
    }
}
