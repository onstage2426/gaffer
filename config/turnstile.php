<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Site Key
    |--------------------------------------------------------------------------
    |
    | Public Cloudflare Turnstile site key, rendered into the widget's
    | data-sitekey. Safe to commit. Read via Turnstile::site_key() or
    | config('turnstile.site_key') in Twig. Empty/unset means Turnstile is off.
    |
    */

    // 'site_key' => '',

    /*
    |--------------------------------------------------------------------------
    | Secret Constant
    |--------------------------------------------------------------------------
    |
    | Name of the PHP constant (defined in wp-config.php) holding the secret
    | key. Only the name lives here, never the secret itself, and it's read
    | lazily in Turnstile::verify(), so it works even when config loads before
    | wp-config.php (ajax.php). If the constant is undefined, verify() logs and
    | returns false. Defaults to "TURNSTILE_SECRET_KEY".
    |
    */

    // 'secret_constant' => 'TURNSTILE_SECRET_KEY',

];
