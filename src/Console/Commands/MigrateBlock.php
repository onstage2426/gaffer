<?php

declare(strict_types=1);

namespace Gaffer\Console\Commands;

use Gaffer\BlockFields;
use Gaffer\Console\Migrate\BlockData;
use Gaffer\Console\Migrate\ContentStore;
use Gaffer\Console\Migrate\Migration;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use WP_Block_Type_Registry;

#[AsCommand('migrate:block', 'Rename a block in stored content (after renaming its directory and block.json)')]
final class MigrateBlock extends MigrateCommand
{
    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->addArgument('from', InputArgument::REQUIRED, 'Old block name, e.g. acf/content-faq');
        $this->addArgument('to', InputArgument::REQUIRED, 'New block name, e.g. acf/faq');
    }

    #[\Override]
    protected function plan(Migration $migration, InputInterface $input): void
    {
        $from = (string) $input->getArgument('from');
        $to = (string) $input->getArgument('to');

        foreach ([$from, $to] as $name) {
            if (!preg_match('#^acf/[a-z0-9-]+$#', $name)) {
                $migration->problem("\"{$name}\" is not an ACF block name (acf/kebab-name)");
                return;
            }
        }
        $registry = WP_Block_Type_Registry::get_instance();
        if ($registry->is_registered($from)) {
            $migration->problem("{$from} is still registered: rename the block's directory and block.json first");
        }
        if (!$registry->is_registered($to)) {
            $migration->problem("{$to} is not registered: rename the block's directory and block.json first");
        }

        if ($migration->has_problems()) {
            return; // the code isn't ready: every value would be a problem too
        }

        $from_prefix = BlockFields::prefix($from);
        $rename = BlockData::block_renamer($from_prefix, BlockFields::prefix($to));

        foreach (ContentStore::find("<!-- wp:{$from} ") as $location) {
            $migration->rewrite($location, static function (array $block) use ($from, $to, $from_prefix, $rename): array {
                if ($block['blockName'] !== $from) {
                    return [$block, 0, []];
                }
                $data = is_array($block['attrs']['data'] ?? null) ? $block['attrs']['data'] : [];
                if ($problems = BlockData::problems($data, $from_prefix)) {
                    return [$block, 0, array_map(static fn(string $p): string => "{$from}: {$p}", $problems)];
                }
                $result = BlockData::rename($data, $rename);
                if ($result['problems'] !== []) {
                    return [$block, 0, $result['problems']];
                }

                $block['blockName'] = $to;
                if (isset($block['attrs']['name'])) {
                    $block['attrs']['name'] = $to;
                }
                if ($data !== []) {
                    $block['attrs']['data'] = $result['data'];
                }

                return [$block, 1 + $result['changes'], []];
            });
        }
    }
}
