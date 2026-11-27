<?php

declare(strict_types=1);

namespace Gaffer\Console\Checks;

use Gaffer\Ajax;
use Gaffer\AjaxAction;
use Gaffer\AjaxArguments;
use Gaffer\Console\ConfigStubs;
use Gaffer\Console\Report;
use Gaffer\Console\Templates;
use Gaffer\Console\ThemeFiles;
use Gaffer\Paths;
use Throwable;

/**
 * Each app/Ajax/{Name}/{Name}.php defines Theme\Ajax\{Name}\{Name} extending AjaxAction,
 * with a GET or POST METHOD and a run() Gaffer can fill, and warns about SHORTINIT
 * actions using things SHORTINIT doesn't load. Loads them the way the dispatcher
 * does (Ajax::action_class()).
 */
final class AjaxCheck implements Check
{
    #[\Override]
    public function run(Report $report): void
    {
        if (is_dir(Paths::base('ajax'))) {
            $report->error('ajax', 'ajax/ is no longer read: actions are app/Ajax/{Name}/{Name}.php', Paths::base('ajax'), null,
                'Move each ajax/{Name}/ to app/Ajax/{Name}/ (same namespace), its templates to views/ (no more @ajax/), then composer dump-autoload.');
        }

        foreach (Ajax::actions() as $action) {
            $file = Paths::ajax() . "/{$action}/{$action}.php";
            if (str_starts_with($action, 'Gaffer')) {
                $report->error('ajax', "The action name {$action} starts with Gaffer, which is Gaffer's", $file, null,
                    'Actions named Gaffer* are reserved for Gaffer\'s own (API.md, "Reserved names"): rename the folder and class.');
            }
            try {
                $class = Ajax::action_class($action);
            } catch (Throwable $e) {
                $report->error('ajax', 'Fails to load: ' . $e->getMessage(), $e->getFile(), $e->getLine());
                continue;
            }

            if ($class === null) {
                $report->error('ajax', Ajax::NAMESPACE . "\\{$action}\\{$action} is not defined here or doesn't extend " . AjaxAction::class, $file);
                continue;
            }

            if (!in_array($class::METHOD, ['GET', 'POST'], true)) {
                $report->error('ajax', 'METHOD is "' . $class::METHOD . '"; the dispatcher only accepts GET or POST', $file);
            }

            // Read through reflection, never constructed: a constructor may call WordPress, which doctor hasn't loaded here.
            $run = method_exists($class, 'run') ? new \ReflectionMethod($class, 'run') : null;
            $problem = $run !== null ? AjaxArguments::problem($run) : "{$class} has no run() method";
            if ($problem !== null) {
                $report->error('ajax', $problem, $file, null,
                    'run() parameters are the request keys: typed string, int, float, bool, array or a Gaffer type (from an ID); no default = required.');
            }

            foreach ($run?->getParameters() ?? [] as $parameter) {
                if ($parameter->getName() === 'action' || str_starts_with($parameter->getName(), 'gaffer_')) {
                    $report->error('ajax', "run()'s parameter \${$parameter->getName()} is a request key Gaffer reads itself", $file, null,
                        '`action` is the dispatcher\'s and gaffer_* is reserved for Gaffer\'s request keys (API.md, "Reserved names"): rename the parameter and what sends it.');
                }
            }

            if ($run !== null) {
                foreach (self::json_strings($run, (string) file_get_contents($file)) as $name) {
                    $report->warning('ajax', "run() decodes JSON from the string parameter \${$name}", $file, null,
                        "Send it as form fields ({$name}[key]=value) and declare `array \${$name}`, or use a type parameter for an ID.");
                }
            }

            if ($class::SHORTINIT) {
                self::shortinit($report, $file);
            }
        }

        $this->urls($report);
        $this->sent_keys($report);
    }

    /**
     * Literal action names in `ajax_url('X')` (Twig) and `Ajax::url('X')` (PHP) that no action
     * has: the request answers 404, found only when someone clicks.
     */
    private function urls(Report $report): void
    {
        $actions = [...Ajax::actions(), ...array_keys(Ajax::OWN)];
        foreach (ThemeFiles::find(['php', 'twig']) as $file) {
            $code = (string) file_get_contents($file);
            preg_match_all('/(?:\bajax_url|\bAjax::url)\(\s*([\'"])([A-Za-z][A-Za-z0-9]*)\1\s*\)/', $code, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            foreach ($matches as $m) {
                if (!in_array($m[2][0], $actions, true)) {
                    $hint = ConfigStubs::did_you_mean($m[2][0], $actions);
                    $report->error('ajax', "Calls the ajax action {$m[2][0]}, which doesn't exist (404)", $file, substr_count($code, "\n", 0, $m[0][1]) + 1,
                        $hint !== null ? "Did you mean {$hint}?" : 'Actions are app/Ajax/{Name}/{Name}.php.');
                }
            }
        }
    }

    /**
     * Keys a template's literal hx-vals JSON sends to an action whose run() has no such
     * parameter: the dispatcher ignores them, so a typo takes the default (or a 400 on click).
     */
    private function sent_keys(Report $report): void
    {
        foreach (Templates::all() as $file) {
            foreach (TagsCheck::tags((string) file_get_contents($file)) as $tag) {
                $action = null;
                $vals = null;
                foreach ($tag['attrs'] as [$name, $value]) {
                    if (preg_match('/^hx-(?:get|post)$/', $name) && preg_match('/\bajax_url\(\s*[\'"]([A-Za-z][A-Za-z0-9]*)[\'"]\s*\)/', (string) $value, $m)) {
                        $action = $m[1];
                    } elseif ($name === 'hx-vals' && str_starts_with(ltrim((string) $value), '{') && !str_starts_with(ltrim((string) $value), '{{')) {
                        $vals = (string) $value;
                    }
                }
                $class = $action !== null && $vals !== null ? Ajax::action_class($action) : null;
                if ($class === null || !method_exists($class, 'run')) {
                    continue;
                }
                $parameters = array_map(static fn(\ReflectionParameter $p): string => $p->getName(), new \ReflectionMethod($class, 'run')->getParameters());
                preg_match_all('/"([A-Za-z_][A-Za-z0-9_-]*)(?:\[[^"]*\])?"\s*:/', (string) preg_replace('/\{\{.*?\}\}/s', '0', $vals), $keys);
                foreach (array_unique($keys[1]) as $key) {
                    if (!in_array($key, $parameters, true)) {
                        $report->warning('ajax', "hx-vals sends \"{$key}\" to {$action}, whose run() has no \${$key}", $file, $tag['line'],
                            'The dispatcher passes request keys by run()\'s parameter names and ignores the rest: rename one of them, or drop the key.');
                    }
                }
            }
        }
    }

    /**
     * String parameters of run() that the action json_decode()s.
     *
     * @return list<string>
     */
    public static function json_strings(\ReflectionMethod $run, string $code): array
    {
        $names = [];
        foreach ($run->getParameters() as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof \ReflectionNamedType && $type->getName() === 'string'
                && preg_match('/\bjson_(?:decode|validate)\(\s*\$' . preg_quote($parameter->getName(), '/') . '\b/', $code)) {
                $names[] = $parameter->getName();
            }
        }

        return $names;
    }

    /**
     * SHORTINIT loads no plugins, no theme and no pluggable functions. Flag code
     * in the action that needs them (pattern-based, so warnings).
     */
    private static function shortinit(Report $report, string $file): void
    {
        $unavailable = [
            '/Gaffer\\\\Types\\\\|\b(?:Post|Term|Image|Attachment|Menu|Pagination)::/' => 'Gaffer types (no WordPress query layer)',
            '/\bWC\(|\bwc_[a-z_]+\(/' => 'WooCommerce (plugins are not loaded)',
            '/\bget_field\(|\bAcf::/' => 'ACF (plugins are not loaded)',
            '/\b(?:current_user_can|wp_verify_nonce|is_user_logged_in|wp_get_current_user)\(/' => 'users and nonces (pluggable functions are not loaded)',
            '/\bajax_url\(|\bget_template_directory/' => 'theme functions (the theme is not loaded)',
        ];

        foreach (file($file) ?: [] as $i => $line) {
            foreach ($unavailable as $pattern => $what) {
                if (preg_match($pattern, $line)) {
                    $report->warning('ajax', "SHORTINIT action uses {$what}", $file, $i + 1,
                        'SHORTINIT has $wpdb, get_option(), config and Twig only. Drop SHORTINIT or read the data with $wpdb.');
                }
            }
        }
    }
}
