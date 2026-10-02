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
use Throwable;

#[AsCommand('migrate:field', "Rename a block's field in stored content (after renaming it in fields.php)")]
final class MigrateField extends MigrateCommand
{
    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->addArgument('block', InputArgument::REQUIRED, 'Block name, e.g. acf/content-faq');
        $this->addArgument('field', InputArgument::REQUIRED, 'Old field name; a sub field as parent.child, e.g. vragen.vraag');
        $this->addArgument('to', InputArgument::REQUIRED, 'New name (just the name, also for a sub field)');
    }

    #[\Override]
    protected function plan(Migration $migration, InputInterface $input): void
    {
        $block = (string) $input->getArgument('block');
        $path = explode('.', (string) $input->getArgument('field'));
        $to = (string) $input->getArgument('to');

        foreach ([...$path, $to] as $name) {
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

        try {
            $keys = self::keys(BlockFields::group($dir)['fields']);
        } catch (Throwable $e) {
            $migration->problem($e->getMessage());
            return;
        }
        $prefix = BlockFields::prefix($block);
        $old = $prefix . '__' . implode('__', $path);
        $new = $prefix . '__' . implode('__', [...array_slice($path, 0, -1), $to]);
        if (isset($keys[$old])) {
            $migration->problem("blocks/{$dir}/fields.php still has " . implode('.', $path) . ": rename it there first");
        }
        if (!isset($keys[$new])) {
            $migration->problem("blocks/{$dir}/fields.php has no " . implode('.', [...array_slice($path, 0, -1), $to]));
        }

        if ($migration->has_problems()) {
            return; // the code isn't ready: every value would be a problem too
        }

        $rename = BlockData::field_renamer($prefix, $path, $to);
        foreach (ContentStore::find("<!-- wp:{$block} ") as $location) {
            $migration->rewrite($location, static function (array $found) use ($block, $prefix, $rename): array {
                if ($found['blockName'] !== $block || !is_array($found['attrs']['data'] ?? null)) {
                    return [$found, 0, []];
                }
                if ($problems = BlockData::problems($found['attrs']['data'], $prefix)) {
                    return [$found, 0, array_map(static fn(string $p): string => "{$block}: {$p}", $problems)];
                }
                $result = BlockData::rename($found['attrs']['data'], $rename);
                if ($result['problems'] !== []) {
                    return [$found, 0, array_map(static fn(string $p): string => "{$block}: {$p}", $result['problems'])];
                }
                $found['attrs']['data'] = $result['data'];

                return [$found, $result['changes'], []];
            });
        }
    }

    /**
     * @param list<array<string, mixed>> $fields
     * @return array<string, true>
     */
    private static function keys(array $fields): array
    {
        $keys = [];
        foreach ($fields as $field) {
            $keys[(string) $field['key']] = true;
            if (is_array($field['sub_fields'] ?? null)) {
                $keys += self::keys($field['sub_fields']);
            }
        }

        return $keys;
    }
}
