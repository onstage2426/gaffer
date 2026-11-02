<?php

/**
 * WP-CLI from a staged copy of the active theme: `wp --require=vendor/onstage2426/gaffer/bin/stage.php
 * gaffer-search rebuild` (in the copy) loads the copy as the active theme, without the persistent
 * object cache, as `php gaffer` does from there (Gaffer\Console\WordPress::as_this_theme()).
 * From the active theme it changes nothing.
 *
 * Only Gaffer's own classes are loaded here: the theme's Composer autoloader (its functions.php
 * loads it) would win over WP-CLI's bundled libraries (Symfony) before WordPress loads.
 *
 * @internal
 */

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen('Gaffer\\'))) . '.php';
    if (str_starts_with($class, 'Gaffer\\') && is_file($file)) {
        require $file;
    }
});

Gaffer\Gaffer::configure(dirname(__DIR__, 4));
Gaffer\Console\WordPress::as_this_theme();
