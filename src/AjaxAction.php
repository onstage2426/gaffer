<?php

declare(strict_types=1);

namespace Gaffer;

abstract class AjaxAction
{
    public string $method = 'POST';
    public bool $shortinit = false;

    abstract public function arguments(): array;
    abstract public function run(array $data): void;
}
