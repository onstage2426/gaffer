<?php

declare(strict_types=1);

namespace Gaffer\Console\Upgrade\Rules;

use Gaffer\Console\Upgrade\Change;
use Gaffer\Console\Upgrade\Rule;
use Gaffer\Console\Upgrade\Theme;

/**
 * CHANGELOG 2026-11-27: `update` links vendor to a vendor.{id}/ folder next to it. Git doesn't
 * match a link with a folder pattern (`vendor/`), so .gitignore also gets `/vendor` and
 * `/vendor.*`. Only where .gitignore ignores vendor/ already (a theme that commits it decides
 * itself). @internal
 */
final class VendorLink extends Rule
{
    #[\Override]
    public function id(): string
    {
        return '2026-11-27-vendor-link';
    }

    #[\Override]
    public function describe(): string
    {
        return '.gitignore: also /vendor (a link now) and /vendor.* (the folders it points to)';
    }

    #[\Override]
    public function changes(Theme $theme): array
    {
        $code = $theme->read('.gitignore');
        if ($code === null) {
            return [];
        }
        $lines = array_map('trim', explode("\n", $code));
        $vendor = array_values(array_filter(array_keys($lines), static fn(int $i): bool => in_array($lines[$i], ['vendor/', '/vendor/', 'vendor', '/vendor', 'vendor/*', '/vendor/*'], true)));
        if ($vendor === []) {
            return [];
        }
        $add = [];
        if (array_intersect($lines, ['vendor', '/vendor']) === []) {
            $add[] = '/vendor';
        }
        if (array_intersect($lines, ['vendor.*', '/vendor.*', 'vendor.*/', '/vendor.*/']) === []) {
            $add[] = '/vendor.*';
        }
        if ($add === []) {
            return [];
        }
        // Right after the theme's own vendor line, so the three stay together.
        $out = explode("\n", $code);
        array_splice($out, $vendor[0] + 1, 0, $add);

        return [new Change('.gitignore', $code, implode("\n", $out))];
    }
}
