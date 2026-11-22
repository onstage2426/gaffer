<?php

declare(strict_types=1);

namespace Gaffer\Console\Render;

use Gaffer\Console\ChildProcess;
use Gaffer\Console\Env;
use Gaffer\Paths;
use Gaffer\Storage;

/**
 * A page for the headless browser (bin/*.cjs): a path of this site, rendered the way doctor
 * renders it and handed to the browser as the page's answer (everything else comes from the
 * site), or another page as it is: a design's URL (with DESIGN_COOKIE from the theme's .env
 * when it sits behind a login) or a local HTML file. And the Node scripts that drive it.
 *
 * @internal
 */
final class BrowserPage
{
    /** Seconds a browser script may take (a full page at several widths, with steps). */
    private const int TIMEOUT = 300;

    /** The readable part of a slug at most (folder names stay short; the hash keeps them apart). */
    private const int SLUG_LENGTH = 60;

    /**
     * A target (/path, https://…, or a file) as the browser scripts take it:
     * {url, html?: file, http?: int, cookies: [{name, value, url}]}, or why there is none.
     *
     * @param Visitor|null $visitor who renders a path (a session's cookies go to the browser too)
     * @return array<string, mixed>|string
     */
    public static function target(string $target, ?string $url = null, ?Visitor $visitor = null, ?string $entry = null, string $name = 'page'): array|string
    {
        if (preg_match('#^https?://#i', $target)) {
            return ['url' => $target, 'cookies' => self::design_cookies($target)];
        }
        if (!str_starts_with($target, '/') || is_file($target)) {
            $file = realpath($target);
            if ($file === false || !is_file($file)) {
                return "\"{$target}\" is no URL path (/…), URL (https://…) or file";
            }
            if ((string) Env::get('PLAYWRIGHT_WS_ENDPOINT') !== '') {
                // The browser runs on the Playwright server: a file:// URL points into its own disk.
                return "{$target} is a file on this machine, and the browser at PLAYWRIGHT_WS_ENDPOINT runs on another one: give the design's URL (https://…)";
            }
            return ['url' => 'file://' . $file, 'cookies' => []];
        }

        $render = new Renderer($url, static fn(string $p) => null, $visitor, $entry)->render([$target], true, as_live: false)[$target];
        if (is_string($render)) {
            return "The render of {$target} failed: {$render}";
        }
        if ($render['status'] === 'error') {
            return "The render of {$target} failed: {$render['error']} ({$render['file']}:{$render['line']})";
        }
        if ($render['status'] === 'redirect') {
            return "{$target} redirects ({$render['http']}) to {$render['redirect']}: use that instead";
        }

        $session_cookies = $visitor?->cookies() ?? [];
        $out = Storage::theme_dir('screenshots/' . self::slug($target));
        file_put_contents("{$out}/{$name}.html", $render['html'] ?? '');
        $home = (array) parse_url(\home_url('/'));
        $origin = ($home['scheme'] ?? 'https') . '://' . ($home['host'] ?? 'localhost') . (isset($home['port']) ? ":{$home['port']}" : '');

        return [
            'url' => $origin . $target,
            'html' => "{$out}/{$name}.html",
            'http' => $render['http'],
            'cookies' => array_map(static fn(string $n, string $v): array => ['name' => $n, 'value' => $v, 'url' => $origin], array_keys($session_cookies), array_values($session_cookies)),
            'out' => $out,
        ];
    }

    /**
     * The folder name for a path's files: "/" is "home", "/winkel/?s=x" "winkel-s-x-" and a short
     * hash of the path, so paths that read alike ("/a-b/", "/a/b/") get their own folders; the
     * readable part is cut at SLUG_LENGTH characters.
     */
    public static function slug(string $path): string
    {
        if ($path === '/') {
            return 'home';
        }
        $readable = trim(substr(trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($path)), '-'), 0, self::SLUG_LENGTH), '-');

        return ($readable !== '' ? "{$readable}-" : '') . substr(hash('sha256', $path), 0, 8);
    }

    /**
     * --width of a command that renders at one width (render:styles, render:compare).
     *
     * @throws \InvalidArgumentException on a list or anything but a width in pixels
     */
    public static function width(string $value): int
    {
        if (str_contains($value, ',')) {
            throw new \InvalidArgumentException("--width takes one width here, not \"{$value}\": run the command once per width");
        }

        return self::widths($value)[0];
    }

    /**
     * --width of render:screenshot: widths in pixels, comma-separated ("390,1440").
     *
     * @return non-empty-list<int>
     * @throws \InvalidArgumentException on anything but widths between 200 and 3840
     */
    public static function widths(string $value): array
    {
        $widths = [];
        foreach (explode(',', $value) as $width) {
            $width = trim($width);
            if (!ctype_digit($width) || (int) $width < 200 || (int) $width > 3840) {
                throw new \InvalidArgumentException("--width takes widths in pixels between 200 and 3840, e.g. 390,1440 (not \"{$value}\")");
            }
            $widths[] = (int) $width;
        }

        return $widths;
    }

    /**
     * DESIGN_COOKIE in the theme's .env ("name=value; other=value"), for a design site behind a
     * login: sent to every URL that isn't this site.
     *
     * @return list<array{name: string, value: string, url: string}>
     */
    public static function design_cookies(string $url): array
    {
        $parts = (array) parse_url($url);
        $site = (array) parse_url(function_exists('home_url') ? \home_url('/') : '');
        $cookie = Env::get('DESIGN_COOKIE');
        if ($cookie === null || ($parts['host'] ?? null) === ($site['host'] ?? null)) {
            return [];
        }
        $origin = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ":{$parts['port']}" : '');
        $cookies = [];
        foreach (explode(';', $cookie) as $pair) {
            [$name, $value] = array_map('trim', explode('=', $pair, 2)) + [1 => ''];
            if ($name !== '') {
                $cookies[] = ['name' => $name, 'value' => $value, 'url' => $origin];
            }
        }

        return $cookies;
    }

    /**
     * Runs bin/{script} with these options (JSON) and returns its last output line, decoded.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public static function run(string $script, array $options): array
    {
        $options = ['theme' => Paths::base(), 'endpoint' => Env::get('PLAYWRIGHT_WS_ENDPOINT'), ...$options];
        $command = ['node', dirname(__DIR__, 3) . "/bin/{$script}", (string) json_encode($options, JSON_UNESCAPED_SLASHES)];
        $process = ChildProcess::run($command, Paths::base(), self::TIMEOUT);
        if ($process === null) {
            return ['error' => 'Could not start Node (`node`), which runs the browser.'];
        }
        if ($process['timed_out']) {
            return ['error' => "The browser script took longer than " . self::TIMEOUT . ' s and was stopped (a page that never settles, a step waiting for an element that never shows?)'];
        }
        ['stdout' => $stdout, 'stderr' => $stderr] = $process;

        $lines = array_filter(explode("\n", trim($stdout)));
        $result = json_decode((string) end($lines), true);
        if (!is_array($result)) {
            $tail = trim(mb_substr($stderr !== '' ? $stderr : $stdout, -500));
            return ['error' => 'The browser script gave no result' . ($tail !== '' ? ": {$tail}" : ' (is Node, `node`, installed?)')];
        }

        /** @var array<string, mixed> $result */
        return $result;
    }
}
