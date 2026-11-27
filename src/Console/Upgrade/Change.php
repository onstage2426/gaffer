<?php

declare(strict_types=1);

namespace Gaffer\Console\Upgrade;

/**
 * One file an upgrade rule writes: its content before (null: the file is new) and after (null:
 * the file goes). Paths are relative to the theme.
 *
 * @internal
 */
final readonly class Change
{
    public function __construct(
        public string $path,
        public ?string $before,
        public ?string $after,
    ) {}
}
