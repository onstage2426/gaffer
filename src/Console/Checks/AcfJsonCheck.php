<?php

declare(strict_types=1);

namespace Gaffer\Console\Checks;

use Gaffer\Console\Report;
use Gaffer\Paths;

/**
 * ACF local JSON parses, and block location rules point at blocks that exist.
 */
final class AcfJsonCheck implements Check
{
    #[\Override]
    public function run(Report $report): void
    {
        $blocks = array_flip(BlocksCheck::names());

        foreach (glob(Paths::storage() . '/acf-json/*.json') ?: [] as $file) {
            $json = (string) file_get_contents($file);
            if (!json_validate($json)) {
                $report->error('acf', 'Invalid JSON: ' . json_last_error_msg(), $file);
                continue;
            }

            $group = json_decode($json, true);
            foreach ($group['location'] ?? [] as $or) {
                foreach ($or as $rule) {
                    if (($rule['param'] ?? null) === 'block' && ($rule['operator'] ?? '==') === '==' && !isset($blocks[$rule['value']])) {
                        $report->error('acf', "Field group \"{$group['title']}\" targets block \"{$rule['value']}\", which doesn't exist", $file, null,
                            'Renamed block? Update the location rule in ACF (or the JSON) to the new name.');
                    }
                }
            }
        }
    }
}
