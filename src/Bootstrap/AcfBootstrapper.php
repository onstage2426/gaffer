<?php

declare(strict_types=1);

namespace Gaffer\Bootstrap;

use Gaffer\Support\Facades\Config;

class AcfBootstrapper implements Bootable
{
    public function boot(): void
    {
        $acf_dir = Config::get("path.storage") . "/acf-json";

        add_filter("acf/settings/save_json", fn(): string => $acf_dir);
        add_filter("acf/settings/load_json", function (array $paths) use ($acf_dir): array {
            $paths[] = $acf_dir;
            return $paths;
        });
    }
}
