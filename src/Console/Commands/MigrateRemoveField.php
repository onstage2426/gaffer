<?php

declare(strict_types=1);

namespace Gaffer\Console\Commands;

use Gaffer\BlockFields;
use Gaffer\Console\Checks\BlocksCheck;
use Gaffer\Console\Migrate\BlockData;
use Gaffer\Console\Migrate\ContentStore;
use Gaffer\Console\Migrate\Migration;
use Gaffer\Paths;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;

#[AsCommand('migrate:remove-field', "Delete a removed field's values from stored content (after removing it from fields.php)")]
final class MigrateRemoveField extends MigrateCommand
{
    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->addArgument('block', InputArgument::REQUIRED, 'Block name, e.g. acf/content-faq');
        $this->addArgument('field', InputArgument::REQUIRED, 'Field name; a sub field as parent.child, e.g. vragen.bron');
    }

    #[\Override]
    protected function plan(Migration $migration, InputInterface $input): void
    {
        $block = (string) $input->getArgument('block');
        $path = explode('.', (string) $input->getArgument('field'));

        foreach ($path as $name) {
            if (!preg_match('/^[A-Za-z0-9-][A-Za-z0-9_-]*$/', $name) || str_contains($name, '__')) {
                $migration->problem("\"{$name}\" is not a field name");
                return;
            }
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
                    return [$found, 0, array_map(static fn(string $p): string => "{$block}: {$p}", $problems)];
                }
                $result = BlockData::remove_field($found['attrs']['data'], $prefix, $path);
                $found['attrs']['data'] = $result['data'];

                return [$found, $result['changes'], []];
            });
        }
    }
}
