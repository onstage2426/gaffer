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
 * get_*() calls that reach past the types into WordPress/WooCommerce, and |t on
 * anything but a literal string.
 */
final class TemplatesCheck implements Check
{
    #[\Override]
    public function run(Report $report): void
    {
        Gaffer::twig();
        $loader = View::env()->getLoader();
        $templates = Templates::all();
        $used = [];

        // The whole file, not line by line: the name may be on the next line (a long call, PSR-12).
        foreach (ThemeFiles::find(['php']) as $file) {
            $code = (string) file_get_contents($file);
            preg_match_all(Templates::PHP_RENDER, $code, $m, PREG_OFFSET_CAPTURE);
            foreach ($m[2] as [$name, $offset]) {
                $used[$name] = true;
                if (!$loader->exists($name)) {
                    $report->error('views', "Renders {$name}, which doesn't exist", $file, substr_count($code, "\n", 0, $offset) + 1);
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
            foreach (Templates::non_literal_translations($module) as $line) {
                $report->warning('views', '|t on something that isn\'t a literal string', $file, $line,
                    "String scanners (WPML, Poedit) only find literals: translate each string where it's written ({{ open ? 'Close'|t : 'Open'|t }}). Content (ACF values, names from PHP) is translated as content in WPML/Polylang, not with |t.");
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
