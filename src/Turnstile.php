<?php

declare(strict_types=1);

namespace Gaffer;

final class Turnstile
{
    public static function site_key(): string
    {
        return (string) (Config::get('turnstile.site_key') ?? '');
    }

    public static function enabled(): bool
    {
        return self::site_key() !== '' && self::secret() !== null;
    }

    public static function verify(string $token): bool
    {
        $secret = self::secret();

        if ($secret === null) {
            error_log('Gaffer Turnstile: secret constant ' . self::secret_constant() . ' is not defined');
            return false;
        }

        $response = wp_remote_post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
            'body' => [
                'secret'   => $secret,
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

    private static function secret(): ?string
    {
        $constant = self::secret_constant();
        return defined($constant) ? (string) constant($constant) : null;
    }

    private static function secret_constant(): string
    {
        return (string) (Config::get('turnstile.secret_constant') ?? 'TURNSTILE_SECRET_KEY');
    }

    /**
     * Logs a rejected submission to storage/logs/spam.log: time, form and
     * reason only. No names, emails or IPs (personal data).
     */
    public static function log_spam(string $form, string $reason): void
    {
        file_put_contents(
            Storage::private_dir('logs') . '/spam.log',
            sprintf("[%s] %s %s\n", wp_date('Y-m-d H:i:s'), $form, $reason),
            FILE_APPEND | LOCK_EX,
        );
    }
}
