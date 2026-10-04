<?php

declare(strict_types=1);

namespace Gaffer\Console\Checks;

use Gaffer\Console\Report;
use Gaffer\Console\Templates;
use Gaffer\Console\ThemeFiles;
use Gaffer\Gaffer;
use Gaffer\Paths;
use Gaffer\View;

/**
 * Templates that are rendered or included but don't exist, templates nothing
 * uses, includes that hand the partial all of the caller's variables, and
 * get_*() calls that reach past the types into WordPress/WooCommerce.
 */
final class TemplatesCheck implements Check
{
    private const string PHP_RENDER = '/View::(?:render|fetch)\(\s*([\'"])([^\'"]+)\1\s*[,)]/';

    #[\Override]
    public function run(Report $report): void
    {
        Gaffer::twig();
        $loader = View::env()->getLoader();
        $templates = Templates::all();
        $used = [];

        foreach (ThemeFiles::find(['php']) as $file) {
            foreach (file($file) ?: [] as $i => $line) {
                if (preg_match_all(self::PHP_RENDER, $line, $m)) {
                    foreach ($m[2] as $name) {
                        $used[$name] = true;
                        if (!$loader->exists($name)) {
                            $report->error('views', "Renders {$name}, which doesn't exist", $file, $i + 1);
                        }
                    }
                }
            }
        }

        foreach ($templates as $name => $file) {
            $module = Templates::parse($name);
            if ($module === null) {
                continue; // twig:lint reports it
            }
            foreach (Templates::getter_calls($module) as [$method, $line]) {
                $report->warning('views', "Calls {$method}(): a WordPress/WooCommerce/plugin method from the template", $file, $line,
                    'Add a method to the type (or pass the value from PHP) and use that.');
            }
            foreach (Templates::references($module) as $ref) {
                if ($ref['pattern'] !== null) {
                    $matches = array_filter(array_keys($templates), static fn(string $name): bool => fnmatch($ref['pattern'], $name));
                    foreach ($matches as $match) {
                        $used[$match] = true;
                    }
                    // source() loads any file (icons/*.svg), not only templates; @namespaces aren't checked.
                    if ($matches === [] && !str_starts_with($ref['pattern'], '@') && (glob(Paths::views() . '/' . $ref['pattern']) ?: []) === []) {
                        $report->error('views', "{$ref['kind']} loads {$ref['pattern']}, which matches no file in views/", $file, $ref['line']);
                    }
                }
                if ($ref['template'] !== null) {
                    $used[$ref['template']] = true;
                    if (!$loader->exists($ref['template'])) {
                        $report->error('views', "{$ref['kind']} loads {$ref['template']}, which doesn't exist", $file, $ref['line']);
                    }
                }
                if ($ref['kind'] === '{% include %}' || $ref['kind'] === '{% embed %}') {
                    $report->warning('views', "Use the include() function instead of {$ref['kind']}", $file, $ref['line'],
                        "{{ include('" . ($ref['template'] ?? 'x.twig') . "', { ... }, with_context = false) }}");
                } elseif (!$ref['isolated']) {
                    $report->warning('views', 'include() passes all of this template\'s variables', $file, $ref['line'],
                        "Pass what the partial needs: {{ include('" . ($ref['template'] ?? 'x.twig') . "', { ... }, with_context = false) }}");
                }
            }
        }

        foreach ($templates as $name => $file) {
            if (!isset($used[$name])) {
                $report->warning('views', 'Nothing renders or includes this template', $file, null,
                    'Delete it, unless PHP renders it with a computed name.');
            }
        }
    }
}
