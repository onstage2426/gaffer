<?php

declare(strict_types=1);

namespace Gaffer;

use InvalidArgumentException;
use LogicException;

final class Ajax
{
    /**
     * Actions are {NAMESPACE}\{Name}\{Name} in ajax/{Name}/{Name}.php; other
     * classes and traits in an action's directory are autoloaded the same way.
     *
     * @internal
     */
    public const string NAMESPACE = 'Theme\\Ajax';

    private static bool $autoload = false;

    public static function boot(string $dir): void
    {
        Gaffer::configure($dir);

        self::handle();
    }

    /**
     * Public URL of an ajax action, e.g. Ajax::url('CartAdd').
     */
    public static function url(string $action): string
    {
        return \get_template_directory_uri() . '/ajax.php?action=' . rawurlencode($action);
    }

    /**
     * The actions in ajax/: every ajax/{Name}/{Name}.php.
     *
     * @return list<string>
     *
     * @internal
     */
    public static function actions(): array
    {
        $names = [];
        foreach (glob(Paths::ajax() . '/*/*.php') ?: [] as $file) {
            if (basename($file, '.php') === basename(dirname($file))) {
                $names[] = basename($file, '.php');
            }
        }

        return $names;
    }

    /**
     * The class of an action, autoloaded: null when its file doesn't define
     * {NAMESPACE}\{Name}\{Name} extending AjaxAction.
     *
     * @return class-string<AjaxAction>|null
     *
     * @internal
     */
    public static function action_class(string $name): ?string
    {
        self::autoload();
        $class = self::NAMESPACE . "\\{$name}\\{$name}";

        return is_subclass_of($class, AjaxAction::class) ? $class : null;
    }

    /** @internal */
    public static function handle(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? '';

        if (!in_array($method, ['GET', 'POST'], true)) {
            http_response_code(405);
            exit();
        }

        $action = $_GET['action'] ?? '';

        if (!is_string($action) || !preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $action)) {
            http_response_code(400);
            exit();
        }

        if (!is_file(Paths::ajax() . "/{$action}/{$action}.php")) {
            http_response_code(404);
            exit();
        }

        $class = self::action_class($action);

        if ($class === null) {
            error_log("Gaffer Ajax: ajax/{$action}/{$action}.php doesn't define " . self::NAMESPACE . "\\{$action}\\{$action} extending " . AjaxAction::class);
            http_response_code(500);
            exit();
        }

        if ($class::METHOD !== $method) {
            http_response_code(405);
            exit();
        }

        $shortinit = $class::SHORTINIT;
        if ($shortinit) {
            define('SHORTINIT', true);
        }

        // A full load runs the theme's functions.php, which calls Gaffer::boot().
        // SHORTINIT never loads the theme, so only Twig is set up here.
        require_once Paths::wordpress() . '/wp-load.php';

        if ($shortinit) {
            Gaffer::twig();
        }

        // An action answers with a fragment: the page shell's shared data isn't needed.
        View::without_shared();

        $input = $method === 'POST' ? $_POST : $_GET;

        // wp-load.php runs wp_magic_quotes(), except under SHORTINIT
        if (!$shortinit) {
            $input = \wp_unslash($input);
        }

        $instance = new $class();

        try {
            $args = AjaxArguments::resolve($instance, $input);
        } catch (InvalidArgumentException) {
            http_response_code(400);
            exit();
        } catch (AjaxNotFound) {
            http_response_code(404);
            exit();
        } catch (LogicException $e) {
            error_log('Gaffer Ajax: ' . $e->getMessage());
            http_response_code(500);
            exit();
        }

        AjaxArguments::run_method($instance)->invokeArgs($instance, $args);
    }

    /**
     * Theme\Ajax\{Dir}\{Class} → ajax/{Dir}/{Class}.php, loaded when first used.
     */
    private static function autoload(): void
    {
        if (self::$autoload) {
            return;
        }
        self::$autoload = true;

        spl_autoload_register(static function (string $class): void {
            $prefix = self::NAMESPACE . '\\';
            $parts = str_starts_with($class, $prefix) ? explode('\\', substr($class, strlen($prefix))) : [];
            if (count($parts) === 2 && preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $parts[0] . $parts[1]) === 1) {
                $file = Paths::ajax() . "/{$parts[0]}/{$parts[1]}.php";
                if (is_file($file)) {
                    require_once $file;
                }
            }
        });
    }
}
