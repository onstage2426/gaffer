<?php

declare(strict_types=1);

namespace Gaffer\Console\Upgrade\Rules;

use Gaffer\Console\Upgrade\Change;
use Gaffer\Console\Upgrade\Rule;
use Gaffer\Console\Upgrade\Theme;

/** CHANGELOG 2026-11-22: Vite 8 reads build.rolldownOptions; rollupOptions is deprecated. @internal */
final class ViteRolldown extends Rule
{
    #[\Override]
    public function id(): string
    {
        return '2026-11-22-vite-rolldown';
    }

    #[\Override]
    public function describe(): string
    {
        return 'vite.config.js: build.rollupOptions → build.rolldownOptions (Vite 8)';
    }

    #[\Override]
    public function changes(Theme $theme): array
    {
        $package = json_decode((string) $theme->read('package.json'), true);
        $vite = is_array($package) ? (string) ($package['devDependencies']['vite'] ?? $package['dependencies']['vite'] ?? '') : '';
        if (!preg_match('/(\d+)/', $vite, $m) || (int) $m[1] < 8) {
            return [];
        }
        $changes = [];
        foreach (['vite.config.js', 'vite.config.mjs', 'vite.config.ts'] as $path) {
            $code = $theme->read($path);
            if ($code !== null && preg_match('/\brollupOptions\b/', $code) === 1) {
                $changes[] = new Change($path, $code, (string) preg_replace('/\brollupOptions\b/', 'rolldownOptions', $code));
            }
        }

        return $changes;
    }
}
