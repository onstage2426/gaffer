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
use Throwable;

/**
 * Deletes values a block stores for fields of something else (another block's fields.php,
 * an old ACF field group) when its own fields.php has no field by that name: data a block
 * kept after being transformed or copied, which it never shows. Values whose name is a
 * field in fields.php are left for migrate:fields.
 *
 * For a fields/ target: deletes the references nothing reads (FieldReferences::leftovers():
 * a key of the target's fields under another name than that field's) with the value stored
 * next to each.
 */
#[AsCommand('migrate:remove-leftovers', "Delete values a block or fields/ target stores that nothing reads (another block's fields, old names, broken keys)")]
final class MigrateRemoveLeftovers extends MigrateCommand
{
    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->addArgument('target', InputArgument::OPTIONAL, 'A block (acf/content-faq) or a fields/ target (post_type/product) (default: every block with a fields.php)');
    }

    #[\Override]
    protected function plan(Migration $migration, InputInterface $input): void
    {
        $only = $input->getArgument('target');
        if (is_string($only) && !str_starts_with($only, 'acf/')) {
            self::plan_target($migration, $only);
            return;
        }
        $blocks = array_filter(
            BlocksCheck::names(),
            static fn(string $name, string $dir): bool => is_file(Paths::blocks() . "/{$dir}/fields.php") && (!is_string($only) || $only === $name),
            ARRAY_FILTER_USE_BOTH,
        );
        if (is_string($only) && $blocks === []) {
            $migration->problem("{$only} is not a block with a fields.php");
            return;
        }

        $handlers = [];
        foreach ($blocks as $dir => $block) {
            try {
                $fields = BlockFields::group($dir)['fields'];
            } catch (Throwable $e) {
                $migration->problem($e->getMessage());
                continue;
            }
            $prefix = BlockFields::prefix($block);

            $handlers[$block] = static function (array $found) use ($block, $prefix, $fields): array {
                if (!is_array($found['attrs']['data'] ?? null)) {
                    return [$found, 0, []];
                }
                // Leftovers point elsewhere by definition; every other problem stops it.
                $problems = array_filter(
                    BlockData::problems($found['attrs']['data'], $prefix),
                    static fn(string $problem): bool => !str_contains($problem, 'not to a field of this block'),
                );
                if ($problems !== []) {
                    return [$found, 0, array_map(static fn(string $p): string => "{$block}: {$p}", array_values($problems))];
                }
                $result = BlockData::remove_leftovers($found['attrs']['data'], $prefix, $fields);
                $found['attrs']['data'] = $result['data'];

                return [$found, $result['changes'], []];
            };
        }
        self::rewrite_blocks($migration, $handlers);
    }

    private static function plan_target(Migration $migration, string $target): void
    {
        $migration->summarize();
        try {
            $sorter = FieldTarget::sorter($target);
        } catch (\RuntimeException $e) {
            $migration->problem($e->getMessage());
            return;
        }
        [$param, $value] = explode('/', $target, 2);
        $prefixes = $param === 'options_page' ? FieldTarget::option_prefixes($value) : [];

        $migration->progress("{$target}: finding its references…");
        $leftovers = $sorter->leftovers(ContentStore::target_references($param, $value, [], $prefixes));

        // The value next to each leftover: same owner, the name without its "_".
        $values = [];
        $names = array_unique(array_filter(array_map(static fn(array $leftover): string => substr($leftover[0]->name, 1), $leftovers)));
        if ($names !== []) {
            $pattern = '(' . implode('|', array_map(static fn(string $name): string => preg_quote($name, '/'), $names)) . ')';
            foreach (ContentStore::target_rows($param, $value, '^' . $pattern . '$') as $row) {
                $values["{$row->location->kind}:{$row->owner}:{$row->name}"][] = $row;
            }
        }

        foreach ($leftovers as [$reference, $why]) {
            $migration->change($reference->location, $reference->key, null, "remove a reference {$why}");
            foreach ($values["{$reference->location->kind}:{$reference->owner}:" . substr($reference->name, 1)] ?? [] as $row) {
                $migration->change($row->location, $row->key, null, "remove the value of a reference {$why}");
            }
        }
    }
}
