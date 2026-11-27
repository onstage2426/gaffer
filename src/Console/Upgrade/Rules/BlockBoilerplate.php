<?php

declare(strict_types=1);

namespace Gaffer\Console\Upgrade\Rules;

use Gaffer\BlockFields;
use Gaffer\Console\Upgrade\Change;
use Gaffer\Console\Upgrade\Rule;
use Gaffer\Console\Upgrade\Theme;

/**
 * CHANGELOG 2026-11-23: Gaffer names blocks after their folder, registers them as ACF Blocks V3
 * and renders functions.php only outside the editor, so block.json's "name" (when it's the
 * folder's), acf.blockVersion 3, acf.renderTemplate "functions.php" and the is_admin() guard go.
 *
 * @internal
 */
final class BlockBoilerplate extends Rule
{
    private const string GUARD = '/^if\s*\(\s*is_admin\(\)\s*\)\s*\{?\s*return\s*;[ \t]*(?:\/\/[^\n]*)?\s*\}?[ \t]*\R(\s*\R)?/m';

    #[\Override]
    public function id(): string
    {
        return '2026-11-23-block-boilerplate';
    }

    #[\Override]
    public function describe(): string
    {
        return 'blocks/*: drop what Gaffer sets (block.json name and acf settings, the is_admin() guard)';
    }

    #[\Override]
    public function changes(Theme $theme): array
    {
        $changes = [];
        foreach ($theme->files(['blocks'], ['json']) as $path) {
            if (basename($path) !== 'block.json' || substr_count($path, '/') !== 2) {
                continue;
            }
            $code = (string) $theme->read($path);
            $json = json_decode($code, true);
            if (!is_array($json)) {
                continue;
            }
            $folder = basename(dirname($path));
            $original = $json;
            if (($json['name'] ?? null) === BlockFields::folder_name($folder)) {
                unset($json['name']);
            }
            if (is_array($json['acf'] ?? null)) {
                $acf = $json['acf'];
                if (($acf['blockVersion'] ?? null) === 3) {
                    unset($acf['blockVersion']);
                }
                if (($acf['renderTemplate'] ?? null) === 'functions.php') {
                    unset($acf['renderTemplate']);
                }
                if ($acf === []) {
                    unset($json['acf']);
                } else {
                    $json['acf'] = $acf;
                }
            }
            if ($json !== $original) {
                $changes[] = new Change($path, $code, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
            }
        }
        foreach ($theme->files(['blocks'], ['php']) as $path) {
            if (basename($path) !== 'functions.php' || substr_count($path, '/') !== 2) {
                continue;
            }
            $code = (string) $theme->read($path);
            $after = (string) preg_replace(self::GUARD, '', $code, 1);
            if ($after !== $code) {
                $changes[] = new Change($path, $code, $after);
            }
        }

        return $changes;
    }
}
