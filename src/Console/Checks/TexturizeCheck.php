<?php

declare(strict_types=1);

namespace Gaffer\Console\Checks;

use Gaffer\Console\Report;
use Gaffer\Console\Templates;
use Gaffer\Console\ThemeFiles;
use Gaffer\Gaffer;
use Gaffer\Paths;

/**
 * A ">" inside an attribute value in a template that block output uses. Block
 * output is post content, so wptexturize runs over it, and wptexturize takes
 * the first ">" as the end of the tag: the rest of the attribute is text to it,
 * and its quotes become curly ones (`[&>svg]:size-8"` → `size-8″`). The HTML
 * breaks without an error. Warnings (a pattern match), and nothing at all when the
 * theme turns wptexturize off.
 */
final class TexturizeCheck implements Check
{
    /**
     * Ways a theme turns wptexturize off: the run_wptexturize filter returning false
     * ('__return_false', __return_false(...), a closure or arrow function returning false),
     * or wptexturize taken off the_content.
     */
    private const string OFF = '/\badd_filter\(\s*[\'"]run_wptexturize[\'"]\s*,\s*(?:[\'"]__return_false[\'"]|__return_false\(\.\.\.\)'
        . '|(?:static\s+)?fn\s*\([^)]*\)\s*(?::\s*\??bool\s*)?=>\s*false\b'
        . '|(?:static\s+)?function\s*\([^)]*\)\s*(?::\s*\??bool\s*)?\{\s*return\s+false\s*;\s*\})'
        . '|\bremove_filter\(\s*[\'"]the_content[\'"]\s*,\s*[\'"]wptexturize[\'"]/i';

    #[\Override]
    public function run(Report $report): void
    {
        // Read from the theme's code, not apply_filters(): doctor runs this before WordPress loads.
        foreach (ThemeFiles::find(['php']) as $file) {
            if (self::turns_off((string) file_get_contents($file))) {
                return;
            }
        }

        Gaffer::twig();
        $templates = Templates::all();

        foreach (self::block_templates($templates) as $name) {
            $lines = self::lines((string) file_get_contents($templates[$name]));
            if ($lines !== []) {
                // Lines in the hint, not the message: doctor-baseline.json matches messages, and lines shift.
                $report->warning('blocks', 'A > inside an attribute: wptexturize breaks block output there', $templates[$name], $lines[0],
                    'On line ' . implode(', ', $lines) . '. Write it as &gt; (the browser decodes it back), or move it out of the markup (a CSS rule, an Alpine.data() method).');
            }
        }
    }

    /** Whether this PHP code turns wptexturize off (OFF). */
    public static function turns_off(string $code): bool
    {
        return (bool) preg_match(self::OFF, $code);
    }

    /**
     * Lines with a ">" inside a quoted attribute value of an HTML tag. Twig tags,
     * output and comments don't count (they aren't the markup), nor do
     * <script>/<style> contents and HTML comments.
     *
     * @return list<int>
     */
    public static function lines(string $source): array
    {
        $html = (string) preg_replace_callback('/\{#.*?#\}|\{%.*?%\}|\{\{.*?\}\}/s',
            static fn(array $m): string => (string) preg_replace('/[^\n]/', ' ', $m[0]), $source);
        $lines = [];
        $length = strlen($html);

        for ($i = 0; $i < $length; $i++) {
            if ($html[$i] !== '<') {
                continue;
            }
            if (substr($html, $i, 4) === '<!--') {
                $end = strpos($html, '-->', $i);
                $i = $end === false ? $length : $end + 2;
                continue;
            }
            if (!preg_match('/\G<([a-z][a-z0-9-]*)/Ai', $html, $m, 0, $i)) {
                continue;
            }
            $quote = null;
            for ($j = $i + strlen($m[0]); $j < $length; $j++) {
                $char = $html[$j];
                if ($quote !== null) {
                    if ($char === $quote) {
                        $quote = null;
                    } elseif ($char === '>') {
                        $lines[substr_count($html, "\n", 0, $j) + 1] = true;
                    }
                } elseif ($char === '"' || $char === "'") {
                    $quote = $char;
                } elseif ($char === '>') {
                    break;
                }
            }
            $i = $j;
            $tag = strtolower($m[1]);
            if ($tag === 'script' || $tag === 'style') {
                $end = stripos($html, "</{$tag}", $i);
                $i = $end === false ? $length : $end;
            }
        }

        return array_keys($lines);
    }

    /**
     * Templates block output uses: the blocks' own (@block/...), what the blocks'
     * PHP renders, and everything those include.
     *
     * @param array<string, string> $templates
     * @return list<string>
     */
    private static function block_templates(array $templates): array
    {
        $queue = array_values(array_filter(array_keys($templates), static fn(string $name): bool => str_starts_with($name, '@block/')));
        foreach (is_dir(Paths::blocks()) ? ThemeFiles::find(['php'], Paths::blocks()) : [] as $file) {
            foreach (Templates::php_renders((string) file_get_contents($file)) as $render) {
                if ($render['template'] !== null) {
                    $queue[] = $render['template'];
                } elseif ($render['pattern'] !== null) {
                    array_push($queue, ...array_filter(array_keys($templates), static fn(string $name): bool => fnmatch((string) $render['pattern'], $name)));
                }
            }
        }

        $found = [];
        while ($queue !== []) {
            $name = array_shift($queue);
            if (isset($found[$name]) || !isset($templates[$name])) {
                continue;
            }
            $found[$name] = true;
            $module = Templates::parse($name);
            foreach ($module === null ? [] : Templates::references($module) as $ref) {
                if ($ref['template'] !== null) {
                    $queue[] = $ref['template'];
                } elseif ($ref['pattern'] !== null) {
                    array_push($queue, ...array_filter(array_keys($templates), static fn(string $name): bool => fnmatch($ref['pattern'], $name)));
                }
            }
        }

        return array_keys($found);
    }
}
