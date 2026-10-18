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
            $target = FieldGroups::target_of($group['location'] ?? null);
            if ($target !== null && isset(FieldGroups::targets()[$target])) {
                $report->error('acf', "Field group \"{$group['title']}\" targets {$target}, which defines its fields in fields/{$target}.php", $file, null,
                    "Move the stored values with php gaffer migrate:fields {$target}, then delete the group in ACF (that also deletes this JSON).");
            } elseif ($target !== null) {
                $report->warning('acf', "Field group \"{$group['title']}\" is made in the ACF UI: field groups are code", $file, null,
                    "Write its fields (same names) in fields/{$target}.php, run php gaffer migrate:fields {$target}, then delete the group in ACF.");
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
    }
}
