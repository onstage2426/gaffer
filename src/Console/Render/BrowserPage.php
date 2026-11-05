<?php

declare(strict_types=1);

namespace Gaffer\Console\Render;

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
    /**
     * A target (/path, https://…, or a file) as the browser scripts take it:
     * {url, html?: file, http?: int, cookies: [{name, value, url}]}, or why there is none.
     *
     * @param list<string> $visitor doctor:render options (--session=…)
     * @param array<string, string> $session_cookies the session's cookies, for the browser too
     * @return array<string, mixed>|string
     */
    public static function target(string $target, ?string $url = null, array $visitor = [], array $session_cookies = [], ?string $entry = null, string $name = 'page'): array|string
    {
        if (preg_match('#^https?://#i', $target)) {
            return ['url' => $target, 'cookies' => self::design_cookies($target)];
        }
        if (!str_starts_with($target, '/') || is_file($target)) {
            $file = realpath($target);
            return $file !== false && is_file($file) ? ['url' => 'file://' . $file, 'cookies' => []] : "\"{$target}\" is no URL path (/…), URL (https://…) or file";
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

        $out = Storage::private_dir('screenshots/' . self::slug($target));
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

    /** The folder name for a path's files: "/" is "home", "/winkel/?s=x" "winkel-s-x". */
    public static function slug(string $path): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($path)), '-') ?: 'home';
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
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, Paths::base());
        if ($process === false) {
            return ['error' => 'Could not start Node (`node`), which runs the browser.'];
        }
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

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
