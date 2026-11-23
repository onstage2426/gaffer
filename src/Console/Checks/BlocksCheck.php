<?php

declare(strict_types=1);

namespace Gaffer\Console\Checks;

use Gaffer\BlockFields;
use Gaffer\Console\Report;
use Gaffer\Paths;

/**
 * Each block: block.json + functions.php + {dir}.twig, a name that is the folder's
 * (BlockFields::name()), a real description, a valid fields.php whose fields functions.php all
 * reads, and no InnerBlocks; what Gaffer sets itself (name, acf, the editor guard) as one warning.
 */
final class BlocksCheck implements Check
{
    /** The editor guard functions.php had before Gaffer rendered blocks (Acf::render_block()). */
    private const string GUARD = '/if\s*\(\s*is_admin\(\)\s*\)\s*\{?\s*return\s*;\s*\}?\s*/';

    #[\Override]
    public function run(Report $report): void
    {
        $seen = [];
        $leftovers = [];

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
            if (!is_array($meta)) {
                $report->error('blocks', 'block.json is not a JSON object', "{$dir}/block.json");
                continue;
            }
            $name = BlockFields::name($block);
            $expected = BlockFields::folder_name($block);

            if ($name !== $expected) {
                $report->error('blocks', "Name is \"{$name}\", expected \"{$expected}\" (from the folder)", "{$dir}/block.json", null,
                    'The name is stored in post content: after renaming, php gaffer migrate:rename-block <old> <new>.');
            }
            if (isset($seen[$name])) {
                $report->error('blocks', "Name \"{$name}\" is also used by {$seen[$name]}", "{$dir}/block.json");
            }
            $seen[$name] = $block;

            $description = trim((string) ($meta['description'] ?? ''));
            if ($description === '' || str_starts_with($description, 'Description for')) {
                $report->warning('blocks', 'Missing or placeholder description', "{$dir}/block.json");
            }

            $acf = is_array($meta['acf'] ?? null) ? $meta['acf'] : [];
            if (($acf['blockVersion'] ?? 3) !== 3) {
                $report->error('blocks', 'acf.blockVersion is not 3', "{$dir}/block.json", null,
                    'Remove it: Gaffer registers every block as ACF Blocks V3 (the iframed editor of WordPress 7.1+).');
            }
            if (isset($acf['renderCallback']) || ($acf['renderTemplate'] ?? 'functions.php') !== 'functions.php') {
                $report->warning('blocks', 'Renders through its own acf.renderTemplate/renderCallback, not functions.php', "{$dir}/block.json", null,
                    'Remove it: Gaffer renders functions.php (nothing in the editor).');
            }

            $php = is_file("{$dir}/functions.php") ? (string) file_get_contents("{$dir}/functions.php") : '';
            // Only the acf keys Gaffer sets: the block's own (mode, …) stay.
            $gaffers = array_key_exists('blockVersion', $acf) || ($acf['renderTemplate'] ?? null) === 'functions.php';
            if (array_key_exists('name', $meta) || $gaffers || preg_match(self::GUARD, $php)) {
                $leftovers[] = $block;
            }

            $twig = is_file("{$dir}/{$block}.twig") ? (string) file_get_contents("{$dir}/{$block}.twig") : '';
            if (str_contains($twig, '<InnerBlocks')) {
                $report->error('blocks', 'Uses <InnerBlocks>: blocks don\'t contain other blocks (decided)', "{$dir}/{$block}.twig", null,
                    'Make separate blocks; rich text goes in a wysiwyg field.');
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
                            'Read it in functions.php (also when an app/ class does the work: pass it the values) and pass it to the template, or remove it from fields.php.');
                    }
                }
            }
        }

        if ($leftovers !== []) {
            $report->warning('blocks', count($leftovers) . (count($leftovers) === 1 ? ' block still writes' : ' blocks still write') . ' what Gaffer sets: "name" and "acf" in block.json, or the is_admin() guard in functions.php (' . implode(', ', array_slice($leftovers, 0, 5)) . (count($leftovers) > 5 ? ', …' : '') . ')', Paths::blocks(), null,
                'Gaffer names a block after its folder, registers it as ACF Blocks V3 and renders functions.php only outside the editor. Remove them (CHANGELOG 2026-11-23).');
        }
    }

    /**
     * Data field names, sub fields included (tabs and messages have none).
     *
     * @param list<array<string, mixed>> $fields
     * @return list<string>
     */
    public static function names_in(array $fields): array
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
