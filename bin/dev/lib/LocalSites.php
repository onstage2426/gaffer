<?php

/**
 * Maintainer tools (not for themes): the local sites from local/sites.json (gitignored; this
 * clone's host details stay out of tracked files), and running commands on them.
 *
 * local/sites.json:
 *   {"playwright": "<a theme dir with playwright installed>",
 *    "sites": {"<name>": {"theme": "<theme dir, ~ allowed>", "url": "https://…",
 *      "commit": true,                    may commit composer.lock there
 *      "container": "<name>:<theme dir>", run `php gaffer` in this container (else on the host)
 *      "wp": ["--url=…"],                 extra WP-CLI arguments (a network's site)
 *      "checks": [["htmx.mjs", "/contact/"], …]}}}  site-checks after <theme> <base>
 */

declare(strict_types=1);

namespace GafferDev;

final class LocalSites
{
    /** @return array{playwright: string, sites: array<string, array<string, mixed>>} */
    public static function config(): array
    {
        $file = dirname(__DIR__, 3) . '/local/sites.json';
        $config = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (!is_array($config) || !is_array($config['sites'] ?? null)) {
            fwrite(STDERR, "No local/sites.json with a \"sites\" object (see bin/dev/lib/LocalSites.php).\n");
            exit(2);
        }
        $sites = [];
        foreach ($config['sites'] as $name => $site) {
            if (is_array($site) && is_string($site['theme'] ?? null)) {
                $site['theme'] = self::expand($site['theme']);
                $sites[(string) $name] = $site;
            }
        }

        return ['playwright' => self::expand((string) ($config['playwright'] ?? '')), 'sites' => $sites];
    }

    /**
     * The sites named in --sites=a,b (all without it), by name.
     *
     * @param list<string> $args
     * @return array<string, array<string, mixed>>
     */
    public static function selected(array $args): array
    {
        $sites = self::config()['sites'];
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--sites=')) {
                $names = array_filter(explode(',', substr($arg, 8)));
                $unknown = array_diff($names, array_keys($sites));
                if ($unknown !== []) {
                    fwrite(STDERR, 'Unknown site(s): ' . implode(', ', $unknown) . ' (local/sites.json has: ' . implode(', ', array_keys($sites)) . ")\n");
                    exit(2);
                }
                return array_intersect_key($sites, array_flip($names));
            }
        }

        return $sites;
    }

    public static function expand(string $path): string
    {
        return str_starts_with($path, '~/') ? (string) getenv('HOME') . substr($path, 1) : $path;
    }

    /**
     * The WordPress root of a site: the theme is wp-content/themes/<theme>.
     *
     * @param array<string, mixed> $site
     */
    public static function root(array $site): string
    {
        return dirname((string) $site['theme'], 3);
    }

    /**
     * `php gaffer <args>` for this site: on the host in its theme, or in its container.
     *
     * @param array<string, mixed> $site
     * @param list<string> $args
     * @return list<string>
     */
    public static function gaffer(array $site, array $args): array
    {
        if (is_string($site['container'] ?? null)) {
            [$container, $dir] = explode(':', $site['container'], 2);
            return ['docker', 'exec', '-w', $dir, $container, 'php', 'gaffer', ...$args];
        }

        return ['php', 'gaffer', ...$args];
    }

    /**
     * Runs commands at once, each in its directory; their output (stdout and stderr) and exit
     * codes, by key, once all are done.
     *
     * @param array<string, array{list<string>, string}> $commands key => [argv, cwd]
     * @return array<string, array{int, string}>
     */
    public static function parallel(array $commands): array
    {
        $running = [];
        foreach ($commands as $key => [$argv, $cwd]) {
            $out = tmpfile();
            $process = proc_open($argv, [0 => ['file', '/dev/null', 'r'], 1 => $out, 2 => $out], $pipes, $cwd);
            $running[$key] = [$process, $out];
        }
        $results = [];
        foreach ($running as $key => [$process, $out]) {
            $code = is_resource($process) ? proc_close($process) : 127;
            fseek($out, 0);
            $results[$key] = [$code, (string) stream_get_contents($out)];
            fclose($out);
        }

        return $results;
    }

    /**
     * Runs one command in a directory, its output streamed through; its exit code.
     *
     * @param list<string> $argv
     */
    public static function run(array $argv, string $cwd): int
    {
        $process = proc_open($argv, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, $cwd);

        return is_resource($process) ? proc_close($process) : 127;
    }

    /**
     * Runs one command, its output captured.
     *
     * @param list<string> $argv
     * @return array{int, string}
     */
    public static function capture(array $argv, string $cwd): array
    {
        return self::parallel(['one' => [$argv, $cwd]])['one'];
    }
}
