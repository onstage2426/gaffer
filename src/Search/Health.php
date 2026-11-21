<?php

declare(strict_types=1);

namespace Gaffer\Search;

/**
 * gaffer-search in one line, for the admin bar: a color and the first thing wrong (or how many
 * indexes are fine). doctor --wp says everything; this says whether to look.
 *
 * @internal
 */
final class Health
{
    /** Minutes an unapplied content change may wait before it counts (as doctor --wp). */
    public const int PENDING_MINUTES = 5;

    /** Days without the engine's daily health check (`drift_checked`) before it counts: WP-cron has stopped. */
    public const int CHECK_DAYS = 2;

    /**
     * Null when the theme defines no index (no search on this site).
     *
     * @return array{color: 'red'|'orange'|'green', reason: string}|null
     */
    public static function summary(): ?array
    {
        $indexes = Indexes::all();
        if ($indexes === []) {
            return null;
        }
        $engine = Engine::find();
        if ($engine === null) {
            return ['color' => 'red', 'reason' => 'not active, while config/search.php defines indexes'];
        }
        $versions = array_map(static fn(array $definition): string => (string) ($definition['version'] ?? ''), Indexes::definitions($indexes));

        return self::of($engine->status(), $versions, time());
    }

    /**
     * The worst state of these indexes, with the reason for it.
     *
     * @param array<string, array{built: bool, building: bool, error: ?string, version: ?string, pending: ?int, pending_since: ?int, drift?: ?int, drift_checked?: ?int, served?: ?list<string>}> $status
     * @param array<string, string> $versions the definitions' version per index
     * @return array{color: 'red'|'orange'|'green', reason: string}
     */
    public static function of(array $status, array $versions, int $now): array
    {
        $red = [];
        $orange = [];
        foreach ($versions as $index => $version) {
            $state = $status[$index] ?? null;
            if ($state === null) {
                $red[] = "{$index}: not defined in the engine (its definition has problems)";
                continue;
            }
            if (($state['served'] ?? []) !== []) {
                $red[] = 'its files can be downloaded: ' . implode(', ', (array) $state['served']);
            }
            if ($state['error'] !== null) {
                $red[] = "{$index}: the last build failed";
            }
            if (!$state['built']) {
                if ($state['building']) {
                    $orange[] = "{$index}: building";
                } else {
                    $red[] = "{$index}: not built";
                }
                continue;
            }
            if ($state['version'] !== $version) {
                $orange[] = "{$index}: " . ($state['building'] ? 'rebuilding' : 'waits for a rebuild') . ' (built from an older definition)';
            }
            if ($state['pending_since'] !== null && $state['pending_since'] < $now - self::PENDING_MINUTES * 60) {
                $orange[] = "{$index}: changes wait since " . intdiv($now - $state['pending_since'], 60) . ' min (WP-cron?)';
            }
            $checked = $state['drift_checked'] ?? null;
            if (is_int($checked) && $checked < $now - self::CHECK_DAYS * 86400) {
                $orange[] = "{$index}: the daily check last ran " . intdiv($now - $checked, 86400) . ' days ago (WP-cron?)';
            }
            if (($state['drift'] ?? 0) > 0) {
                $orange[] = "{$index}: {$state['drift']} document(s) changed without WordPress's hooks";
            }
        }

        return match (true) {
            $red !== [] => ['color' => 'red', 'reason' => $red[0]],
            $orange !== [] => ['color' => 'orange', 'reason' => $orange[0]],
            default => ['color' => 'green', 'reason' => count($versions) . ' ' . (count($versions) === 1 ? 'index' : 'indexes') . ' built and current'],
        };
    }
}
