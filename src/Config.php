<?php

declare(strict_types=1);

namespace Gaffer;

/**
 * The theme's config/*.php files, read once per request. config/gaffer/ is Gaffer's: config
 * files Gaffer adds later go there (config/gaffer/{name}.php, read as gaffer.{name}.*), so they
 * never collide with a theme's own config/{name}.php.
 */
final class Config
{
    /** @var array<string, array<string, mixed>> */
    private static array $config = [];

    /** @internal */
    public static function load(string $dir): void
    {
        $config = [];

        foreach (glob("{$dir}/*.php") ?: [] as $file) {
            $data = include $file;
            if (!is_array($data)) {
                continue;
            }
            $config[basename($file, '.php')] = $data;
        }
        foreach (glob("{$dir}/gaffer/*.php") ?: [] as $file) {
            $data = include $file;
            if (is_array($data)) {
                $config['gaffer'][basename($file, '.php')] = $data;
            }
        }

        self::$config = $config;
    }

    /**
     * @return array<string, array<string, mixed>>
     *
     * @internal
     */
    public static function all(): array
    {
        return self::$config;
    }

    /**
     * A value by dotted key ("theme.types": config/theme.php's `types`), null when
     * it isn't set.
     */
    public static function get(string $key): mixed
    {
        $parts = explode('.', $key);
        $value = self::$config[array_shift($parts)] ?? null;

        foreach ($parts as $part) {
            if (!is_array($value) || !isset($value[$part])) {
                return null;
            }
            $value = $value[$part];
        }

        return $value;
    }
}
