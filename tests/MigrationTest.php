<?php

declare(strict_types=1);

namespace Gaffer\Tests;

use Gaffer\Console\Migrate\Location;
use Gaffer\Console\Migrate\Migration;
use LogicException;

final class MigrationTest extends TestCase
{
    public function test_a_location_is_changed_once_per_run(): void
    {
        // Option rows have no editor lock to look up, so this needs no WordPress.
        $migration = new Migration('migrate:fields');
        $migration->change(new Location('option', 1, 'option _options_a'), 'field_old_a', 'field_new_a', 'a');
        $migration->change(new Location('option', 2, 'option _options_b'), 'field_old_b', 'field_new_b', 'b');
        $migration->change(new Location('termmeta', 1, 'term 7 _a'), 'field_old_a', 'field_new_a', 'same id, other kind');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('option _options_a is changed twice in one run');
        $migration->change(new Location('option', 1, 'option _options_a'), 'field_new_a', 'field_newer_a', 'again');
    }
}
