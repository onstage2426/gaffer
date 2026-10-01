<?php

declare(strict_types=1);

namespace Gaffer;

use InvalidArgumentException;
use LogicException;

class Ajax
{
    public static function boot(string $dir): void
    {
        Gaffer::configure($dir);

        self::handle(Paths::ajax(), (string) (Config::get('theme.ajax_namespace') ?? 'Theme\\Ajax'));
    }

    /**
     * Public URL of an ajax action, e.g. Ajax::url('CartAdd').
     */
    public static function url(string $action): string
    {
        return \get_template_directory_uri() . '/ajax.php?action=' . rawurlencode($action);
    }

    public static function handle(string $dir, string $namespace): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? '';

        if (!in_array($method, ['GET', 'POST'], true)) {
            http_response_code(405);
            exit();
        }

        $action = $_GET['action'] ?? '';

        if (!preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $action)) {
            http_response_code(400);
            exit();
        }

        $action_file = "{$dir}/{$action}/{$action}.php";

        if (!is_file($action_file)) {
            http_response_code(404);
            exit();
        }

        foreach (glob("{$dir}/*/*.php") ?: [] as $file) {
            if (basename($file, '.php') !== basename(dirname($file))) {
                require_once $file;
            }
        }

        require_once $action_file;

        $class = "{$namespace}\\{$action}\\{$action}";

        if (!is_subclass_of($class, AjaxAction::class)) {
            error_log("Gaffer Ajax: {$class} is not defined in {$action_file} or does not extend " . AjaxAction::class);
            http_response_code(500);
            exit();
        }

        $instance = new $class();

        if ($instance->method !== $method) {
            http_response_code(405);
            exit();
        }

        if ($instance->shortinit) {
            define('SHORTINIT', true);
        }

        // A full load runs the theme's functions.php, which calls Gaffer::boot().
        // SHORTINIT never loads the theme, so only Twig is set up here.
        require_once Paths::wordpress() . '/wp-load.php';

        if ($instance->shortinit) {
            Gaffer::twig();
        }

        $input = $method === 'POST' ? $_POST : $_GET;

        // wp-load.php runs wp_magic_quotes(), except under SHORTINIT
        if (!$instance->shortinit) {
            $input = \wp_unslash($input);
        }

        try {
            $args = AjaxArguments::resolve($instance, $input);
        } catch (InvalidArgumentException) {
            http_response_code(400);
            exit();
        } catch (LogicException $e) {
            error_log('Gaffer Ajax: ' . $e->getMessage());
            http_response_code(500);
            exit();
        }

        AjaxArguments::run_method($instance)->invokeArgs($instance, $args);
    }
}
