<?php

declare(strict_types=1);

namespace Gaffer\Forms;

use Gaffer\Storage;
use Gaffer\Turnstile;

/**
 * uploads/gaffer/logs/spam.log: submissions rejected as spam. Gaffer's reasons: honeypot,
 * turnstile-missing (no widget answer), turnstile-invalid. `doctor --wp` reads the
 * log: many turnstile-missing lines can mean a widget that doesn't render.
 */
final class Spam
{
    /**
     * Whether the form the visitor just sent is spam, by what @gaffer/forms/meta.twig put in it
     * (the honeypot, Turnstile's token): the reason, or null for a real submission. Every form
     * Gaffer protects checks with this, Gravity Forms' (FormAction) and a theme's own action
     * alike, so the hidden fields can change in any release. A reason is logged under $form and,
     * outside production, sent as the X-Gaffer-Spam header. Answer spam as if it worked.
     *
     *   if (Spam::check('offerte') !== null) { View::render('quote/sent.twig'); return; }
     */
    public static function check(string $form): ?string
    {
        $value = static fn(string $key): string => is_string($_POST[$key] ?? null) ? $_POST[$key] : '';
        $reason = match (true) {
            $value('website') !== '' => 'honeypot',
            !Turnstile::enabled() => null,
            $value('turnstile') === '' => 'turnstile-missing', // no widget answer: a bot, or a widget that didn't render
            !Turnstile::check($value('turnstile')) => 'turnstile-invalid',
            default => null,
        };
        if ($reason !== null) {
            self::log($form, $reason);
            if (\wp_get_environment_type() !== 'production' && !headers_sent()) {
                header("X-Gaffer-Spam: {$reason}"); // so a test submission that got here can tell
            }
        }

        return $reason;
    }

    /**
     * One line per rejected submission: time, form and reason only. No names,
     * emails or IPs (personal data).
     *
     * @internal check() logs; doctor reads the lines (counts()), so their format is Gaffer's.
     */
    public static function log(string $form, string $reason): void
    {
        $reason = (string) preg_replace('/\s+/', '-', trim($reason)); // one word: the line is "[time] form reason"

        file_put_contents(
            Storage::gaffer_dir('logs') . '/spam.log',
            sprintf("[%s] %s %s\n", \wp_date('Y-m-d H:i:s'), (string) preg_replace('/\s+/', '-', $form), $reason),
            FILE_APPEND | LOCK_EX,
        );
    }

    /**
     * The log's lines since $since, as form => reason => count. The log writes the site's
     * local time: $since is the site's local time read as UTC (what the log's stamps parse to).
     *
     * @return array<string, array<string, int>>
     *
     * @internal
     */
    public static function counts(string $log, int $since): array
    {
        $counts = [];
        foreach (explode("\n", $log) as $line) {
            if (!preg_match('/^\[(\d{4}-\d\d-\d\d \d\d:\d\d:\d\d)\] (\S+) (\S+)$/', $line, $m)) {
                continue;
            }
            $time = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $m[1], new \DateTimeZone('UTC'));
            if ($time !== false && $time->getTimestamp() >= $since) {
                $counts[$m[2]][$m[3]] = ($counts[$m[2]][$m[3]] ?? 0) + 1;
            }
        }

        return $counts;
    }
}
