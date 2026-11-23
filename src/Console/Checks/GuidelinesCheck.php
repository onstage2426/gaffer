<?php

declare(strict_types=1);

namespace Gaffer\Console\Checks;

use Gaffer\BlockFields;
use Gaffer\Ai\Guidelines;
use Gaffer\Ai\Installer;
use Gaffer\Console\Report;
use Gaffer\Paths;

/**
 * The theme's own guidelines and skills (.ai/) only mention files, `Theme\`
 * classes and blocks that exist: hand-written notes go stale when a site
 * deletes or renames what they describe (e.g. a new site started from a starter
 * theme). Also lists theme files that replace one of Gaffer's guidelines or
 * skills: that's allowed, but invisible in the generated output. And the size of
 * the theme's guidelines: AGENTS.md loads on every turn of the agent.
 */
final class GuidelinesCheck implements Check
{
    /** Bytes of the theme's own .ai/guidelines/ before doctor warns (Gaffer's own are about 25 KB). */
    public const int BUDGET = 30_000;

    #[\Override]
    public function run(Report $report): void
    {
        $blocks = array_flip(BlockFields::names());
        $files = [...glob(Paths::base('.ai/guidelines') . '/*.md') ?: [], ...glob(Paths::base('.ai/skills') . '/*/SKILL.md') ?: []];

        foreach ($files as $file) {
            foreach (self::missing((string) file_get_contents($file), $blocks) as $mention) {
                $report->warning('ai', "Mentions {$mention}, which doesn't exist", $file, null,
                    'Update or remove it (renamed or deleted?), then php gaffer ai:update.');
            }
        }

        if (($over = self::over_budget(glob(Paths::base('.ai/guidelines') . '/*.md') ?: [])) !== null) {
            $report->warning('ai', 'The theme\'s guidelines are larger than an agent should read on every turn', Paths::base('.ai/guidelines'), null, $over);
        }

        foreach (Guidelines::gaffer_names() as $name) {
            $file = Paths::base(".ai/guidelines/{$name}.md");
            if (is_file($file)) {
                $report->info('ai', "Replaces Gaffer's {$name} guideline", $file, null,
                    "Gaffer's version is not in AGENTS.md; check it after updating Gaffer.");
            }
        }

        if (($stale = self::stale()) !== null) {
            $report->warning('ai', 'AGENTS.md is older than what it\'s made from', Paths::base('AGENTS.md'), null,
                "{$stale} changed after the last php gaffer ai:update: run it (agents read AGENTS.md and the installed skills, not .ai/).");
        }

        $gaffer = Installer::gaffer_skills();
        foreach ([...glob("{$gaffer}/*/SKILL.md") ?: [], ...glob("{$gaffer}/plugins/*/*/SKILL.md") ?: []] as $skill) {
            $file = Paths::base('.ai/skills/' . basename(dirname($skill)) . '/SKILL.md');
            if (is_file($file)) {
                $report->info('ai', "Replaces Gaffer's " . basename(dirname($skill)) . ' skill', $file, null,
                    "Gaffer's version is not installed; check it after updating Gaffer.");
            }
        }
    }

    /**
     * The hint when these guideline files are over BUDGET together, largest first; null otherwise.
     *
     * @param list<string> $files
     */
    public static function over_budget(array $files): ?string
    {
        $sizes = [];
        foreach ($files as $file) {
            $sizes[basename($file)] = (int) filesize($file);
        }
        if (array_sum($sizes) <= self::BUDGET) {
            return null;
        }
        arsort($sizes);
        $largest = implode(', ', array_map(static fn(string $f, int $b): string => "{$f} " . round($b / 1000) . ' KB', array_keys(array_slice($sizes, 0, 3)), array_slice($sizes, 0, 3)));

        return round(array_sum($sizes) / 1000) . ' KB, over ' . (self::BUDGET / 1000) . " KB (largest: {$largest}). Guidelines hold rules and decisions; "
            . 'move procedures, plans and reference detail into .ai/skills/{name}/SKILL.md (read when needed), drop what gaffer-reference already lists, then php gaffer ai:update.';
    }

    /**
     * The newest source of the generated AGENTS.md that changed after it (the theme's .ai/ and
     * config/ai.php, Gaffer's resources/ai/ after an update), relative to the theme; null when
     * it's current or there is no generated AGENTS.md.
     */
    public static function stale(): ?string
    {
        $agents = Paths::base('AGENTS.md');
        if (!is_file($agents) || !str_contains((string) file_get_contents($agents), '<!-- gaffer:start -->')) {
            return null;
        }
        $written = (int) filemtime($agents);
        $gaffer = dirname(Installer::gaffer_skills());
        $newest = null;
        foreach ([Paths::base('.ai'), Paths::base('config/ai.php'), $gaffer] as $source) {
            $files = is_dir($source)
                ? new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS))
                : (is_file($source) ? [new \SplFileInfo($source)] : []);
            foreach ($files as $file) {
                if ($file instanceof \SplFileInfo && $file->isFile() && $file->getMTime() > $written && ($newest === null || $file->getMTime() > $newest[0])) {
                    $newest = [$file->getMTime(), $file->getPathname()];
                }
            }
        }
        if ($newest === null) {
            return null;
        }

        return str_starts_with($newest[1], $gaffer . '/') ? "Gaffer's " . substr($newest[1], strlen(dirname($gaffer, 2)) + 1) : substr($newest[1], strlen(Paths::base()) + 1);
    }

    /**
     * `code` mentions of theme paths, Theme\ classes and acf/ blocks that don't exist.
     *
     * @param array<string, string> $blocks block name => directory
     * @return list<string>
     */
    public static function missing(string $markdown, array $blocks): array
    {
        preg_match_all('/`([^`\s]+)`/', $markdown, $m);
        $missing = [];

        foreach (array_unique($m[1]) as $mention) {
            $name = (string) preg_replace('/(::|->)\w+\(?\)?$|\(\)$/', '', $mention);
            if (preg_match('/[{}*…<>]/', $name)) {
                continue; // a pattern or placeholder, not a real name
            }
            $exists = match (true) {
                (bool) preg_match('#^acf/[a-z0-9-]+$#', $name) => isset($blocks[$name]),
                (bool) preg_match('/^Theme\\\\[A-Za-z0-9_\\\\]+$/', $name) => class_exists($name) || interface_exists($name) || trait_exists($name),
                // Outside the theme (an mu-plugin, a plugin's file): from the WordPress root, two folders up.
                str_starts_with($name, 'wp-content/') => file_exists(dirname(Paths::base(), 3) . '/' . $name),
                (bool) preg_match('#^[\w.-]+(/[\w.-]+)+\.(php|twig|js|css|json|svg|md)$#', $name) => array_any(
                    ['', 'views/', 'assets/', 'assets/js/'],
                    static fn(string $root): bool => file_exists(Paths::base($root . $name)),
                ),
                default => true,
            };
            if (!$exists) {
                $missing[] = $mention;
            }
        }

        return $missing;
    }
}
