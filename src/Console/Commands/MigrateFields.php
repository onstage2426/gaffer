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

/**
 * Moves stored block data from field group keys (made in the ACF UI or JSON,
 * "field_6a2b…") to the keys derived from the block's fields.php, after the
 * fields moved to code. Each value's field is found by its name in fields.php.
 */
#[AsCommand('migrate:fields', "Move stored block data to the keys of the block's fields.php (after moving its fields from the ACF UI/JSON to code)")]
final class MigrateFields extends MigrateCommand
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

        // The old key's field type, while ACF still has the old field group.
        $type = static function (string $key): ?string {
            $field = function_exists('acf_get_field') ? acf_get_field($key) : false;
            return is_array($field) && is_string($field['type'] ?? null) ? $field['type'] : null;
        };

        foreach ($blocks as $dir => $block) {
            try {
                $fields = BlockFields::group($dir)['fields'];
            } catch (Throwable $e) {
                $migration->problem($e->getMessage());
                continue;
            }
            $prefix = BlockFields::prefix($block);
            $rename = BlockData::fields_renamer($prefix, $fields, $type);

            foreach (ContentStore::find("<!-- wp:{$block} ") as $location) {
                $migration->rewrite($location, static function (array $found) use ($block, $prefix, $rename): array {
                    if ($found['blockName'] !== $block || !is_array($found['attrs']['data'] ?? null)) {
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
                        return [$found, 0, array_map(static fn(string $p): string => "{$block}: {$p}", $result['problems'])];
                    }
                    $found['attrs']['data'] = $result['data'];

                    return [$found, $result['changes'], []];
                });
            }
        }

        $migration->note('Afterwards: delete the old field groups of these blocks in ACF (php gaffer doctor lists them).');
    }
}
