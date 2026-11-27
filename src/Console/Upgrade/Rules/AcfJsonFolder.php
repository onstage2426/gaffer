<?php

declare(strict_types=1);

namespace Gaffer\Console\Upgrade\Rules;

use Gaffer\Console\Upgrade\Change;
use Gaffer\Console\Upgrade\Rule;
use Gaffer\Console\Upgrade\Theme;

/** CHANGELOG 2026-11-22: ACF's UI JSON lives in acf-json/, out of storage/. @internal */
final class AcfJsonFolder extends Rule
{
    #[\Override]
    public function id(): string
    {
        return '2026-11-22-acf-json';
    }

    #[\Override]
    public function describe(): string
    {
        return 'storage/acf-json/*.json → acf-json/';
    }

    #[\Override]
    public function changes(Theme $theme): array
    {
        $changes = [];
        foreach ($theme->files(['storage/acf-json'], ['json']) as $path) {
            $code = (string) $theme->read($path);
            $target = 'acf-json/' . basename($path);
            $existing = $theme->read($target);
            if ($existing !== null && $existing !== $code) {
                continue; // both differ: by_hand() names it
            }
            if ($existing === null) {
                $changes[] = new Change($target, null, $code);
            }
            $changes[] = new Change($path, $code, null);
        }

        return $changes;
    }

    #[\Override]
    public function by_hand(Theme $theme): array
    {
        $todo = [];
        foreach ($theme->files(['storage/acf-json'], ['json']) as $path) {
            $target = 'acf-json/' . basename($path);
            $existing = $theme->read($target);
            if ($existing !== null && $existing !== $theme->read($path)) {
                $todo[] = "{$path} and {$target} differ: keep the newer one in acf-json/ and delete the other.";
            }
        }

        return $todo;
    }
}
