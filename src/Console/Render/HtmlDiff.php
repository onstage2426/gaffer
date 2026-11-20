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
    /** Up to this many line pairs, two blocks are aligned exactly (LCS); larger ones by unique lines first. */
    private const int MAX_ALIGN = 250_000;

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
     * Whether a hunk only moves lines around: the removed lines are the added ones in another
     * order (random suggestions, items with equal counts, a sitemap sorted differently).
     *
     * @param list<string> $hunk
     */
    public static function reorder_only(array $hunk): bool
    {
        $side = static function (string $op) use ($hunk): array {
            $lines = array_map(static fn(string $line): string => substr($line, 2), array_values(array_filter($hunk, static fn(string $line): bool => str_starts_with($line, $op))));
            sort($lines);
            return $lines;
        };
        $removed = $side('- ');

        return $removed !== [] && $removed === $side('+ ');
    }

    /**
     * A long line that changed into another long line (a config attribute, an Instagram
     * feed), shown as the part that differs with $context characters around it:
     * "- …data-config="{…"ms":32,…}"…".
     *
     * @param list<string> $hunk
     * @return list<string>
     */
    public static function shorten(array $hunk, int $width = 200, int $context = 40): array
    {
        foreach ($hunk as $i => $line) {
            $next = $hunk[$i + 1] ?? '';
            if (!str_starts_with($line, '- ') || !str_starts_with($next, '+ ') || (mb_strlen($line) <= $width && mb_strlen($next) <= $width)) {
                continue;
            }
            // Characters once, not mb_substr() per character: these lines run to kilobytes.
            [$old, $new] = [mb_str_split(mb_substr($line, 2)), mb_str_split(mb_substr($next, 2))];
            $max = min(count($old), count($new));
            $prefix = 0;
            while ($prefix < $max && $old[$prefix] === $new[$prefix]) {
                $prefix++;
            }
            $suffix = 0;
            while ($suffix < $max - $prefix && $old[count($old) - 1 - $suffix] === $new[count($new) - 1 - $suffix]) {
                $suffix++;
            }
            $cut = static function (array $chars) use ($prefix, $suffix, $context): string {
                $from = max(0, $prefix - $context);
                $to = count($chars) - max(0, $suffix - $context);
                return ($from > 0 ? '…' : '') . implode('', array_slice($chars, $from, $to - $from)) . ($to < count($chars) ? '…' : '');
            };
            $hunk[$i] = '- ' . $cut($old);
            $hunk[$i + 1] = '+ ' . $cut($new);
        }

        return $hunk;
    }

    /**
     * A diff() split at its "…" lines: each change with its context.
     *
     * @param list<string> $diff
     * @return list<list<string>>
     */
    public static function hunks(array $diff): array
    {
        $hunks = [[]];
        foreach ($diff as $line) {
            if ($line === '…') {
                $hunks[] = [];
            } else {
                $hunks[array_key_last($hunks)][] = $line;
            }
        }

        return array_values(array_filter($hunks, static fn(array $hunk): bool => $hunk !== []));
    }

    /**
     * An edit script for two blocks of lines. Small blocks: longest common subsequence.
     * Large ones (a 700-item menu): patience diff, anchored on lines that occur once on
     * each side, recursing between the anchors, so a long page still lines up.
     *
     * @param list<string> $a
     * @param list<string> $b
     * @return list<array{string, string}>
     */
    private static function align(array $a, array $b): array
    {
        // Common start and end first: cheap, and leaves the anchors less to do.
        $head = [];
        while ($a !== [] && $b !== [] && $a[0] === $b[0]) {
            $head[] = ['  ', array_shift($a)];
            array_shift($b);
        }
        $tail = [];
        while ($a !== [] && $b !== [] && end($a) === end($b)) {
            array_unshift($tail, ['  ', array_pop($a)]);
            array_pop($b);
        }

        if (count($a) * count($b) <= self::MAX_ALIGN) {
            return [...$head, ...self::lcs($a, $b), ...$tail];
        }

        $anchors = self::anchors($a, $b);
        if ($anchors === []) {
            return [...$head, ...array_map(static fn(string $l): array => ['- ', $l], $a), ...array_map(static fn(string $l): array => ['+ ', $l], $b), ...$tail];
        }

        $script = $head;
        $i = $j = 0;
        foreach ([...$anchors, [count($a), count($b)]] as [$ai, $bj]) {
            array_push($script, ...self::align(array_slice($a, $i, $ai - $i), array_slice($b, $j, $bj - $j)));
            if ($ai < count($a)) {
                $script[] = ['  ', $a[$ai]];
            }
            [$i, $j] = [$ai + 1, $bj + 1];
        }

        return [...$script, ...$tail];
    }

    /**
     * Lines that occur exactly once in each block, as [index in a, index in b], the longest
     * run of them in the same order on both sides (patience sorting).
     *
     * @param list<string> $a
     * @param list<string> $b
     * @return list<array{int, int}>
     */
    private static function anchors(array $a, array $b): array
    {
        $once = static function (array $lines): array {
            $seen = [];
            foreach ($lines as $i => $line) {
                $seen[$line] = isset($seen[$line]) ? -1 : $i;
            }
            return array_filter($seen, static fn(int $i): bool => $i >= 0);
        };
        $in_b = $once($b);
        $pairs = [];
        foreach ($once($a) as $line => $i) {
            if (isset($in_b[$line])) {
                $pairs[$i] = $in_b[$line];
            }
        }
        ksort($pairs);

        // Longest increasing run of b-indexes, in a's order.
        $tops = [];
        $links = [];
        foreach ($pairs as $i => $j) {
            $lo = 0;
            $hi = count($tops);
            while ($lo < $hi) {
                $mid = intdiv($lo + $hi, 2);
                $pairs[$tops[$mid]] < $j ? $lo = $mid + 1 : $hi = $mid;
            }
            $tops[$lo] = $i;
            $links[$i] = $lo > 0 ? $tops[$lo - 1] : null;
        }

        $run = [];
        for ($i = $tops === [] ? null : end($tops); $i !== null; $i = $links[$i]) {
            $run[] = [$i, $pairs[$i]];
        }

        return array_reverse($run);
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     * @return list<array{string, string}>
     */
    private static function lcs(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);

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
