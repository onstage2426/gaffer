<?php

declare(strict_types=1);

namespace Gaffer\Forms;

use Gaffer\Storage;

/**
 * storage/logs/spam.log: submissions rejected as spam (honeypot, Turnstile).
 */
final class Spam
{
    /**
     * One line per rejected submission: time, form and reason only. No names,
     * emails or IPs (personal data).
     */
    public static function log(string $form, string $reason): void
    {
        file_put_contents(
            Storage::private_dir('logs') . '/spam.log',
            sprintf("[%s] %s %s\n", \wp_date('Y-m-d H:i:s'), $form, $reason),
            FILE_APPEND | LOCK_EX,
        );
    }
}
