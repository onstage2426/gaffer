<?php

declare(strict_types=1);

namespace Gaffer\Console\Upgrade;

use Gaffer\Console\Upgrade\Rules;
use RuntimeException;

/**
 * Every upgrade rule, oldest first, and writing their changes with a way back: before anything is
 * written, the files it touches are copied to a backup folder with a manifest, and restore() puts
 * them back (new files removed, deleted ones written again).
 *
 * @internal
 */
final class Upgrader
{
    /** @return list<Rule> oldest first */
    public static function rules(): array
    {
        return [
            new Rules\AcfJsonFolder(),
            new Rules\ViteRolldown(),
            new Rules\BlockBoilerplate(),
            new Rules\FormActionFolder(),
            new Rules\FileInputs(),
            new Rules\ProductType(),
            new Rules\EditorStylesheet(),
            new Rules\CartAdd(),
            new Rules\CartBadge(),
            new Rules\Notices(),
            new Rules\MenuChildren(),
            new Rules\ContentMarkup(),
            new Rules\FormActionFinal(),
            new Rules\SearchValues(),
            new Rules\VendorLink(),
            new Rules\AutoloadOnce(),
        ];
    }

    /**
     * The changes and by-hand items of every rule (or one) that finds something.
     *
     * @return array<string, array{rule: Rule, changes: list<Change>, by_hand: list<string>}>
     */
    public static function plan(Theme $theme, ?string $only = null): array
    {
        $plan = [];
        foreach (self::rules() as $rule) {
            if ($only !== null && $rule->id() !== $only) {
                continue;
            }
            $changes = $rule->changes($theme);
            $theme = $theme->with($changes); // the next rule reads what this one changed
            $by_hand = $rule->by_hand($theme);
            if ($changes !== [] || $by_hand !== []) {
                $plan[$rule->id()] = ['rule' => $rule, 'changes' => $changes, 'by_hand' => $by_hand];
            }
        }

        return $plan;
    }

    /**
     * Writes a plan for `php gaffer update` (format 1): one entry per file, as it was before the
     * first rule and as the last rule leaves it (base64, null: new or deleted), and the by-hand
     * items. `update` runs this with the staged new Gaffer and writes the files itself, right next
     * to the vendor/ swap: theme code and Gaffer change together, not seconds apart.
     *
     * @param array<string, array{rule: Rule, changes: list<Change>, by_hand: list<string>}> $plan
     */
    public static function write_plan(string $file, array $plan): void
    {
        $merged = [];
        $by_hand = [];
        foreach ($plan as $id => $item) {
            foreach ($item['changes'] as $change) {
                $merged[$change->path] = new Change($change->path, isset($merged[$change->path]) ? $merged[$change->path]->before : $change->before, $change->after);
            }
            foreach ($item['by_hand'] as $todo) {
                $by_hand[] = "{$id}: {$todo}";
            }
        }
        $changes = [];
        foreach ($merged as $change) {
            if ($change->before !== $change->after) {
                $changes[] = ['path' => $change->path, 'before' => $change->before === null ? null : base64_encode($change->before), 'after' => $change->after === null ? null : base64_encode($change->after)];
            }
        }
        $json = json_encode(['format' => 1, 'rules' => array_keys($plan), 'changes' => $changes, 'by_hand' => $by_hand], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false || file_put_contents($file, $json . "\n") === false) {
            throw new RuntimeException("Can't write the plan to {$file}.");
        }
    }

    /**
     * The changes of a plan written by write_plan() (possibly by another Gaffer version), or null
     * when the file isn't one or the theme changed since it was made (a file differs from its
     * "before"): then nothing may be written from it.
     *
     * @return list<Change>|null
     */
    public static function read_plan(Theme $theme, string $file): ?array
    {
        $plan = json_decode((string) @file_get_contents($file), true);
        if (!is_array($plan) || ($plan['format'] ?? null) !== 1 || !is_array($plan['changes'] ?? null)) {
            return null;
        }
        $changes = [];
        foreach ($plan['changes'] as $entry) {
            if (!is_array($entry) || !is_string($entry['path'] ?? null) || str_contains($entry['path'], '..') || str_starts_with($entry['path'], '/')) {
                return null;
            }
            $before = is_string($entry['before'] ?? null) ? base64_decode($entry['before'], true) : null;
            $after = is_string($entry['after'] ?? null) ? base64_decode($entry['after'], true) : null;
            if ($before === false || $after === false) {
                return null;
            }
            $now = is_file("{$theme->root}/{$entry['path']}") ? (string) file_get_contents("{$theme->root}/{$entry['path']}") : null;
            if ($now !== $before) {
                return null;
            }
            $changes[] = new Change($entry['path'], $before, $after);
        }

        return $changes;
    }

    /**
     * Writes the changes after backing up what they touch into $backup (a new folder).
     *
     * @param list<Change> $changes
     */
    public static function apply(Theme $theme, array $changes, string $backup): void
    {
        // One change per file: as it was before the first rule, as the last rule leaves it.
        $merged = [];
        foreach ($changes as $change) {
            $merged[$change->path] = new Change($change->path, isset($merged[$change->path]) ? $merged[$change->path]->before : $change->before, $change->after);
        }
        $changes = array_values(array_filter($merged, static fn(Change $c): bool => $c->before !== $c->after));
        if (!mkdir($backup, 0700, true) && !is_dir($backup)) {
            throw new RuntimeException("Can't create the backup folder {$backup}.");
        }
        $manifest = [];
        foreach ($changes as $i => $change) {
            $manifest[] = ['path' => $change->path, 'existed' => $change->before !== null, 'copy' => $change->before !== null ? "{$i}.orig" : null];
            if ($change->before !== null && file_put_contents("{$backup}/{$i}.orig", $change->before) === false) {
                throw new RuntimeException("Can't write the backup of {$change->path}.");
            }
        }
        if (file_put_contents("{$backup}/manifest.json", json_encode(['format' => 1, 'files' => $manifest], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) {
            throw new RuntimeException("Can't write {$backup}/manifest.json.");
        }
        foreach ($changes as $change) {
            $file = "{$theme->root}/{$change->path}";
            if ($change->after === null) {
                @unlink($file);
                self::remove_empty_parents(dirname($file), $theme->root);
                continue;
            }
            if (!is_dir(dirname($file))) {
                mkdir(dirname($file), 0755, true);
            }
            if (file_put_contents($file, $change->after) === false) {
                throw new RuntimeException("Can't write {$change->path}.");
            }
        }
    }

    /** Puts back what the backup in $backup holds. */
    public static function restore(Theme $theme, string $backup): void
    {
        $manifest = json_decode((string) @file_get_contents("{$backup}/manifest.json"), true);
        if (!is_array($manifest) || !is_array($manifest['files'] ?? null)) {
            throw new RuntimeException("{$backup} has no readable manifest.json.");
        }
        foreach ($manifest['files'] as $entry) {
            $file = "{$theme->root}/" . $entry['path'];
            if ($entry['existed']) {
                if (!is_dir(dirname($file))) {
                    mkdir(dirname($file), 0755, true);
                }
                file_put_contents($file, (string) file_get_contents("{$backup}/" . $entry['copy']));
            } else {
                @unlink($file);
                self::remove_empty_parents(dirname($file), $theme->root);
            }
        }
    }

    private static function remove_empty_parents(string $dir, string $root): void
    {
        while ($dir !== $root && str_starts_with($dir, $root . '/') && is_dir($dir) && (scandir($dir) ?: []) === ['.', '..']) {
            rmdir($dir);
            $dir = dirname($dir);
        }
    }
}
