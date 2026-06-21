<?php

declare(strict_types=1);

namespace Gaffer\Support\Traits;

Trait ClassImporter
{
    protected function import(object $info): void
    {
        if (is_object($info)) {
            $info = get_object_vars($info);
        }
        if (is_array($info)) {
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
}
