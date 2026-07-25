<?php

declare(strict_types=1);

namespace Gaffer\Bootstrap;

class AcfBootstrapper
{
    public function __construct(private readonly string $dir) {}

    public function boot(): void
    {
        if (!function_exists('add_filter')) {
            return;
        }

        add_filter('acf/settings/save_json', fn(): string => $this->dir);
        add_filter('acf/settings/load_json', function (array $paths): array {
            $paths[] = $this->dir;
            return $paths;
        });
    }
}
