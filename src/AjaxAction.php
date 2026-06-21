<?php

declare(strict_types=1);

namespace Gaffer;

abstract class AjaxAction
{
    public string $method = 'POST';
    public bool $shortinit = false;

    public function arguments(): array
    {
        return [];
    }

    abstract public function run(array $data): void;
}
