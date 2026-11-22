<?php

declare(strict_types=1);

namespace Gaffer;

/**
 * Cloudflare Turnstile: the site key from theme config (public), the secret
 * from the TURNSTILE_SECRET_KEY constant in wp-config.php (per server). Gaffer
 * owns both ends: @gaffer/forms/meta.twig writes the widget, register() loads
 * Cloudflare's script and renders widgets (also after htmx swaps), and
 * Forms\FormAction checks the token.
 */
final class Turnstile
{
    private const string SECRET = 'TURNSTILE_SECRET_KEY';

    private const string SCRIPT = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit&onload=gafferTurnstile';

    /**
     * Renders every widget not rendered yet: on load (the ?onload= callback) and after each
     * htmx swap (a form re-rendered with errors brings a new widget: a token works once).
     * htmx 4's event names.
     */
    private const string RENDER = <<<'JS'
        (() => {
            const render = () => window.turnstile && document.querySelectorAll(".cf-turnstile:empty").forEach((el) => window.turnstile.render(el));
            window.gafferTurnstile = render;
            for (const name of ["htmx:after:process", "htmx:after:swap", "htmx:after:settle"]) {
                document.addEventListener(name, render);
            }
        })();
        JS;

    /**
     * A site key and a secret: forms check tokens.
     *
     * @internal Gaffer writes the widget (@gaffer/forms/meta.twig) and checks it (Spam::check()).
     */
    public static function enabled(): bool
    {
        return self::site_key() !== null;
    }

    /**
     * The site key when forms check tokens, else null.
     *
     * @internal
     */
    public static function site_key(): ?string
    {
        $key = Config::get('turnstile.site_key');

        return is_string($key) && $key !== '' && defined(self::SECRET) ? $key : null;
    }

    /**
     * Cloudflare's script and the widget rendering, on every front-end page while enabled
     * (a form can arrive later through htmx).
     *
     * @internal
     */
    public static function register(): void
    {
        \add_action('wp_enqueue_scripts', static function (): void {
            if (!self::enabled()) {
                return;
            }
            \wp_register_script('gaffer-turnstile', self::SCRIPT, [], null, ['in_footer' => true]);
            \wp_add_inline_script('gaffer-turnstile', self::RENDER, 'before');
            \wp_enqueue_script('gaffer-turnstile');
        });
    }

    /**
     * Cloudflare's answer for a token; true when Cloudflare can't be reached.
     *
     * @internal
     */
    public static function check(string $token): bool
    {
        if ($token === '') {
            return false; // no widget answer: nothing for Cloudflare to check
        }

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
