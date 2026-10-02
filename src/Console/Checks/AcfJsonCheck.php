<?php

declare(strict_types=1);

namespace Gaffer\Console\Checks;

use Gaffer\Console\Report;
use Gaffer\Paths;

/**
 * ACF local JSON parses, and block location rules point at blocks that exist and
 * don't define their fields in code (fields.php).
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
                    }
                }
            }
        }
    }
}
