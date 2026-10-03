<?php

declare(strict_types=1);

namespace Gaffer\Mcp;

use Gaffer\Paths;
use RuntimeException;

/**
 * Runs the theme's `php gaffer` in a child process, for tools that need a fresh
 * WordPress (rendering a URL, doctor): one WordPress request per process.
 */
final class GafferCli
{
    /** @param list<string> $arguments */
    public static function run(array $arguments): string
    {
        $entry = Paths::base('gaffer');
        if (!is_file($entry)) {
            throw new RuntimeException("The theme has no `gaffer` CLI entry file ({$entry}).");
        }

        $command = [PHP_BINARY, $entry, ...$arguments, '--no-ansi', '--url=' . \home_url('/')];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, Paths::base());
        if ($process === false) {
            throw new RuntimeException('Could not start `php gaffer`.');
        }
        $stdout = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return $stdout;
    }
}
