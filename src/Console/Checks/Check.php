<?php

declare(strict_types=1);

namespace Gaffer\Console\Checks;

use Gaffer\Console\Report;

interface Check
{
    public function run(Report $report): void;
}
