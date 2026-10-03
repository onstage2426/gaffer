<?php

declare(strict_types=1);

namespace Gaffer\Mcp\Tools;

use Gaffer\Mcp\Tool;
use Gaffer\Paths;

final class LastErrors implements Tool
{
    /** How much of the end of a log is read. */
    private const int TAIL_BYTES = 512 * 1024;

    #[\Override]
    public function name(): string
    {
        return 'last-errors';
    }

    #[\Override]
    public function label(): string
    {
        return 'Recent errors and logs';
    }

    #[\Override]
    public function description(): string
    {
        return "The most recent entries of PHP's error log (WordPress's debug.log when WP_DEBUG_LOG is on), repeated "
            . "messages merged with a count and the last time, newest last; plus the last lines of the theme's own logs "
            . '(storage/logs: spam.log, migrate.log, ...). Use when something broke or behaves oddly, before guessing.';
    }

    #[\Override]
    public function input_schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 20, 'description' => 'Distinct PHP messages, and lines per theme log'],
            ],
        ];
    }

    #[\Override]
    public function run(array $input): array
    {
        $limit = (int) ($input['limit'] ?? 20);
        $file = (string) ini_get('error_log');

        $php = is_file($file) && is_readable($file)
            ? ['file' => $file, 'entries' => array_slice(self::entries(...self::tail($file)), -$limit)]
            : ['file' => $file ?: null, 'entries' => [], 'note' => 'No readable PHP error log: turn on WP_DEBUG_LOG in wp-config.php to log to wp-content/debug.log.'];

        $logs = [];
        foreach (glob(Paths::storage() . '/logs/*.log') ?: [] as $log) {
            [$text] = self::tail($log);
            $logs[basename($log)] = array_slice(array_values(array_filter(explode("\n", $text), static fn(string $line): bool => trim($line) !== '')), -$limit);
        }

        return ['php' => $php, 'theme_logs' => $logs];
    }

    #[\Override]
    public function available(): bool
    {
        return true;
    }

    /**
     * PHP log entries ("[03-Oct-2026 10:00:00 UTC] PHP Warning: ..." plus any stack
     * trace lines), identical messages merged, ordered by their last occurrence.
     *
     * @param bool $partial the text starts mid-file, so its first entry may be cut off
     * @return list<array{message: string, count: int, last: string}>
     */
    public static function entries(string $text, bool $partial): array
    {
        $chunks = preg_split('/^(?=\[\d{2}-[A-Za-z]{3}-\d{4} [^\]]*\] )/m', $text) ?: [];
        if ($partial) {
            array_shift($chunks);
        }

        $merged = [];
        foreach ($chunks as $chunk) {
            if (!preg_match('/^\[([^\]]+)\] (.*)$/s', rtrim($chunk), $m)) {
                continue;
            }
            $message = mb_substr($m[2], 0, 2000);
            $previous = $merged[$message]['count'] ?? 0;
            unset($merged[$message]); // re-added at the end: ordered by last occurrence
            $merged[$message] = ['message' => $message, 'count' => $previous + 1, 'last' => $m[1]];
        }

        return array_values($merged);
    }

    /** @return array{string, bool} the end of the file, and whether that starts mid-file */
    private static function tail(string $file): array
    {
        $size = (int) filesize($file);
        $handle = fopen($file, 'r');
        if ($handle === false) {
            return ['', false];
        }
        $start = max(0, $size - self::TAIL_BYTES);
        fseek($handle, $start);
        $text = (string) stream_get_contents($handle);
        fclose($handle);

        return [$text, $start > 0];
    }
}
