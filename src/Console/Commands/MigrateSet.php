<?php

declare(strict_types=1);

namespace Gaffer\Console\Commands;

use Gaffer\BlockFields;
use Gaffer\Blocks;
use Gaffer\Console\Migrate\BlockData;
use Gaffer\Console\Migrate\ContentStore;
use Gaffer\Console\Migrate\Migration;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Sets one field of an ACF block in stored content to a value (a field added to a block that
 * existing pages need filled, demo content): the value given as JSON, by field name, checked
 * and stored the way Blocks::markup() writes it (a repeater's rows, IDs as ACF keeps them), the
 * field's old values (a repeater's rows too) replaced. Every other block and the rest of the
 * block's data stay as they are. Dry run, backup and rollback as every migrate:* command.
 */
#[AsCommand('migrate:set', "Set a block field's value in stored content (value as JSON)")]
final class MigrateSet extends MigrateCommand
{
    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->addArgument('block', InputArgument::REQUIRED, 'The block, e.g. acf/product-offers');
        $this->addArgument('field', InputArgument::REQUIRED, 'A top-level field name of the block');
        $this->addArgument('value', InputArgument::REQUIRED, 'The value as JSON, as Blocks::markup() takes it: \'[17, 23]\' (posts), \'"Text"\', \'true\', \'[{"vraag": "…"}]\' (repeater rows)');
        $this->addOption('post', null, InputOption::VALUE_REQUIRED, 'Only in this post (ID); else in every post and widget with the block');
    }

    #[\Override]
    protected function plan(Migration $migration, InputInterface $input): void
    {
        $block = (string) $input->getArgument('block');
        $field = (string) $input->getArgument('field');
        $post = $input->getOption('post');
        if ($post !== null && !ctype_digit((string) $post)) {
            $migration->problem('--post takes a post ID');
            return;
        }

        try {
            $value = json_decode((string) $input->getArgument('value'), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $migration->problem("The value isn't JSON ({$e->getMessage()}): a text is '\"Text\"', a list of IDs '[17, 23]'");
            return;
        }
        try {
            $data = Blocks::data_for($block, [$field => $value]);
        } catch (InvalidArgumentException $e) {
            $migration->problem($e->getMessage());
            return;
        }
        $prefix = BlockFields::prefix($block);

        $found = 0;
        foreach (ContentStore::find("<!-- wp:{$block} ") as $location) {
            if ($post !== null && ($location->kind !== 'post' || $location->id !== (int) $post)) {
                continue;
            }
            $found++;
            $migration->rewrite($location, static function (array $b) use ($block, $prefix, $field, $data): array {
                if ($b['blockName'] !== $block) {
                    return [$b, 0, []];
                }
                $old = is_array($b['attrs']['data'] ?? null) ? $b['attrs']['data'] : [];
                if ($problems = BlockData::problems($old, $prefix)) {
                    return [$b, 0, array_map(static fn(string $p): string => "{$block}: {$p}", $problems)];
                }
                $new = [...BlockData::remove_field($old, $prefix, [$field])['data'], ...$data];
                $sorted = static function (array $data): array {
                    ksort($data);
                    return $data;
                };
                if ($sorted($new) === $sorted($old)) { // already this value
                    return [$b, 0, []];
                }
                $b['attrs']['data'] = $new;

                return [$b, 1, []];
            });
        }
        if ($found === 0) {
            $migration->problem($post !== null ? "Post {$post} has no {$block} block" : "No content has a {$block} block");
        }
    }
}
