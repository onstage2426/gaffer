<?php

declare(strict_types=1);

namespace Gaffer;

use InvalidArgumentException;
use LogicException;

/**
 * The ajax dispatcher: the theme's ajax.php hands every request to it, and it runs the
 * action named by ?action= (app/Ajax/{Name}/{Name}.php, an AjaxAction).
 */
final class Ajax
{
    /**
     * Actions are {NAMESPACE}\{Name}\{Name} in app/Ajax/{Name}/{Name}.php, loaded by
     * the theme's Composer autoloader (Theme\ → app/) like its other classes.
     *
     * @internal
     */
    public const string NAMESPACE = 'Theme\\Ajax';

    /**
     * Entry point for the theme's ajax.php: answers the request and exits.
     */
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
     * The actions: every app/Ajax/{Name}/{Name}.php.
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
     * The class of an action: null when its file doesn't define
     * {NAMESPACE}\{Name}\{Name} extending AjaxAction.
     *
     * @return class-string<AjaxAction>|null
     *
     * @internal
     */
    public static function action_class(string $name): ?string
    {
        $class = self::NAMESPACE . "\\{$name}\\{$name}";

        return is_subclass_of($class, AjaxAction::class) ? $class : null;
    }

    /** @internal */
    public static function handle(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? '';

        if (!in_array($method, ['GET', 'POST'], true)) {
            self::fail(405);
        }

        // Larger than post_max_size: PHP dropped the whole body (a form's files), so the
        // action would see no arguments at all. 413 says why; htmx fires htmx:responseError.
        if ($method === 'POST' && $_POST === [] && $_FILES === [] && self::too_large($_SERVER, (string) ini_get('post_max_size'))) {
            self::fail(413);
        }

        $action = $_GET['action'] ?? '';

        if (!is_string($action) || !preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $action)) {
            self::fail(400);
        }

        if (!is_file(Paths::ajax() . "/{$action}/{$action}.php")) {
            self::fail(404);
        }

        $class = self::action_class($action);

        if ($class === null) {
            error_log("Gaffer Ajax: app/Ajax/{$action}/{$action}.php doesn't define " . self::NAMESPACE . "\\{$action}\\{$action} extending " . AjaxAction::class);
            self::fail(500);
        }

        if ($class::METHOD !== $method) {
            self::fail(405);
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

        // An answer is for this visitor (a cart, a form's state): never stored by a page or
        // proxy cache, and not a page for search engines.
        header('Cache-Control: no-store, max-age=0');
        header('X-Robots-Tag: noindex');

        $instance = new $class();

        try {
            $args = AjaxArguments::resolve($instance, $input);
        } catch (InvalidArgumentException) {
            self::fail(400);
        } catch (AjaxNotFound) {
            self::fail(404);
        } catch (LogicException $e) {
            error_log('Gaffer Ajax: ' . $e->getMessage());
            self::fail(500);
        }

        AjaxArguments::run_method($instance)->invokeArgs($instance, $args);
    }

    /**
     * Ends the request with an error status and no body. `HX-Reswap: none`: htmx 2 doesn't
     * swap error responses, htmx 4 does by default, and an empty body would wipe the target
     * (a form). htmx fires its error event either way.
     *
     * @internal
     */
    public static function fail(int $status): never
    {
        http_response_code($status);
        if (!headers_sent()) {
            header('HX-Reswap: none');
        }
        exit();
    }

    /**
     * Whether a request's body was larger than PHP takes (post_max_size).
     *
     * @param array<string, mixed> $server $_SERVER
     *
     * @internal
     */
    public static function too_large(array $server, string $post_max_size): bool
    {
        $length = (int) ($server['CONTENT_LENGTH'] ?? 0);
        $limit = trim($post_max_size) === '' ? 0 : ini_parse_quantity($post_max_size);

        return $limit > 0 && $length > $limit;
    }
}
