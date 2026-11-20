<?php

declare(strict_types=1);

namespace Gaffer\Console\Checks;

use Gaffer\Console\Report;
use Gaffer\Console\ThemeFiles;
use Gaffer\FieldGroups;
use Gaffer\Paths;
use Throwable;

/**
 * fields/: only known location params, files that make a valid field group, and
 * fields the theme never mentions. The same field name on two options pages is
 * WordPressCheck's (shared_options()): only ACF knows each page's post_id.
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
     * Field names on more than one options page that store under the same post_id (ACF stores
     * an options page's values as {post_id}_{name}, "options" unless the page sets its own):
     * name => the targets, which then share one value.
     *
     * @param array<string, list<array<string, mixed>>> $groups target => its fields
     * @param \Closure(string): string $post_id an options page's post_id, by its slug
     * @return array<string, list<string>>
     */
    public static function shared_options(array $groups, \Closure $post_id): array
    {
        $stored = [];
        foreach ($groups as $target => $fields) {
            if (!str_starts_with($target, 'options_page/')) {
                continue;
            }
            $prefix = $post_id(substr($target, strlen('options_page/')));
            foreach ($fields as $field) {
                if (is_string($field['name'] ?? null) && $field['name'] !== '') {
                    $stored[$prefix][$field['name']][] = $target;
                }
            }
        }

        $shared = [];
        foreach ($stored as $names) {
            foreach ($names as $name => $targets) {
                if (count($targets) > 1) {
                    $shared[$name] = [...$shared[$name] ?? [], ...$targets];
                }
            }
        }

        return $shared;
    }

    /**
     * Every quoted string in the theme's PHP and Twig (outside fields/) and in Gaffer's own code
     * (Term::thumbnail() reads thumbnail_id), and every .name in Twig.
     *
     * @return array<string, true>
     */
    private static function mentioned(): array
    {
        $mentioned = [];
        foreach ([...ThemeFiles::find(['php', 'twig']), ...ThemeFiles::find(['php'], dirname(__DIR__, 2))] as $file) {
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
