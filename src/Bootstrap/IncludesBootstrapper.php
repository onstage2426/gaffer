<?php

declare(strict_types=1);

namespace Gaffer\Bootstrap;

class IncludesBootstrapper
{
    public function __construct(
        private readonly string $base,
        private readonly array $subdirs = [],
    ) {}

    public function boot(): void
    {
        if (defined('SHORTINIT') && SHORTINIT) {
            return;
        }

        foreach ($this->subdirs as $dir) {
            foreach (glob("{$this->base}/{$dir}/*.php") ?: [] as $file) {
                include_once $file;
            }
        }

        foreach (glob("{$this->base}/*.php") ?: [] as $file) {
            include_once $file;
        }
    }
}
