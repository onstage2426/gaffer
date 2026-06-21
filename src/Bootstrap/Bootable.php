<?php

declare(strict_types=1);

namespace Gaffer\Bootstrap;

interface Bootable
{
    public function boot(): void;
}
