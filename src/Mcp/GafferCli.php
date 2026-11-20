<?php

declare(strict_types=1);

namespace Gaffer\Mcp;

use Gaffer\Console\ChildProcess;
use Gaffer\Paths;
use RuntimeException;

/**
 * Runs the theme's `php gaffer` in a child process, for tools that need a fresh
 * WordPress (rendering a URL, doctor): one WordPress request per process.
 */
final class GafferCli
{
    /** Seconds a command may take (doctor --wp renders ~30 pages; a full-page screenshot scrolls). */
    private const int TIMEOUT = 600;

    /** @param list<string> $arguments */
    public static function run(array $arguments, ?string $entry = null): string
    {
        $entry ??= Paths::base('gaffer');
        if (!is_file($entry)) {
            throw new RuntimeException("The theme has no `gaffer` CLI entry file ({$entry}).");
        }

        $result = ChildProcess::run([PHP_BINARY, $entry, ...$arguments, '--no-ansi', '--url=' . \home_url('/')], Paths::base(), self::TIMEOUT);
        if ($result === null) {
            throw new RuntimeException('Could not start `php gaffer`.');
        }
        if ($result['timed_out']) {
            throw new RuntimeException('`php gaffer ' . ($arguments[0] ?? '') . '` took longer than ' . self::TIMEOUT . ' s and was stopped.');
        }

        return $result['stdout'];
    }

    /**
     * The JSON object a `--json` command printed, wherever it is in the output: plugins may
     * print before it (Redis Object Cache's comment while WordPress loads) or after it (on
     * shutdown). Tries each line that starts with "{" against each later line that ends with
     * "}", the widest first.
     *
     * @return array<string, mixed>|null
     */
    public static function json_object(string $stdout): ?array
    {
        $lines = explode("\n", $stdout);
        $starts = $ends = [];
        $offset = 0;
        foreach ($lines as $line) {
            $trimmed = rtrim($line);
            if (str_starts_with($trimmed, '{')) {
                $starts[] = $offset;
            }
            if (str_ends_with($trimmed, '}')) {
                $ends[] = $offset + strlen($trimmed);
            }
            $offset += strlen($line) + 1;
        }
        foreach ($starts as $start) {
            foreach (array_reverse($ends) as $end) {
                if ($end <= $start) {
                    break;
                }
                $data = json_decode(substr($stdout, $start, $end - $start), true);
                if (is_array($data) && !array_is_list($data)) {
                    /** @var array<string, mixed> $data */
                    return $data;
                }
            }
        }

        return null;
    }
}
