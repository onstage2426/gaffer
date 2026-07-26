<?php

declare(strict_types=1);

namespace Gaffer;

use Gaffer\Bootstrap\IncludesBootstrapper;
use Gaffer\Facades\Config;
use Gaffer\Facades\Paths;

class Ajax
{
    public static function boot(): void
    {
        self::handle(Paths::ajax(), (string) Config::get('theme.ajax_namespace'));
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

        $class    = "{$namespace}\\{$action}\\{$action}";
        $instance = new $class();

        if ($instance->method !== $method) {
            http_response_code(405);
            exit();
        }

        if ($instance->shortinit) {
            define('SHORTINIT', true);
        }

        require_once $_SERVER['DOCUMENT_ROOT'] . '/wp-load.php';

        if (!$instance->shortinit) {
            new IncludesBootstrapper(Paths::includes())->boot();
        }

        $input = $method === 'POST' ? $_POST : $_GET;

        $data = [];
        foreach ($instance->arguments() as $key => $default) {
            if (!array_key_exists($key, $input)) {
                if ($default === null) {
                    http_response_code(400);
                    exit();
                }
                $data[$key] = $default;
            } else {
                $data[$key] = $input[$key];
            }
        }

        $instance->run($data);
    }
}
