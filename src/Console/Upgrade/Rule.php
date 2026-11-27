<?php

declare(strict_types=1);

namespace Gaffer\Console\Upgrade;

/**
 * A mechanical change a Gaffer release asks of themes, applied by `php gaffer upgrade`. A rule
 * only computes (changes() writes nothing) and is idempotent: on a theme that has the change
 * already it finds nothing, so every rule runs on every upgrade.
 *
 * @internal
 */
abstract class Rule
{
    /** The release date and a slug: "2026-11-23-file-inputs" (the CHANGELOG entry's date). */
    abstract public function id(): string;

    /** One line: what it changes. */
    abstract public function describe(): string;

    /** @return list<Change> */
    abstract public function changes(Theme $theme): array;

    /**
     * What the same CHANGELOG entry still asks by hand on this theme, after the rule's changes.
     *
     * @return list<string>
     */
    public function by_hand(Theme $theme): array
    {
        return [];
    }
}
