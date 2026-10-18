<?php

declare(strict_types=1);

namespace Gaffer\Console\Commands;

use Gaffer\BlockFields;
use Gaffer\Console\Migrate\ContentStore;
use Gaffer\FieldGroups;
use Gaffer\Console\Checks\BlocksCheck;
use Gaffer\Console\Migrate\BlockData;
use Gaffer\Console\Migrate\Migration;
use Gaffer\Paths;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Throwable;

/**
 * Moves stored field references from field group keys (made in the ACF UI or
 * JSON, "field_6a2b…") to the keys derived from the code, after the fields moved
 * to code: block data to the block's fields.php, and the "_name" rows of posts,
 * terms and options to fields/{param}/{value}.php. Each value's field is found
 * by its name.
 */
#[AsCommand('migrate:fields', 'Move stored field references to the keys of fields.php / fields/ (after moving a field group from the ACF UI/JSON to code)')]
final class MigrateFields extends MigrateCommand
{
    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->addArgument('target', InputArgument::OPTIONAL, 'A block (acf/content-faq) or a fields/ target (post_type/review) (default: every block with a fields.php and every file in fields/)');
    }

    #[\Override]
    protected function plan(Migration $migration, InputInterface $input): void
    {
        $only = $input->getArgument('target');
        if (!is_string($only) || str_starts_with($only, 'acf/')) {
            $this->plan_blocks($migration, is_string($only) ? $only : null);
        }
        if (!is_string($only) || !str_starts_with($only, 'acf/')) {
            $this->plan_groups($migration, is_string($only) ? $only : null);
        }

        $migration->note('Afterwards: delete the old field groups in ACF (php gaffer doctor lists them).');
    }

    private function plan_blocks(Migration $migration, ?string $only): void
    {
        $blocks = array_filter(
            BlocksCheck::names(),
            static fn(string $name, string $dir): bool => is_file(Paths::blocks() . "/{$dir}/fields.php") && (!is_string($only) || $only === $name),
            ARRAY_FILTER_USE_BOTH,
        );
        if (is_string($only) && $blocks === []) {
            $migration->problem("{$only} is not a block with a fields.php");
            return;
        }

        // The old key's field type, while ACF still has the old field group.
        $type = static function (string $key): ?string {
            $field = function_exists('acf_get_field') ? acf_get_field($key) : false;
            return is_array($field) && is_string($field['type'] ?? null) ? $field['type'] : null;
        };

        $handlers = [];
        foreach ($blocks as $dir => $block) {
            try {
                $fields = BlockFields::group($dir)['fields'];
            } catch (Throwable $e) {
                $migration->problem($e->getMessage());
                continue;
            }
            $prefix = BlockFields::prefix($block);
            $rename = BlockData::fields_renamer($prefix, $fields, $type);

            $handlers[$block] = static function (array $found) use ($block, $prefix, $rename): array {
                if (!is_array($found['attrs']['data'] ?? null)) {
                    return [$found, 0, []];
                }
                // Keys of another field group are what this command moves; every other problem stops it.
                $problems = array_filter(
                    BlockData::problems($found['attrs']['data'], $prefix),
                    static fn(string $problem): bool => !str_contains($problem, 'not to a field of this block'),
                );
                if ($problems !== []) {
                    return [$found, 0, array_map(static fn(string $p): string => "{$block}: {$p}", array_values($problems))];
                }
                $result = BlockData::rename($found['attrs']['data'], $rename);
                if ($result['problems'] !== []) {
                    $problems = array_map(static fn(string $p): string => "{$block}: {$p}", $result['problems']);
                    if (array_any($result['problems'], static fn(string $p): bool => str_contains($p, "isn't a field in fields.php"))) {
                        $problems[] = "{$block}: values of fields that fields.php doesn't have are left over from elsewhere (another block, an old field group): php gaffer migrate:remove-leftovers {$block} deletes them";
                    }
                    return [$found, 0, $problems];
                }
                $found['attrs']['data'] = $result['data'];

                return [$found, $result['changes'], []];
            };
        }
        self::rewrite_blocks($migration, $handlers);
    }

    /**
     * Rewrites every reference row (postmeta, termmeta, options) that points to a field of a
     * UI/JSON group with the same target as a fields/ file, to the key of the field with the
     * same name (path) there. A field the file doesn't have, or has with another type, stops
     * the run when values reference it.
     */
    private function plan_groups(Migration $migration, ?string $only): void
    {
        $targets = FieldGroups::targets();
        if ($only !== null && !isset($targets[$only])) {
            $migration->problem("{$only} has no file in fields/ (expected fields/{$only}.php)");
            return;
        }

        foreach ($only !== null ? [$only] : array_keys($targets) as $target) {
            try {
                $group = FieldGroups::group($target);
            } catch (Throwable $e) {
                $migration->problem($e->getMessage());
                continue;
            }
            $new = self::by_path($group['fields']);

            // Old key => [group title, path, type, new key or null].
            $old = [];
            foreach (acf_get_field_groups() as $candidate) {
                if (($candidate['key'] ?? null) === $group['key'] || FieldGroups::target_of($candidate['location'] ?? null) !== $target) {
                    continue;
                }
                foreach (self::by_path(self::fields_of($candidate)) as $path => $field) {
                    $old[$field['key']] = [(string) $candidate['title'], $path, (string) $field['type'], $new[$path] ?? null];
                }
            }
            if ($old === []) {
                continue;
            }

            $stuck = [];
            foreach (ContentStore::references(array_map('strval', array_keys($old))) as $location) {
                $key = ContentStore::read($location);
                [$title, $path, $type, $field] = $old[$key];
                $problem = match (true) {
                    $field === null => "\"{$path}\" of \"{$title}\" isn't in fields/{$target}.php: add it, or delete its values",
                    $field['type'] !== $type => "\"{$path}\" of \"{$title}\" is a {$type} field, fields/{$target}.php makes it a {$field['type']}",
                    default => null,
                };
                if ($problem !== null) {
                    $stuck[$problem] = ($stuck[$problem] ?? 0) + 1;
                    continue;
                }
                $migration->change($location, $key, (string) $field['key'], "{$key} → {$field['key']}");
            }
            foreach ($stuck as $problem => $count) {
                $migration->problem("{$problem} ({$count} stored " . ($count === 1 ? 'value' : 'values') . ')');
            }
        }
    }

    /**
     * A field group's fields with their sub fields, as ACF loads them (a JSON or database group).
     *
     * @param array<string, mixed> $group
     * @return list<array<string, mixed>>
     */
    private static function fields_of(array $group): array
    {
        $load = static function (array $parent) use (&$load): array {
            $fields = [];
            foreach (acf_get_fields($parent) ?: [] as $field) {
                if (in_array($field['type'] ?? null, ['repeater', 'group'], true)) {
                    $field['sub_fields'] = $load($field);
                }
                $fields[] = $field;
            }
            return $fields;
        };

        return $load($group);
    }

    /**
     * Data fields by name path ("vragen.vraag"); tabs and messages have no name.
     *
     * @param list<array<string, mixed>> $fields
     * @return array<string, array<string, mixed>>
     */
    private static function by_path(array $fields, string $parent = ''): array
    {
        $paths = [];
        foreach ($fields as $field) {
            if (!is_string($field['name'] ?? null) || $field['name'] === '') {
                continue;
            }
            $path = $parent . $field['name'];
            $paths[$path] = $field;
            if (is_array($field['sub_fields'] ?? null)) {
                $paths += self::by_path($field['sub_fields'], "{$path}.");
            }
        }

        return $paths;
    }
}
