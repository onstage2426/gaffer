<?php

declare(strict_types=1);

namespace Gaffer\Bootstrap;

use Gaffer\Support\Facades\Config;

class IncludesBootstrapper implements Bootable
{
    public function boot(): void
    {
        $base = Config::get("path.includes");

        foreach (Config::get("theme.includes") as $dir) {
            foreach (glob("{$base}/{$dir}/*.php") ?: [] as $file) {
                include_once $file;
            }
        }

        foreach (glob("{$base}/*.php") ?: [] as $file) {
            include_once $file;
        }
    }
}
