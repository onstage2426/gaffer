<?php

declare(strict_types=1);

namespace Gaffer\Console\Checks;

use Gaffer\Console\Report;
use Gaffer\Console\Upgrade\Theme;
use Gaffer\Console\Upgrade\Upgrader;
use Gaffer\Paths;

/**
 * What `php gaffer upgrade` would still change, and what its CHANGELOG entries ask by hand: a
 * theme that updated Gaffer without running it hears about it here.
 *
 * @internal
 */
final class UpgradeCheck implements Check
{
    #[\Override]
    public function run(Report $report): void
    {
        foreach (Upgrader::plan(new Theme(rtrim(Paths::base(), '/'))) as $id => $item) {
            if ($item['changes'] !== []) {
                $report->warning('upgrade', $id . ': ' . $item['rule']->describe() . ' (' . count($item['changes']) . ' ' . (count($item['changes']) === 1 ? 'file' : 'files') . ')', null, null,
                    'php gaffer upgrade shows the diff, php gaffer upgrade --write applies it (with a backup).');
            }
            foreach ($item['by_hand'] as $todo) {
                $report->warning('upgrade', "{$id}: {$todo}");
            }
        }
    }
}
