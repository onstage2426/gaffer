<?php

declare(strict_types=1);

namespace Gaffer\Support\Facades;

class Config
{
    protected static array $config;

    public static function load(string $dir): void
    {
        $config = [];

        foreach (glob("{$dir}/*.php") ?: [] as $file) {
            $data = include $file;
            if (!is_array($data)) {
                continue;
            }
            $config[basename($file, ".php")] = $data;
        }

        self::$config = $config;
    }

    public static function get(string $key): mixed
    {
        $parts = explode(".", $key);
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
