<?php

/**
 * WP-CLI from a staged copy of the active theme: `wp --require=vendor/onstage2426/gaffer/bin/stage.php
 * gaffer-search rebuild` (in the copy) loads the copy as the active theme, reading live's persistent
 * object cache without writing to it, as `php gaffer` does from there (Gaffer\Console\WordPress::as_this_theme()).
 * From the active theme it changes nothing.
 *
 * Only Gaffer's and the theme's own classes (Theme\ in app/: config files may use their
 * constants) are loaded here: the theme's Composer autoloader (its functions.php loads it)
 * would win over WP-CLI's bundled libraries (Symfony) before WordPress loads.
 *
 * @internal
 */

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    foreach (['Gaffer\\' => dirname(__DIR__) . '/src/', 'Theme\\' => dirname(__DIR__, 4) . '/app/'] as $prefix => $dir) {
        $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (str_starts_with($class, $prefix) && is_file($file)) {
            require $file;
            return;
        }
    }
});

Gaffer\Gaffer::configure(dirname(__DIR__, 4));
Gaffer\Console\WordPress::as_this_theme();
