<?php

/**
 * WP-CLI from a staged copy of the active theme: `wp --require=vendor/onstage2426/gaffer/bin/stage.php
 * gaffer-search rebuild` (in the copy) loads the copy as the active theme, without the persistent
 * object cache, as `php gaffer` does from there (Gaffer\Console\WordPress::as_this_theme()).
 * From the active theme it changes nothing.
 *
 * @internal
 */

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/autoload.php';

Gaffer\Gaffer::configure(dirname(__DIR__, 4));
Gaffer\Console\WordPress::as_this_theme();
