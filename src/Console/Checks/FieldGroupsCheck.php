<?php

declare(strict_types=1);

namespace Gaffer\Console\Checks;

use Gaffer\Console\Report;
use Gaffer\Console\ThemeFiles;
use Gaffer\FieldGroups;
use Gaffer\Paths;
use Throwable;

/**
 * fields/: only known location params, files that make a valid field group,
 * no field name on two options pages (all options share one namespace), and
 * fields the theme never mentions.
 */
final class FieldGroupsCheck implements Check
{
    #[\Override]
    public function run(Report $report): void
    {
        foreach (FieldGroups::unknown_dirs() as $dir) {
            $report->error('acf', "fields/{$dir}/ isn't an ACF location Gaffer knows", Paths::fields() . "/{$dir}", null,
                'Use ' . implode(', ', array_map(static fn(string $p): string => "fields/{$p}/", FieldGroups::PARAMS)) . '.');
        }

        $groups = [];
        foreach (FieldGroups::targets() as $target => $file) {
            try {
                $groups[$target] = FieldGroups::group($target)['fields'];
            } catch (Throwable $e) {
                $report->error('acf', $e->getMessage(), $file);
            }
        }

        // Options are stored as options_{name} whatever the page: the same name on two pages is one value.
        $options = [];
        foreach ($groups as $target => $fields) {
            if (str_starts_with($target, 'options_page/')) {
                foreach ($fields as $field) {
                    if (is_string($field['name'] ?? null) && $field['name'] !== '') {
                        $options[$field['name']][] = $target;
                    }
                }
            }
        }
        foreach ($options as $name => $targets) {
            if (count($targets) > 1) {
                $report->error('acf', "Field \"{$name}\" is on two options pages (" . implode(', ', $targets) . '): they share one stored value',
                    FieldGroups::targets()[$targets[1]], null, 'Give one of them another name.');
            }
        }

        $mentioned = self::mentioned();
        foreach ($groups as $target => $fields) {
            foreach (BlocksCheck::names_in($fields) as $name) {
                if (!isset($mentioned[$name])) {
                    $report->warning('acf', "Field \"{$name}\" is never read", FieldGroups::targets()[$target], null,
                        "Nothing in the theme's PHP or templates mentions '{$name}': read it where it's shown, or remove it.");
                }
            }
        }
    }

    /**
     * Every quoted string in the theme's PHP and Twig, and every .name in Twig, outside fields/.
     *
     * @return array<string, true>
     */
    private static function mentioned(): array
    {
        $mentioned = [];
        foreach (ThemeFiles::find(['php', 'twig']) as $file) {
            if (str_starts_with($file, Paths::fields() . '/')) {
                continue;
            }
            $code = (string) file_get_contents($file);
            preg_match_all('/([\'"])([A-Za-z0-9_-]+)\1/', $code, $quoted);
            foreach ($quoted[2] as $name) {
                $mentioned[$name] = true;
            }
            if (str_ends_with($file, '.twig')) {
                preg_match_all('/\.([A-Za-z_][A-Za-z0-9_]*)/', $code, $attributes);
                foreach ($attributes[1] as $name) {
                    $mentioned[$name] = true;
                }
            }
        }

        return $mentioned;
    }
}
