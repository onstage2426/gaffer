<?php

declare(strict_types=1);

namespace Gaffer\Console\Checks;

use Gaffer\Console\Report;
use Gaffer\Console\ThemeFiles;
use Gaffer\FieldGroups;

/**
 * Line-level scans of the theme's PHP and Twig: hardcoded IDs, uploads paths, and
 * trusted HTML: |raw in templates and new Markup(...) in PHP (the same decision, made
 * in PHP). Each says why it's trusted where it's used: a `{# trusted: why #}` (Twig)
 * or `// trusted: why` (PHP) comment on its line or alone on the line above.
 */
final class SourceCheck implements Check
{
    private const array PATTERNS = [
        'php' => '/\b(?:[A-Z]\w*::from|get_post|get_term|wp_get_nav_menu_items|GFAPI::(?:submit_form|get_form|get_entries)|gravity_form)\(\s*\d+\s*[,)]|\[gravityforms? id=["\']?\d+/',
        'twig' => '/\bwp\.ID\s*==\s*\d+|\bid\(\)\s*==\s*\d+/',
    ];

    /** A trust comment with a reason. */
    private const array TRUSTED = [
        'php' => '/(?:\/\/|\/\*|#)\s*trusted:\s*\S/',
        'twig' => '/\{#-?\s*trusted:\s*[^#\s]/',
    ];

    /** A line that is only a trust comment: it covers the next line. */
    private const array TRUSTED_LINE = [
        'php' => '/^\s*(?:\/\/|\/\*|#)\s*trusted:\s*\S/',
        'twig' => '/^\s*\{#-?\s*trusted:\s*[^#\s].*#\}\s*$/',
    ];

    #[\Override]
    public function run(Report $report): void
    {
        $raw = [];

        foreach (ThemeFiles::find(['php', 'twig']) as $file) {
            $type = pathinfo($file, PATHINFO_EXTENSION);

            $previous = '';
            foreach (file($file) ?: [] as $i => $line) {
                if (preg_match(self::PATTERNS[$type], $line, $m)) {
                    $report->warning('source', 'Hardcoded ID: ' . trim($m[0], ' ,)') . ')', $file, $i + 1,
                        'IDs differ per site/database. Use a menu location, config value or option.');
                }
                if (str_contains($line, 'wp-content/uploads/') && !FieldGroups::is_definition($file)) {
                    $report->warning('source', 'Hardcoded uploads path', $file, $i + 1,
                        'Theme graphics belong in the theme (e.g. views/components/icons/).');
                }
                if (($type === 'twig' ? preg_match('/\|\s*raw\b/', $line) : preg_match('/\bnew\s+\\\\?(?:Twig\\\\)?Markup\s*\(/', $line))
                    && !preg_match(self::TRUSTED[$type], $line) && !preg_match(self::TRUSTED_LINE[$type], $previous)) {
                    $raw[] = [$file, $i + 1, $type === 'twig' ? '|raw' : 'new Markup'];
                }
                $previous = $line;
            }
        }

        foreach ($raw as [$file, $line, $what]) {
            $report->warning('raw', "{$what} without a trust comment", $file, $line, $what === '|raw'
                ? 'Say why this HTML is trusted, on the line or alone on the line above: {{ post.content()|raw }}{# trusted: WordPress content #}. Untrusted values are printed escaped.'
                : 'Say why this HTML is trusted, on the line or alone on the line above: // trusted: rendered by Theme\\Search. Or pass the string to a template and use |raw there.');
        }
    }
}
