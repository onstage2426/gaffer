<?php

declare(strict_types=1);

namespace Gaffer\Console\Checks;

use Gaffer\Console\Report;
use Gaffer\Console\Templates;
use Gaffer\Console\ThemeFiles;
use Gaffer\Paths;

/**
 * A theme on htmx 4 (node_modules/htmx.org, else package.json) uses no htmx 2 names: renamed and
 * removed attributes, the old event names (in hx-on, hx-trigger and JavaScript), and attributes
 * a parent passes on without `:inherited`. htmx ignores what it doesn't know, so each of these
 * fails silently in the browser. Inheritance comes from htmx's own upgrade checker
 * (node_modules/htmx.org/dist/scripts/upgrade-check.py, with python3); the rest is matched here,
 * also the hx-on spelling `hx-on::after-request` that checker misses.
 */
final class HtmxCheck implements Check
{
    /** htmx 2 attribute => what htmx 4 has instead. */
    private const array ATTRIBUTES = [
        'hx-disabled-elt' => 'hx-disable (htmx 2\'s hx-disable is htmx 4\'s hx-ignore: rename that one first)',
        'hx-ext' => 'nothing: importing the extension is enough',
        'hx-vars' => 'hx-vals with the js: prefix',
        'hx-params' => 'the htmx:config:request event',
        'hx-request' => 'hx-config',
        'hx-history' => 'nothing (history no longer uses localStorage)',
        'hx-disinherit' => 'nothing (inheritance is explicit)',
        'hx-inherit' => 'the :inherited modifier on each attribute',
        'hx-prompt' => 'the hx-prompt extension',
    ];

    #[\Override]
    public function run(Report $report): void
    {
        if (self::major() !== 4) {
            return;
        }
        $files = array_unique([...array_values(Templates::all()), ...ThemeFiles::find(['php', 'js', 'mjs', 'ts'])]);
        $boosted = false;
        foreach ($files as $file) {
            $boosted = $boosted || str_contains((string) file_get_contents($file), 'hx-boost');
        }
        foreach ($files as $file) {
            $code = (string) file_get_contents($file);
            foreach (self::problems($code, $boosted) as [$offset, $message, $hint]) {
                $report->error('htmx', $message, $file, substr_count($code, "\n", 0, $offset) + 1, $hint);
            }
        }
        foreach ($this->inheritance() as [$file, $line, $message]) {
            $report->error('htmx', $message, $file, $line,
                'htmx 4 inherits nothing by itself: put the attribute on the element that makes the request, or write it as hx-target:inherited="…".');
        }
    }

    /** The major version of htmx the theme builds with, null when it has none. */
    public static function major(): ?int
    {
        $installed = json_decode((string) @file_get_contents(Paths::base('node_modules/htmx.org/package.json')), true);
        $version = is_array($installed) && is_string($installed['version'] ?? null) ? $installed['version'] : null;
        if ($version === null) {
            $package = json_decode((string) @file_get_contents(Paths::base('package.json')), true);
            $version = is_array($package) ? ($package['dependencies']['htmx.org'] ?? $package['devDependencies']['htmx.org'] ?? null) : null;
        }

        return is_string($version) && preg_match('/(\d+)/', $version, $m) ? (int) $m[1] : null;
    }

    /**
     * htmx 2 names in a template or script: [offset, message, hint].
     *
     * @param bool $boosted the theme uses hx-boost somewhere (then htmx may send any form)
     * @return list<array{int, string, string}>
     */
    public static function problems(string $code, bool $boosted = false): array
    {
        $problems = [];
        $hx_on = [];
        foreach (self::ATTRIBUTES as $old => $new) {
            if (preg_match_all('/(?<![\w-])(?:data-)?' . preg_quote($old, '/') . '(?=[\s=>:\/])/', $code, $m, PREG_OFFSET_CAPTURE)) {
                foreach ($m[0] as [, $offset]) {
                    $problems[] = [$offset, "{$old} is htmx 2", "htmx 4: {$new}."];
                }
            }
        }
        // <input name="form"> inside a form: form.form is then that input (DOM named properties),
        // and htmx 4 reads elt.form, form.enctype, form.elements, form.noValidate
        if (preg_match_all('/<(?:input|select|textarea|button)\b[^>]*\bname=["\'](form|enctype|elements|novalidate|action|method|submit|reportValidity)["\']/i', $code, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[1] as [$name, $offset]) {
                if (!$boosted && !self::in_htmx_form($code, $offset)) {
                    continue; // a plain form (WooCommerce's account forms send name="action"): htmx never reads it
                }
                $problems[] = [$offset, "An input named \"{$name}\" hides the <form>'s own {$name} property", 'htmx 4 reads the form through those properties and fails (FormData of an input): rename the input.'];
            }
        }
        // hx-on::after-request / hx-on:htmx:afterRequest: htmx 4 events are colon-separated
        if (preg_match_all('/hx-on(?:::|:htmx:)([a-z]+(?:-[a-z]+)+|[a-z]+[A-Z][A-Za-z]*)(?=[\s=])/', $code, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[1] as [$event, $offset]) {
                $new = strtolower((string) preg_replace('/(?<=[a-z])(?=[A-Z])|-/', ':', $event));
                $problems[] = [$offset, "hx-on event \"{$event}\" is htmx 2's name", "htmx 4: hx-on::{$new}."];
                $hx_on[$offset] = true;
            }
        }
        // htmx:afterSwap, htmx:load in JavaScript, hx-trigger or hx-on
        if (preg_match_all('/htmx:(load\b|[a-z]+[A-Z][A-Za-z]*)/', $code, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as [$event, $offset]) {
                if (isset($hx_on[$offset + 5])) {
                    continue; // hx-on:htmx:afterSwap: reported above
                }
                $problems[] = [$offset, "The event {$event} is htmx 2's", 'htmx 4 names: htmx:after:process (was htmx:load), htmx:after:swap, htmx:before:request, htmx:config:request, htmx:response:error, htmx:error (node_modules/htmx.org/dist/skills/htmx-upgrade-from-htmx2.md has the table).'];
            }
        }

        usort($problems, static fn(array $a, array $b): int => $a[0] <=> $b[0]);

        return $problems;
    }

    /**
     * Whether the input at $offset may be in a form htmx sends: inside a <form> in this file that
     * has an hx- attribute on itself or an element in it, or outside any <form> here (a partial,
     * which may be included into one).
     */
    private static function in_htmx_form(string $code, int $offset): bool
    {
        $before = substr($code, 0, $offset);
        $open = preg_match_all('/<form\b/i', $before, $m, PREG_OFFSET_CAPTURE) ? end($m[0])[1] : null;
        if ($open === null || preg_match('/<\/form\s*>/i', substr($before, $open))) {
            return true;
        }
        $close = preg_match('/<\/form\s*>/i', $code, $c, PREG_OFFSET_CAPTURE, $offset) ? $c[0][1] : strlen($code);

        return str_contains(substr($code, $open, $close - $open), 'hx-');
    }

    /**
     * htmx's own checker's inheritance findings: [file, line, message].
     *
     * @return list<array{string, int, string}>
     */
    private function inheritance(): array
    {
        $script = Paths::base('node_modules/htmx.org/dist/scripts/upgrade-check.py');
        $python = trim((string) shell_exec('command -v python3 2>/dev/null'));
        if (!is_file($script) || $python === '') {
            return [];
        }
        $dirs = array_values(array_filter(array_map(Paths::base(...), ['views', 'blocks', 'woocommerce', 'inc', 'app', 'assets']), is_dir(...)));
        $command = [$python, '-I', $script, '--no-color', '--ext', '.twig', ...$dirs];
        $output = (string) shell_exec(implode(' ', array_map(escapeshellarg(...), $command)) . ' 2>/dev/null');
        $found = [];
        foreach (explode("\n", $output) as $line) {
            if (preg_match('/^(.+?):(\d+): \[inheritance\] (.+)$/', trim($line), $m)) {
                $file = str_starts_with($m[1], '/') ? $m[1] : Paths::base($m[1]);
                if (!self::sets_itself($file, $m[3])) {
                    $found[] = [$file, (int) $m[2], $m[3]];
                }
            }
        }

        return $found;
    }

    /**
     * Whether the descendant htmx's checker names ("hx-target needs :inherited suffix (descendant on
     * line 32 has hx-post)") sets that attribute itself: its checker doesn't look, so a fixed
     * template would stay reported.
     */
    public static function sets_itself(string $file, string $message): bool
    {
        if (!preg_match('/^(hx-[a-z-]+) needs :inherited suffix \(descendant on line (\d+)/', $message, $m)) {
            return false;
        }
        $lines = explode("\n", (string) @file_get_contents($file));
        $tag = implode("\n", array_slice($lines, (int) $m[2] - 1, 20));
        $end = strpos($tag, '>');
        $tag = $end === false ? $tag : substr($tag, 0, $end);

        return (bool) preg_match('/(?<![\w-])' . preg_quote($m[1], '/') . '(?::inherited)?=/', $tag);
    }
}
