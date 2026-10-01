<?php

declare(strict_types=1);

namespace Gaffer\Bootstrap;

class BlocksBootstrapper
{
    public function __construct(private readonly string $dir) {}

    public function boot(): void
    {
        foreach (glob("{$this->dir}/*/block.json") ?: [] as $block) {
            register_block_type($block);
        }
    }
}
