<?php

declare(strict_types=1);

namespace Gaffer\Console\Commands;

use Gaffer\BlockFields;
use Gaffer\Console\Migrate\ContentStore;
use Gaffer\Console\Migrate\FieldReferences;
use Gaffer\Console\Migrate\FieldTarget;
use Gaffer\Console\Migrate\Reference;
use Gaffer\FieldGroups;
use Gaffer\Console\Checks\BlocksCheck;
use Gaffer\Console\Migrate\BlockData;
use Gaffer\Console\Migrate\Migration;
use Gaffer\Paths;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
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
        $this->addOption('delete-groups', null, InputOption::VALUE_NONE, 'With --run: delete the moved ACF UI field groups once everything is written and checked');
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
            $this->plan_groups($migration, is_string($only) ? $only : null, (bool) $input->getOption('delete-groups'));
        }

        if (!$input->getOption('delete-groups')) {
            $migration->note('Afterwards: delete the old field groups in ACF (php gaffer doctor lists them), or run again with --delete-groups.');
            return;
        }
        $groups = [];
        foreach (is_string($only) ? [$only] : array_keys(FieldGroups::targets()) as $target) {
            if (!str_starts_with($target, 'acf/')) {
                array_push($groups, ...FieldTarget::ui_groups($target));
            }
        }
        $migration->note(count($groups) . ' ACF UI field group(s) are deleted after a successful --run: ' . implode(', ', array_map(static fn(array $g): string => "\"{$g['title']}\"", $groups))
            . '. migrate:rollback doesn\'t bring them back (their JSON is in git: restore it and sync in ACF).');
        $migration->after_success(static function () use ($groups): array {
            $done = [];
            foreach ($groups as $group) {
                $post = acf_get_field_group_post((string) $group['key']);
                $json = Paths::storage() . "/acf-json/{$group['key']}.json";
                if ($post !== false) {
                    // ACF deletes the group's fields and its local JSON with it.
                    $done[] = (acf_delete_field_group($post->ID) ? 'Deleted' : 'Could not delete') . " the field group \"{$group['title']}\"";
                } elseif (is_file($json)) {
                    $done[] = (unlink($json) ? 'Deleted' : 'Could not delete') . " the field group \"{$group['title']}\" (only in JSON: storage/acf-json/{$group['key']}.json)";
                }
            }
            return $done;
        });
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
     * Rewrites the references a fields/ target's UI groups left (postmeta, termmeta, options)
     * to the derived keys of the file's fields with the same name path. Only the target's
     * own (FieldReferences): references elsewhere or under another name are reported and left
     * alone. A field the file doesn't have, or has with another type, stops the run. With
     * --run, what ACF reads for every changed field is compared before and after.
     */
    private function plan_groups(Migration $migration, ?string $only, bool $delete_groups = false): void
    {
        $targets = FieldGroups::targets();
        if ($only !== null && !isset($targets[$only])) {
            $migration->problem("{$only} has no file in fields/ (expected fields/{$only}.php)");
            return;
        }
        $migration->summarize();

        $checked = [];
        foreach ($only !== null ? [$only] : array_keys($targets) as $target) {
            try {
                $sorter = FieldTarget::sorter($target);
            } catch (\RuntimeException $e) {
                $migration->problem($e->getMessage());
                continue;
            }
            [$param, $value] = explode('/', $target, 2);
            $old = FieldTarget::old_fields($target);
            $new = FieldReferences::paths(FieldGroups::group($target)['fields']);

            if ($old !== []) {
                $migration->progress("{$target}: finding the references to " . count($old) . (count($old) === 1 ? ' field' : ' fields') . ' of its UI groups…');
                $sorted = $sorter->classify(ContentStore::references(array_map('strval', array_keys($old))));
                $migration->progress("{$target}: " . count($sorted['rewrite']) . ' to rewrite; planning…');
                foreach ($sorted['rewrite'] as $rewrite) {
                    $migration->change($rewrite['reference']->location, $rewrite['reference']->key, $rewrite['to'], "\"{$rewrite['path']}\" {$rewrite['reference']->key} → {$rewrite['to']}");
                    $owner = match (true) {
                        is_int($rewrite['post_id']) => "post {$rewrite['post_id']}",
                        str_starts_with($rewrite['post_id'], 'term_') => 'term ' . substr($rewrite['post_id'], 5),
                        default => $rewrite['post_id'],
                    };
                    $checked["{$owner} \"{$rewrite['field']}\""] = [$rewrite['post_id'], $rewrite['field']];
                }
                foreach ($sorted['problems'] as $problem => $references) {
                    $migration->problem("{$problem} (" . self::listed($references) . ')');
                }
                if ($sorted['outside'] !== []) {
                    $migration->note(self::references($sorted['outside']) . " to these fields stored outside {$target}, left alone: " . self::listed($sorted['outside']));
                }
                if ($sorted['other_names'] !== [] && $delete_groups) {
                    // remove-leftovers knows them by the UI groups' keys: deleting the groups first hides them for good.
                    $migration->problem("{$target} has references under another name than their field's: run php gaffer migrate:remove-leftovers {$target} before --delete-groups (it recognizes them by the old groups' keys)");
                }
                if ($sorted['other_names'] !== []) {
                    $migration->note(self::references($sorted['other_names']) . " in {$target} stored under another name than their field's (an old name?), left alone: "
                        . self::listed(array_map(static fn(array $found): string => "{$found[0]->label()} → key of \"{$found[1]}\"", $sorted['other_names']))
                        . ". php gaffer migrate:remove-leftovers {$target} deletes them with their values (after this run).");
                }
            }

            $known = array_map('strval', [...array_keys($old), ...array_column($new, 'key')]);
            // Broken: no ACF field has the key (a key of another group that stays in the UI isn't).
            // The stored name says which field it is: repaired to its derived key, outside the
            // value check (ACF read it unformatted before, through no field).
            $broken = array_values(array_filter(
                $sorter->broken(ContentStore::target_references($param, $value, $known, $param === 'options_page' ? FieldTarget::option_prefixes($value) : [])),
                static fn(array $found): bool => !acf_get_field($found[0]->key),
            ));
            foreach ($broken as [$reference, $field]) {
                $migration->change($reference->location, $reference->key, $field['key'], "repair \"{$field['path']}\": a key no field has → {$field['key']}");
            }
            if ($broken !== []) {
                $migration->note(self::references($broken) . " in {$target} with a key no field has (an import?), repaired to the field their name says: "
                    . self::listed(array_map(static fn(array $found): string => "{$found[0]->label()} → {$found[0]->key}", $broken)));
            }
        }

        if ($checked !== []) {
            $migration->check_values(static function () use ($checked): array {
                // ACF keeps loaded values for the request; read them from the database again.
                if (function_exists('acf_get_store')) {
                    acf_get_store('values')->reset();
                }
                $hashes = [];
                foreach ($checked as $id => [$post_id, $field]) {
                    try {
                        $hashes[$id] = md5(serialize(get_field($field, $post_id)));
                    } catch (Throwable $e) {
                        $hashes[$id] = 'unreadable: ' . $e->getMessage();
                    }
                }
                return $hashes;
            });
        }
    }

    /** @param list<mixed> $items "1 reference", "3 references" */
    private static function references(array $items): string
    {
        return count($items) . (count($items) === 1 ? ' reference' : ' references');
    }

    /**
     * A few of these, with how many there are: "product 1 _x, product 2 _x and 4 more".
     *
     * @param list<Reference|string> $items
     */
    private static function listed(array $items): string
    {
        $labels = array_map(static fn(Reference|string $item): string => $item instanceof Reference ? $item->label() : $item, $items);

        return implode(', ', array_slice($labels, 0, 3)) . (count($labels) > 3 ? ' and ' . (count($labels) - 3) . ' more' : '');
    }
}
