<?php

declare(strict_types=1);

namespace Gaffer\Console\Commands;

use Gaffer\BlockFields;
use Gaffer\Console\Checks\BlocksCheck;
use Gaffer\Console\Migrate\BlockData;
use Gaffer\Console\Migrate\ContentStore;
use Gaffer\Console\Migrate\FieldTarget;
use Gaffer\Console\Migrate\Migration;
use Gaffer\Paths;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;

#[AsCommand('migrate:remove-field', "Delete a removed field's values from stored content (after removing it from fields.php or fields/)")]
final class MigrateRemoveField extends MigrateCommand
{
    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->addArgument('target', InputArgument::REQUIRED, 'A block (acf/content-faq) or a fields/ target (post_type/product)');
        $this->addArgument('field', InputArgument::REQUIRED, 'Field name; a sub field as parent.child, e.g. vragen.bron');
    }

    #[\Override]
    protected function plan(Migration $migration, InputInterface $input): void
    {
        $block = (string) $input->getArgument('target');
        $path = explode('.', (string) $input->getArgument('field'));

        foreach ($path as $name) {
            if (!preg_match('/^[A-Za-z0-9-][A-Za-z0-9_-]*$/', $name) || str_contains($name, '__')) {
                $migration->problem("\"{$name}\" is not a field name");
                return;
            }
        }
        if (!str_starts_with($block, 'acf/')) {
            self::plan_target($migration, $block, $path);
            return;
        }
        $dir = array_search($block, BlocksCheck::names(), true);
        if ($dir === false || !is_file(Paths::blocks() . "/{$dir}/fields.php")) {
            $migration->problem("{$block} is not a block with a fields.php");
            return;
        }

        $prefix = BlockFields::prefix($block);
        $key = $prefix . '__' . implode('__', $path);
        if (MigrateField::has_key(BlockFields::group($dir)['fields'], $key)) {
            $migration->problem("blocks/{$dir}/fields.php still has " . implode('.', $path) . ': remove it there first');
            return;
        }

        foreach (ContentStore::find("<!-- wp:{$block} ") as $location) {
            $migration->rewrite($location, static function (array $found) use ($block, $prefix, $path): array {
                if ($found['blockName'] !== $block || !is_array($found['attrs']['data'] ?? null)) {
                    return [$found, 0, []];
                }
                if ($problems = BlockData::problems($found['attrs']['data'], $prefix)) {
                    $problems = array_map(static fn(string $p): string => "{$block}: {$p}", $problems);
                    if (array_any($problems, static fn(string $p): bool => str_contains($p, 'not to a field of this block'))) {
                        $problems[] = "{$block}: values that point elsewhere are moved by php gaffer migrate:fields {$block} or, when fields.php has no such field, deleted by php gaffer migrate:remove-leftovers {$block}";
                    }
                    return [$found, 0, $problems];
                }
                $result = BlockData::remove_field($found['attrs']['data'], $prefix, $path);
                $found['attrs']['data'] = $result['data'];

                return [$found, $result['changes'], []];
            });
        }
    }

    /**
     * Deletes every row stored under the field's name in a fields/ target (values and
     * references; posts and their revisions, terms, options in every language), by row ID.
     * The field must be gone from the file and from the target's UI groups.
     *
     * @param list<string> $path
     */
    private static function plan_target(Migration $migration, string $target, array $path): void
    {
        $migration->summarize();
        try {
            $sorter = FieldTarget::sorter($target);
        } catch (\RuntimeException $e) {
            $migration->problem($e->getMessage());
            return;
        }
        $joined = implode('.', $path);
        if ($sorter->has($joined)) {
            $migration->problem("fields/{$target}.php still has {$joined}: remove it there first");
            return;
        }
        if (($group = FieldTarget::ui_group_with($target, $joined)) !== null) {
            $migration->problem("The ACF UI group \"{$group}\" still has {$joined}: move the group with php gaffer migrate:fields {$target} and delete it first");
            return;
        }
        $pattern = $sorter->child_pattern($path);
        if ($pattern === null) {
            $migration->problem(implode('.', array_slice($path, 0, -1)) . " isn't a repeater or group in fields/{$target}.php");
            return;
        }

        [$param, $value] = explode('/', $target, 2);
        $migration->progress("{$target}: finding the rows of {$joined}…");
        foreach (ContentStore::target_rows($param, $value, $sorter->name_regex($pattern)) as $row) {
            $migration->change($row->location, $row->key, null, "remove \"{$joined}\"");
        }
    }
}
