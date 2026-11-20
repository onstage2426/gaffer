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
 * get_*() calls that reach past the types into WordPress/WooCommerce, meta() calls
 * (field names belong in the type), |t on anything but a literal string, and an
 * include inside a <form> that brings a <form> of its own.
 */
final class TemplatesCheck implements Check
{
    /** @var array<string, ?string> by template: the template (itself or one it includes) with a <form>, if any */
    private array $forms = [];

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
            foreach (Templates::php_renders($code) as ['template' => $name, 'pattern' => $pattern, 'offset' => $offset]) {
                if ($name !== null) {
                    $used[$name] = true;
                    if (!$loader->exists($name)) {
                        $report->error('views', "Renders {$name}, which doesn't exist", $file, substr_count($code, "\n", 0, $offset) + 1);
                    }
                    continue;
                }
                // A computed name ("cards/{$type}.twig"): the templates it can be are used; which one exists is only known at runtime.
                $matches = $pattern === null ? [] : array_filter(array_keys($templates), static fn(string $t): bool => fnmatch($pattern, $t));
                foreach ($matches as $match) {
                    $used[$match] = true;
                }
                if ($pattern !== null && $matches === []) {
                    $report->info('views', "Renders a computed name ({$pattern}) no template matches", $file, substr_count($code, "\n", 0, $offset) + 1,
                        'Fine when the templates come later; else check the name.');
                }
            }
        }

        // Card templates config/search.php renders into the index (a list layout no PHP renders).
        foreach (\Gaffer\Search\Indexes::all() as $definition) {
            foreach (\Gaffer\Search\Indexes::templates($definition) as $card) {
                $used[$card] = true;
            }
        }

        foreach ($templates as $name => $file) {
            $module = Templates::parse($name);
            if ($module === null) {
                continue; // twig:lint reports it
            }
            foreach (Templates::method_calls($module, '/^get_/') as [$method, $line]) {
                $report->warning('views', "Calls {$method}(): a WordPress/WooCommerce/plugin method from the template", $file, $line,
                    'Add a method to the type (or pass the value from PHP) and use that.');
            }
            foreach (Templates::method_calls($module, '/^meta$/') as [, $line]) {
                $report->warning('views', 'Calls meta(): the template reads a field by name', $file, $line,
                    "Add a method to the type that reads it (term.tint(), not term.meta('tint')) and use that.");
            }
            foreach (Templates::includes_in_form($module) as $ref) {
                $inner = $ref['template'] !== null ? $this->form_in($ref['template'], $templates) : null;
                if ($inner !== null) {
                    $report->warning('views', "Includes {$ref['template']} inside a <form>, and " . ($inner === $ref['template'] ? 'it has' : "it includes {$inner}, which has") . ' a <form> itself', $file, $ref['line'],
                        'Browsers drop a <form> inside another one: its fields then belong to the outer form. Move the include out of the form, or close the form before it.');
                }
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
                } elseif ($ref['kind'] === 'include()' && !$ref['isolated']) {
                    $report->warning('views', 'include() passes all of this template\'s variables', $file, $ref['line'],
                        "Pass what the partial needs: {{ include('" . ($ref['template'] ?? 'x.twig') . "', { ... }, with_context = false) }}");
                }
            }
        }

        foreach ($templates as $name => $file) {
            if (!isset($used[$name])) {
                $report->warning('views', 'Nothing renders or includes this template', $file, null,
                    'Delete it. If PHP builds its name, write the name literally (View::fetch(\'emails/x.twig\')) so doctor sees it; baseline it only when it can\'t be literal.');
            }
        }
    }

    /**
     * The template with a <form> that rendering $name brings in: $name itself or one it
     * includes (by fixed name), or null. Twig comments don't count.
     *
     * @param array<string, string> $templates
     */
    private function form_in(string $name, array $templates): ?string
    {
        if (!array_key_exists($name, $this->forms)) {
            $this->forms[$name] = null;
            foreach (Templates::brought_in($name) as $template) {
                if (isset($templates[$template]) && preg_match('/<form\b/i', (string) Templates::code($template))) {
                    $this->forms[$name] = $template;
                    break;
                }
            }
        }

        return $this->forms[$name];
    }
}
