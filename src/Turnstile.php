<?php

declare(strict_types=1);

namespace Gaffer;

/**
 * Cloudflare Turnstile: the site key from theme config (public), the secret
 * from the TURNSTILE_SECRET_KEY constant in wp-config.php (per server).
 */
final class Turnstile
{
    private const string SECRET = 'TURNSTILE_SECRET_KEY';

    /** The public site key, null when none is configured. */
    public static function site_key(): ?string
    {
        $key = Config::get('turnstile.site_key');

        return is_string($key) && $key !== '' ? $key : null;
    }

    /** A site key and a secret: forms check tokens. */
    public static function enabled(): bool
    {
        return self::site_key() !== null && defined(self::SECRET);
    }

    public static function verify(string $token): bool
    {
        if (!defined(self::SECRET)) {
            error_log('Gaffer Turnstile: ' . self::SECRET . ' is not defined in wp-config.php');
            return false;
        }

        $response = \wp_remote_post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
            'body' => [
                'secret' => (string) constant(self::SECRET),
                'response' => $token,
                'remoteip' => $_SERVER['REMOTE_ADDR'] ?? '',
            ],
        ]);

        if (\is_wp_error($response)) {
            return true; // Cloudflare unreachable: let people through rather than block every form
        }

        $data = json_decode(\wp_remote_retrieve_body($response), true);

        return is_array($data) && ($data['success'] ?? false) === true;
    }
}
