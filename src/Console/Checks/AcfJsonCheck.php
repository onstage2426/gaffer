<?php

declare(strict_types=1);

namespace Gaffer\Console\Checks;

use Gaffer\Console\Report;
use Gaffer\FieldGroups;
use Gaffer\Paths;

/**
 * ACF local JSON parses, block location rules point at blocks that exist, and no
 * field group made in the ACF UI targets what defines its fields in code
 * (fields.php, fields/). Field groups are code: a UI group that could be is a
 * warning.
 */
final class AcfJsonCheck implements Check
{
    #[\Override]
    public function run(Report $report): void
    {
        $blocks = array_flip(BlocksCheck::names()); // name => directory
        $stays = []; // groups fields/ can't express: "title" (location)

        foreach (glob(Paths::storage() . '/acf-json/*.json') ?: [] as $file) {
            $json = (string) file_get_contents($file);
            if (!json_validate($json)) {
                $report->error('acf', 'Invalid JSON: ' . json_last_error_msg(), $file);
                continue;
            }

            $group = json_decode($json, true);
            if (!is_array($group) || !isset($group['fields'])) {
                continue; // a post type, taxonomy or options page made in the ACF UI
            }
            foreach (self::dead_conditions(is_array($group['fields']) ? $group['fields'] : []) as $field) {
                $report->warning('acf', "Field \"{$field}\" of \"{$group['title']}\" has conditional logic on a field that doesn't exist", $file, null,
                    'It never shows (a way of switching it off?): delete the field, or point the rule at a field of this group.');
            }
            $target = FieldGroups::target_of($group['location'] ?? null);
            if ($target !== null && isset(FieldGroups::targets()[$target])) {
                $report->error('acf', "Field group \"{$group['title']}\" targets {$target}, which defines its fields in fields/{$target}.php", $file, null,
                    "Move the stored values with php gaffer migrate:fields {$target}, then delete the group in ACF (that also deletes this JSON).");
            } elseif ($target !== null) {
                $report->warning('acf', "Field group \"{$group['title']}\" is made in the ACF UI: field groups are code", $file, null,
                    "Write its fields (same names) in fields/{$target}.php, run php gaffer migrate:fields {$target}, then delete the group in ACF.");
            } elseif (!self::only_blocks($group['location'] ?? null)) {
                $stays[] = "\"{$group['title']}\" (" . self::location($group['location'] ?? null) . ')';
            }
            foreach ($group['location'] ?? [] as $or) {
                foreach ($or as $rule) {
                    if (($rule['param'] ?? null) !== 'block' || ($rule['operator'] ?? '==') !== '==') {
                        continue;
                    }
                    if (!isset($blocks[$rule['value']])) {
                        $report->error('acf', "Field group \"{$group['title']}\" targets block \"{$rule['value']}\", which doesn't exist", $file, null,
                            'Renamed block? Update the location rule in ACF (or the JSON) to the new name.');
                    } elseif (is_file(Paths::blocks() . "/{$blocks[$rule['value']]}/fields.php")) {
                        $report->error('acf', "Field group \"{$group['title']}\" targets block \"{$rule['value']}\", which defines its fields in fields.php", $file, null,
                            'Move these fields to fields.php and delete the group in ACF (that also deletes this JSON).');
                    } else {
                        $report->warning('acf', "Field group \"{$group['title']}\" is made in the ACF UI: field groups are code", $file, null,
                            "Write its fields (same names) in blocks/{$blocks[$rule['value']]}/fields.php, run php gaffer migrate:fields {$rule['value']}, then delete the group in ACF.");
                    }
                }
            }
        }

        if ($stays !== []) {
            $report->info('acf', 'Field groups that fields/ can\'t express stay in the ACF UI', null, null,
                count($stays) . ': ' . implode(', ', $stays) . '. fields/ has one file per target (one post type, taxonomy or options page); keep these in the UI (their JSON in storage/acf-json/).');
        }
    }

    /** Whether every location rule targets a block (those are BlocksCheck's and the rules above). */
    private static function only_blocks(mixed $location): bool
    {
        $rules = is_array($location) ? array_merge(...array_values(array_filter($location, 'is_array'))) : [];

        return $rules !== [] && array_all($rules, static fn(mixed $rule): bool => is_array($rule) && ($rule['param'] ?? null) === 'block');
    }

    /** "taxonomy == product_cat or taxonomy == pa_merk" */
    private static function location(mixed $location): string
    {
        $or = [];
        foreach (is_array($location) ? $location : [] as $and) {
            $or[] = implode(' and ', array_map(
                static fn(mixed $rule): string => is_array($rule) ? ($rule['param'] ?? '?') . ' ' . ($rule['operator'] ?? '==') . ' ' . ($rule['value'] ?? '') : '?',
                is_array($and) ? $and : [],
            ));
        }

        return implode(' or ', $or) ?: 'no location';
    }

    /**
     * Names of fields whose conditional logic refers to a key no field of the group has.
     *
     * @param array<mixed> $fields
     * @return list<string>
     */
    public static function dead_conditions(array $fields): array
    {
        $keys = [];
        $collect = static function (array $fields) use (&$collect, &$keys): void {
            foreach ($fields as $field) {
                if (is_array($field)) {
                    $keys[(string) ($field['key'] ?? '')] = true;
                    $collect(is_array($field['sub_fields'] ?? null) ? $field['sub_fields'] : []);
                }
            }
        };
        $collect($fields);

        $dead = [];
        $walk = static function (array $fields) use (&$walk, &$dead, $keys): void {
            foreach ($fields as $field) {
                if (!is_array($field)) {
                    continue;
                }
                foreach (is_array($field['conditional_logic'] ?? null) ? $field['conditional_logic'] : [] as $rules) {
                    foreach (is_array($rules) ? $rules : [] as $rule) {
                        if (is_array($rule) && !isset($keys[(string) ($rule['field'] ?? '')])) {
                            $dead[] = (string) ($field['name'] ?? $field['label'] ?? '?');
                            continue 3;
                        }
                    }
                }
                $walk(is_array($field['sub_fields'] ?? null) ? $field['sub_fields'] : []);
            }
        };
        $walk($fields);

        return $dead;
    }
}
