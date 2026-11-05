<?php

declare(strict_types=1);

namespace Gaffer\Console\Checks;

use Gaffer\Console\Report;
use Gaffer\Console\Templates;
use Gaffer\Gaffer;

/**
 * Mistakes inside one HTML tag of a template, which render without an error:
 * an alt attribute next to image.attrs() (two alts: browsers keep the first, the
 * media library's), and a class in both the static class and :class (Alpine adds
 * :class's classes and never removes static ones, so that state never goes away).
 */
final class TagsCheck implements Check
{
    #[\Override]
    public function run(Report $report): void
    {
        Gaffer::twig();

        foreach (Templates::all() as $file) {
            foreach (self::problems((string) file_get_contents($file)) as [$level, $message, $line, $hint]) {
                $report->{$level}('views', $message, $file, $line, $hint);
            }
        }
    }

    /**
     * @return list<array{'error'|'warning', string, int, string}>
     */
    public static function problems(string $source): array
    {
        $problems = [];
        foreach (self::tags($source) as $tag) {
            $names = array_map(static fn(array $a): string => strtolower($a[0]), $tag['attrs']);
            $twig = implode(' ', $tag['twig']);

            if (preg_match('/\.attrs\(/', $twig) && in_array('alt', $names, true)) {
                $problems[] = ['error', 'An alt attribute next to .attrs(), which writes alt itself: the tag has two', $tag['line'],
                    "Browsers keep the first (the media library's alt). For a decorative image: image.attrs('large', decorative: true), which writes alt=\"\"; else drop the alt attribute."];
            }

            $static = [];
            $dynamic = '';
            foreach ($tag['attrs'] as [$name, $value]) {
                $name = strtolower($name);
                if ($name === 'class' && $value !== null) {
                    // Twig output in the value isn't a class name.
                    $plain = (string) preg_replace('/\{\{.*?\}\}|\{%.*?%\}/s', ' ', $value);
                    $static = array_merge($static, preg_split('/\s+/', trim($plain), -1, PREG_SPLIT_NO_EMPTY) ?: []);
                } elseif (($name === ':class' || $name === 'x-bind:class') && $value !== null) {
                    $dynamic .= ' ' . $value;
                }
            }
            if ($static !== [] && $dynamic !== '') {
                preg_match_all('/([\'"])(.*?)\1/s', $dynamic, $strings);
                // Every quoted class name (strings, object keys { 'a b': cond }) and unquoted object keys ({ hidden: !open }).
                preg_match_all('/[{,]\s*([a-z_-][\w-]*)\s*:/i', $dynamic, $keys);
                $toggled = preg_split('/\s+/', implode(' ', [...$strings[2], ...$keys[1]]), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                $both = array_values(array_unique(array_intersect($static, $toggled)));
                if ($both !== []) {
                    $problems[] = ['warning', 'A class in both class and :class: Alpine never removes the static one', $tag['line'],
                        implode(' ', $both) . ' stays on when :class turns it off. Put each state\'s classes only in :class (open ? \'a\' : \'b\'), and keep in class what never changes.'];
                }
            }
        }

        return $problems;
    }

    /**
     * The HTML tags of a template: name, attributes (name, value or null; Twig inside
     * a value kept as written) and the Twig output/tags written between attributes.
     * Twig comments, HTML comments and <script>/<style> contents are skipped.
     *
     * @return list<array{name: string, line: int, attrs: list<array{string, ?string}>, twig: list<string>}>
     */
    public static function tags(string $source): array
    {
        $html = (string) preg_replace_callback('/\{#.*?#\}/s', static fn(array $m): string => (string) preg_replace('/[^\n]/', ' ', $m[0]), $source);
        $length = strlen($html);
        $tags = [];

        for ($i = 0; $i < $length; $i++) {
            if ($html[$i] === '{' && in_array($html[$i + 1] ?? '', ['{', '%'], true)) {
                $i = self::twig_end($html, $i) - 1; // a "<" inside Twig isn't a tag
                continue;
            }
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
            $tag = ['name' => strtolower($m[1]), 'line' => substr_count($html, "\n", 0, $i) + 1, 'attrs' => [], 'twig' => []];
            $j = $i + strlen($m[0]);
            while ($j < $length) {
                $char = $html[$j];
                if (ctype_space($char) || $char === '/') {
                    $j++;
                } elseif ($char === '>') {
                    break;
                } elseif ($char === '{' && in_array($html[$j + 1] ?? '', ['{', '%'], true)) {
                    $end = self::twig_end($html, $j);
                    $tag['twig'][] = substr($html, $j, $end - $j);
                    $j = $end;
                } elseif (preg_match('/\G[^\s=>\/"\'{]+/A', $html, $name, 0, $j)) {
                    $j += strlen($name[0]);
                    $value = null;
                    if (preg_match('/\G\s*=\s*/A', $html, $eq, 0, $j)) {
                        $j += strlen($eq[0]);
                        $quote = $html[$j] ?? '';
                        if ($quote === '"' || $quote === "'") {
                            $start = ++$j;
                            while ($j < $length && $html[$j] !== $quote) {
                                $j = $html[$j] === '{' && in_array($html[$j + 1] ?? '', ['{', '%'], true) ? self::twig_end($html, $j) : $j + 1;
                            }
                            $value = substr($html, $start, $j - $start);
                            $j++;
                        } elseif (preg_match('/\G[^\s>]+/A', $html, $bare, 0, $j)) {
                            $value = $bare[0];
                            $j += strlen($bare[0]);
                        }
                    }
                    $tag['attrs'][] = [$name[0], $value];
                } else {
                    $j++;
                }
            }
            $tags[] = $tag;
            $i = $j;
            if ($tag['name'] === 'script' || $tag['name'] === 'style') {
                $end = stripos($html, "</{$tag['name']}", $i);
                $i = $end === false ? $length : $end;
            }
        }

        return $tags;
    }

    /** The offset just past the Twig output or tag starting at $at ({{ … }} or {% … %}), quotes respected. */
    private static function twig_end(string $html, int $at): int
    {
        $close = $html[$at + 1] === '{' ? '}}' : '%}';
        $length = strlen($html);
        $quote = null;
        for ($k = $at + 2; $k < $length; $k++) {
            $char = $html[$k];
            if ($quote !== null) {
                if ($char === '\\') {
                    $k++;
                } elseif ($char === $quote) {
                    $quote = null;
                }
            } elseif ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif (substr($html, $k, 2) === $close) {
                return $k + 2;
            }
        }

        return $length;
    }
}
