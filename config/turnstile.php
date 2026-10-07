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
    | config('turnstile.site_key') in Twig. Unset means Turnstile is off. The
    | secret is the TURNSTILE_SECRET_KEY constant in wp-config.php.
    |
    */

    // 'site_key' => '',

];
