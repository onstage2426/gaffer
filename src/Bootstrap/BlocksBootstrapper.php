<?php

declare(strict_types=1);

namespace Gaffer\Bootstrap;

use Gaffer\Support\Facades\Config;

class BlocksBootstrapper implements Bootable
{
    public function boot(): void
    {
        foreach (glob(Config::get("path.blocks") . "/*/block.json") ?: [] as $block) {
            register_block_type($block);
        }
    }
}
