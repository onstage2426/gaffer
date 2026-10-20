<?php

/**
 * Renders one URL the way a web request does; doctor:render starts it, one process per
 * URL. WordPress and its template loader run at this file's top level, like index.php,
 * so template files run in the global scope as they do in a real request (their
 * variables are WordPress's globals: get_header() may overwrite a $page).
 *
 * php render.php <vendor/autoload.php> <theme directory> <options as JSON>
 *
 * @internal
 */

declare(strict_types=1);

[, $gaffer_autoload, $gaffer_theme, $gaffer_options] = array_pad((array) ($_SERVER['argv'] ?? []), 4, null);
if (!is_string($gaffer_autoload) || !is_string($gaffer_theme) || !is_array($gaffer_options = json_decode((string) $gaffer_options, true))) {
    fwrite(STDERR, "Usage: php render.php <vendor/autoload.php> <theme directory> <options as JSON>\n");
    exit(2);
}

require $gaffer_autoload;
Gaffer\Console\Render\Request::preload($gaffer_autoload, $gaffer_theme);

try {
    $gaffer_render = Gaffer\Console\Render\Request::start($gaffer_theme, $gaffer_options);
} catch (InvalidArgumentException $gaffer_error) {
    fwrite(STDOUT, $gaffer_error->getMessage() . "\n");
    exit(1);
}

require_once Gaffer\Paths::wordpress() . '/wp-load.php';
$gaffer_render->loaded();

try {
    wp();
    require ABSPATH . 'wp-includes/template-loader.php';
} catch (Throwable $gaffer_error) {
    $gaffer_render->failed($gaffer_error);
}
