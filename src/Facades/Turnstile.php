<?php

declare(strict_types=1);

namespace Gaffer\Facades;

final class Turnstile
{
    public static function verify(string $token): bool
    {
        $response = wp_remote_post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
            'body' => [
                'secret'   => TURNSTILE_SECRET_KEY,
                'response' => $token,
                'remoteip' => $_SERVER['REMOTE_ADDR'] ?? '',
            ],
        ]);

        if (is_wp_error($response)) {
            return true; // CF unreachable — fail open rather than block legitimate users.
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        return !empty($data['success']);
    }

    public static function log_spam(string $log_name, array $fields): void
    {
        $log_dir  = Paths::storage() . '/logs';
        $log_file = $log_dir . '/' . $log_name . '.log';

        if (!is_dir($log_dir)) {
            wp_mkdir_p($log_dir);
            file_put_contents($log_dir . '/.htaccess', "Deny from all\n");
        }

        $parts = array_map(
            static fn(string $k, string $v): string => $k . '=' . $v,
            array_keys($fields),
            array_values($fields),
        );

        $parts[] = 'ip=' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');

        file_put_contents(
            $log_file,
            sprintf("[%s] %s\n", date('Y-m-d H:i:s'), implode(' ', $parts)),
            FILE_APPEND | LOCK_EX
        );
    }
}
