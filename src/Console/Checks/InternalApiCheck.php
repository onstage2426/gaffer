<?php

declare(strict_types=1);

namespace Gaffer\Console\Checks;

use Gaffer\Console\GafferReferences;
use Gaffer\Console\PublicApi;
use Gaffer\Console\Report;
use Gaffer\Console\ThemeFiles;

/**
 * Theme PHP that names Gaffer code outside the public API (API.md): an
 * @internal class or static member, or one this Gaffer doesn't have. Internal
 * code may change in any release without a changelog entry, so a site that uses
 * it can break on an update; the public API (API.md) only changes after a deprecation.
 */
final class InternalApiCheck implements Check
{
    private const string HINT = 'Internal code may change in any Gaffer release without notice. Use the public API (vendor/onstage2426/gaffer/API.md); if it has nothing for this, say so in your feedback to the Gaffer maintainers.';

    /** Internal members with a public replacement. */
    private const array REPLACEMENTS = [
        'Gaffer\Paths::storage' => 'Gaffer\Storage::private_dir() for a private folder (uploads/gaffer/).',
    ];

    #[\Override]
    public function run(Report $report): void
    {
        foreach (ThemeFiles::find(['php']) as $file) {
            foreach (self::findings((string) file_get_contents($file)) as $finding) {
                $report->warning('api', $finding['message'], $file, $finding['line'], $finding['hint']);
            }
        }
    }

    /**
     * One finding per internal or missing symbol a file names (its first line).
     *
     * @return list<array{message: string, line: int, hint: string}>
     */
    public static function findings(string $code): array
    {
        $found = [];
        foreach (GafferReferences::in($code) as ['class' => $class, 'member' => $member, 'line' => $line]) {
            $status = PublicApi::status($class);
            if ($status === 'public' && $member !== null) {
                $status = PublicApi::status($class, $member);
            } else {
                $member = null; // an internal or missing class: report the class once
            }
            $symbol = $class . ($member !== null ? "::{$member}" : '');
            if ($status === 'public' || isset($found[$symbol])) {
                continue;
            }
            $found[$symbol] = [
                'message' => $status === 'missing' ? "Uses {$symbol}, which this Gaffer doesn't have" : "Uses Gaffer's internal {$symbol}",
                'line' => $line,
                'hint' => $status === 'missing'
                    ? 'Removed or renamed: find it in vendor/onstage2426/gaffer/CHANGELOG.md.'
                    : (isset(self::REPLACEMENTS[$symbol]) ? 'Use ' . self::REPLACEMENTS[$symbol] . ' ' : '') . self::HINT,
            ];
        }

        return array_values($found);
    }
}
