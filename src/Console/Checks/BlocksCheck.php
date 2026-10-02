<?php

declare(strict_types=1);

namespace Gaffer\Console\Checks;

use Gaffer\BlockFields;
use Gaffer\Console\Report;
use Gaffer\Paths;

/**
 * Each block: block.json + functions.php + {dir}.twig, name = acf/ + kebab-case of the
 * directory, a real description, the is_admin() guard, and a valid fields.php whose
 * fields functions.php all reads.
 */
final class BlocksCheck implements Check
{
    #[\Override]
    public function run(Report $report): void
    {
        $seen = [];

        foreach (glob(Paths::blocks() . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $block = basename($dir);

            foreach (['block.json', 'functions.php', "{$block}.twig"] as $file) {
                if (!is_file("{$dir}/{$file}")) {
                    $report->error('blocks', "Missing {$file}", $dir);
                }
            }

            $json = is_file("{$dir}/block.json") ? (string) file_get_contents("{$dir}/block.json") : null;
            if ($json === null) {
                continue;
            }
            if (!json_validate($json)) {
                $report->error('blocks', 'Invalid JSON: ' . json_last_error_msg(), "{$dir}/block.json");
                continue;
            }

            $meta = json_decode($json, true);
            $name = $meta['name'] ?? null;
            $expected = self::expected_name($block);

            if ($name !== $expected) {
                $report->error('blocks', "Name is \"{$name}\", expected \"{$expected}\"", "{$dir}/block.json", null,
                    'The name is stored in post content and ACF location rules, so renaming means migrating both.');
            }
            if (is_string($name) && isset($seen[$name])) {
                $report->error('blocks', "Name \"{$name}\" is also used by {$seen[$name]}", "{$dir}/block.json");
            }
            $seen[$name] = $block;

            $description = trim((string) ($meta['description'] ?? ''));
            if ($description === '' || str_starts_with($description, 'Description for')) {
                $report->warning('blocks', 'Missing or placeholder description', "{$dir}/block.json");
            }

            if (($meta['acf']['renderTemplate'] ?? null) !== 'functions.php') {
                $report->warning('blocks', 'acf.renderTemplate is not "functions.php"', "{$dir}/block.json");
            }

            $php = is_file("{$dir}/functions.php") ? (string) file_get_contents("{$dir}/functions.php") : '';
            if ($php !== '' && !preg_match('/if\s*\(\s*is_admin\(\)\s*\)\s*\{?\s*return/', $php)) {
                $report->warning('blocks', 'No early `if (is_admin()) return;` guard', "{$dir}/functions.php", null,
                    'Blocks render nothing in the editor on purpose.');
            }

            if (is_file("{$dir}/fields.php")) {
                try {
                    $fields = BlockFields::group($block)['fields'];
                } catch (\Throwable $e) {
                    $report->error('blocks', $e->getMessage(), "{$dir}/fields.php");
                    $fields = [];
                }
                foreach (self::names_in($fields) as $field) {
                    if (!preg_match('/([\'"])' . preg_quote($field, '/') . '\1/', $php)) {
                        $report->warning('blocks', "Field \"{$field}\" is never read in functions.php", "{$dir}/fields.php", null,
                            'Pass it to the template, or remove it from fields.php.');
                    }
                }
            }
        }
    }

    /**
     * Block names by directory name, from each block.json (valid JSON only).
     *
     * @return array<string, string>
     */
    public static function names(): array
    {
        $names = [];

        foreach (glob(Paths::blocks() . '/*/block.json') ?: [] as $file) {
            $meta = json_decode((string) file_get_contents($file), true);
            if (is_array($meta) && is_string($meta['name'] ?? null)) {
                $names[basename(dirname($file))] = $meta['name'];
            }
        }

        return $names;
    }

    /** contentFaq → acf/content-faq */
    public static function expected_name(string $directory): string
    {
        return 'acf/' . strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $directory));
    }

    /**
     * Data field names, sub fields included (tabs and messages have none).
     *
     * @param list<array<string, mixed>> $fields
     * @return list<string>
     */
    private static function names_in(array $fields): array
    {
        $names = [];
        foreach ($fields as $field) {
            if (is_string($field['name'] ?? null) && $field['name'] !== '') {
                $names[] = $field['name'];
            }
            if (is_array($field['sub_fields'] ?? null)) {
                array_push($names, ...self::names_in($field['sub_fields']));
            }
        }

        return $names;
    }
}
