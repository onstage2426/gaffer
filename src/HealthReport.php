<?php

declare(strict_types=1);

namespace Gaffer;

/**
 * A site's state as one flat map of dotted keys (`gaffer.version`, `health.gaffer-search.color`),
 * for whatever reads it from outside: `php gaffer health --json` today, a hub later. Format 1:
 * keys are only added, never renamed or removed, and a reader ignores the ones it doesn't know
 * (API.md, "Report"). Values are scalars or null.
 *
 * @internal
 */
final class HealthReport
{
    public const int FORMAT = 1;

    /** @return array<string, scalar|null> */
    public static function flat(): array
    {
        $report = [
            'format' => self::FORMAT,
            'gaffer.version' => Gaffer::version(),
            'php.version' => PHP_VERSION,
            'wordpress.version' => isset($GLOBALS['wp_version']) && is_string($GLOBALS['wp_version']) ? $GLOBALS['wp_version'] : null,
            'site.url' => \home_url('/'),
            'site.environment' => \wp_get_environment_type(),
            'site.multisite' => \is_multisite(),
        ];
        $states = AdminBar::states();
        $report['health.color'] = AdminBar::worst(array_values($states))['color'];
        foreach ($states as $name => $state) {
            $report["health.{$name}.color"] = $state['color'];
            $report["health.{$name}.reason"] = $state['reason'];
        }

        return $report;
    }
}
