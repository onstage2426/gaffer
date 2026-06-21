<?php

declare(strict_types=1);

namespace Gaffer\Types;

abstract class Model
{
    protected function import(object $info): void
    {
        $info = get_object_vars($info);

        foreach ($info as $key => $value) {
            if ("" === $key || ord($key[0]) === 0) {
                continue;
            }
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
    }
}
