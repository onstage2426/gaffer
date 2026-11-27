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
