<?php

declare(strict_types=1);

namespace Gaffer\Console\Render;

use Dom\HTMLDocument;
use InvalidArgumentException;

/**
 * Compares two renders of a page: normalized to one tag or text run per line
 * (entities decoded, whitespace collapsed), so markup that means the same
 * compares equal, then diffed line by line. Pure PHP, no WordPress.
 */
final class HtmlDiff
{
    /** Above this many line pairs between the first and last change, the diff shows the whole range instead of aligning it. */
    private const int MAX_ALIGN = 1_000_000;

    /**
     * The outer HTML of every element matching a CSS selector (also the MCP render tool's).
     *
     * @return list<string>
     * @throws InvalidArgumentException on an invalid selector
     */
    public static function select_all(string $html, string $selector): array
    {
        $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);
        try {
            $nodes = $document->querySelectorAll($selector);
        } catch (\Throwable $e) {
            throw new InvalidArgumentException("Invalid selector \"{$selector}\": {$e->getMessage()}", 0, $e);
        }

        return array_map(static fn(\Dom\Node $node): string => $document->saveHtml($node), iterator_to_array($nodes, false));
    }

    /**
     * The page without the matches of these regular expressions (with delimiters):
     * timestamps, nonces, random order. They match the page as rendered, before
     * select_all() and lines() rewrite it.
     *
     * @param list<string> $ignore
     * @throws InvalidArgumentException on an invalid expression
     */
    public static function without(string $html, array $ignore): string
    {
        foreach ($ignore as $pattern) {
            $html = @preg_replace($pattern, '', $html);
            if (!is_string($html)) {
                throw new InvalidArgumentException("Invalid regular expression {$pattern} (with delimiters, e.g. /data-delay=\"\\d+\"/)");
            }
        }

        return $html;
    }

    /**
     * One tag or text run per line: entities decoded, whitespace collapsed.
     *
     * @return list<string>
     */
    public static function lines(string $html): array
    {
        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $html = (string) preg_replace(['/\s+/u', '/\s*</', '/>\s*/'], [' ', "\n<", ">\n"], $html);

        return array_values(array_filter(array_map('trim', explode("\n", $html)), static fn(string $line): bool => $line !== ''));
    }

    /**
     * The changed lines ("- " old, "+ " new) with $context unchanged lines ("  ") around
     * them and "…" between distant changes; [] when equal. At most $max lines.
     *
     * @param list<string> $old
     * @param list<string> $new
     * @return list<string>
     */
    public static function diff(array $old, array $new, int $context = 2, int $max = 40): array
    {
        if ($old === $new) {
            return [];
        }

        $start = 0;
        while ($start < count($old) && $start < count($new) && $old[$start] === $new[$start]) {
            $start++;
        }
        $end = 0;
        while ($end < count($old) - $start && $end < count($new) - $start && $old[count($old) - 1 - $end] === $new[count($new) - 1 - $end]) {
            $end++;
        }

        $script = [];
        foreach (array_slice($old, max(0, $start - $context), min($start, $context)) as $line) {
            $script[] = ['  ', $line];
        }
        array_push($script, ...self::align(array_slice($old, $start, count($old) - $start - $end), array_slice($new, $start, count($new) - $start - $end)));
        foreach (array_slice($old, count($old) - $end, $context) as $line) {
            $script[] = ['  ', $line];
        }

        // Keep changes and the context around them.
        $keep = [];
        foreach ($script as $i => [$op]) {
            if ($op !== '  ') {
                for ($j = max(0, $i - $context); $j <= min(count($script) - 1, $i + $context); $j++) {
                    $keep[$j] = true;
                }
            }
        }
        $out = [];
        $last = null;
        foreach ($script as $i => [$op, $line]) {
            if (!isset($keep[$i])) {
                continue;
            }
            if ($last !== null && $i > $last + 1) {
                $out[] = '…';
            }
            $out[] = $op . $line;
            $last = $i;
        }

        if (count($out) > $max) {
            $more = count($out) - $max;
            $out = [...array_slice($out, 0, $max), "… {$more} more lines"];
        }

        return $out;
    }

    /**
     * An edit script for two blocks of lines (longest common subsequence).
     *
     * @param list<string> $a
     * @param list<string> $b
     * @return list<array{string, string}>
     */
    private static function align(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);
        if ($n * $m > self::MAX_ALIGN) {
            return [...array_map(static fn(string $l): array => ['- ', $l], $a), ...array_map(static fn(string $l): array => ['+ ', $l], $b)];
        }

        // $lcs[$i][$j]: common lines of $a from $i and $b from $j.
        $lcs = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $lcs[$i][$j] = $a[$i] === $b[$j] ? $lcs[$i + 1][$j + 1] + 1 : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
            }
        }

        $script = [];
        $i = $j = 0;
        while ($i < $n || $j < $m) {
            if ($i < $n && $j < $m && $a[$i] === $b[$j]) {
                $script[] = ['  ', $a[$i++]];
                $j++;
            } elseif ($j < $m && ($i === $n || $lcs[$i][$j + 1] > $lcs[$i + 1][$j])) {
                $script[] = ['+ ', $b[$j++]];
            } else {
                $script[] = ['- ', $a[$i++]];
            }
        }

        return $script;
    }
}
