<?php

declare(strict_types=1);

namespace Gaffer\Console\Commands;

use Gaffer\BlockFields;
use Gaffer\Console\Checks\BlocksCheck;
use Gaffer\Console\Migrate\BlockData;
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
 */
#[AsCommand('migrate:remove-leftovers', "Delete values a block stores for another block's or field group's fields that its fields.php doesn't have")]
final class MigrateRemoveLeftovers extends MigrateCommand
{
    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->addArgument('block', InputArgument::OPTIONAL, 'Block name, e.g. acf/content-faq (default: every block with a fields.php)');
    }

    #[\Override]
    protected function plan(Migration $migration, InputInterface $input): void
    {
        $only = $input->getArgument('block');
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
}
