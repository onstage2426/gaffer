<?php

declare(strict_types=1);

namespace Gaffer\Bootstrap;

class IncludesBootstrapper
{
    public function __construct(private readonly string $base) {}

    public function boot(): void
    {
        foreach (glob("{$this->base}/*.php") ?: [] as $file) {
            include_once $file;
        }

        foreach (glob("{$this->base}/*/*.php") ?: [] as $file) {
            include_once $file;
        }
    }
}
