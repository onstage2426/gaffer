<?php

declare(strict_types=1);

namespace Gaffer\Console\Checks;

use Gaffer\Console\Report;
use Gaffer\Console\ThemeFiles;
use Gaffer\Config;
use Gaffer\Paths;

/**
 * Line-level scans of the theme's PHP and Twig: hardcoded IDs, uploads paths, and
 * trusted HTML: |raw in templates and new Markup(...) in PHP (the same decision,
 * made in PHP), counted together against console.raw_baseline.
 */
final class SourceCheck implements Check
{
    private const array PATTERNS = [
        'php' => '/\b(?:[A-Z]\w*::from|get_post|get_term|wp_get_nav_menu_items|GFAPI::(?:submit_form|get_form|get_entries)|gravity_form)\(\s*\d+\s*[,)]|\[gravityforms? id=["\']?\d+/',
        'twig' => '/\bwp\.ID\s*==\s*\d+|\bid\(\)\s*==\s*\d+/',
    ];

    #[\Override]
    public function run(Report $report): void
    {
        $raw = [];

        foreach (ThemeFiles::find(['php', 'twig']) as $file) {
            $type = pathinfo($file, PATHINFO_EXTENSION);

            foreach (file($file) ?: [] as $i => $line) {
                if (preg_match(self::PATTERNS[$type], $line, $m)) {
                    $report->warning('source', 'Hardcoded ID: ' . trim($m[0], ' ,)') . ')', $file, $i + 1,
                        'IDs differ per site/database. Use a menu location, config value or option.');
                }
                if (str_contains($line, 'wp-content/uploads/')) {
                    $report->warning('source', 'Hardcoded uploads path', $file, $i + 1,
                        'Theme graphics belong in the theme (e.g. views/components/icons/).');
                }
                if ($type === 'twig' ? preg_match('/\|\s*raw\b/', $line) : preg_match('/\bnew\s+\\\\?(?:Twig\\\\)?Markup\s*\(/', $line)) {
                    $raw[] = [$file, $i + 1, $type === 'twig' ? '|raw' : 'new Markup'];
                }
            }
        }

        $baseline = Config::get('console.raw_baseline');
        $count = count($raw);

        if (!is_int($baseline)) {
            $report->info('raw', "{$count} uses of |raw (templates) and new Markup (PHP)", null, null,
                "Set console.raw_baseline to {$count} to get warned about new ones.");
        } elseif ($count > $baseline) {
            $report->warning('raw', "{$count} uses of |raw and new Markup, baseline is {$baseline}; check the new ones below", Paths::base('config/console.php'));
            foreach ($raw as [$file, $line, $what]) {
                $report->info('raw', $what, $file, $line);
            }
        } elseif ($count < $baseline) {
            $report->info('raw', "{$count} uses of |raw and new Markup, below the baseline of {$baseline}", Paths::base('config/console.php'), null,
                "Lower console.raw_baseline to {$count}.");
        }
    }
}
