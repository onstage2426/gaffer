<?php

declare(strict_types=1);

namespace Gaffer\Console\Commands;

use Gaffer\Console\Migrate\BlockData;
use Gaffer\Console\Migrate\ContentStore;
use Gaffer\Console\Migrate\Migration;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use WP_Block_Type_Registry;

#[AsCommand('migrate:remove-block', 'Delete every use of a removed block from stored content (after deleting its directory)')]
final class MigrateRemoveBlock extends MigrateCommand
{
    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->addArgument('block', InputArgument::REQUIRED, 'Block name, e.g. acf/content-team');
    }

    #[\Override]
    protected function plan(Migration $migration, InputInterface $input): void
    {
        $block = (string) $input->getArgument('block');

        if (!preg_match('#^acf/[a-z0-9-]+$#', $block)) {
            $migration->problem("\"{$block}\" is not an ACF block name (acf/kebab-name)");
            return;
        }
        if (WP_Block_Type_Registry::get_instance()->is_registered($block)) {
            $migration->problem("{$block} is still registered: delete the block's directory first");
            return;
        }

        foreach (ContentStore::find("<!-- wp:{$block} ") as $location) {
            $migration->rewrite_blocks($location, static function (array $blocks) use ($block): array {
                $result = BlockData::remove_blocks($blocks, $block);

                return [$result['blocks'], $result['removed'], $result['problems']];
            });
        }
    }
}
