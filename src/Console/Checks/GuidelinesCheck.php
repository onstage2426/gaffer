<?php

declare(strict_types=1);

namespace Gaffer\Console\Checks;

use Gaffer\Ai\Guidelines;
use Gaffer\Ai\Installer;
use Gaffer\Console\Report;
use Gaffer\Paths;

/**
 * The theme's own guidelines and skills (.ai/) only mention files, `Theme\`
 * classes and blocks that exist: hand-written notes go stale when a site
 * deletes or renames what they describe (e.g. a new site started from a starter
 * theme). Also lists theme files that replace one of Gaffer's guidelines or
 * skills: that's allowed, but invisible in the generated output.
 */
final class GuidelinesCheck implements Check
{
    #[\Override]
    public function run(Report $report): void
    {
        $blocks = array_flip(BlocksCheck::names());
        $files = [...glob(Paths::base('.ai/guidelines') . '/*.md') ?: [], ...glob(Paths::base('.ai/skills') . '/*/SKILL.md') ?: []];

        foreach ($files as $file) {
            foreach (self::missing((string) file_get_contents($file), $blocks) as $mention) {
                $report->warning('ai', "Mentions {$mention}, which doesn't exist", $file, null,
                    'Update or remove it (renamed or deleted?), then php gaffer ai:update.');
            }
        }

        foreach (Guidelines::gaffer_names() as $name) {
            $file = Paths::base(".ai/guidelines/{$name}.md");
            if (is_file($file)) {
                $report->info('ai', "Replaces Gaffer's {$name} guideline", $file, null,
                    "Gaffer's version is not in AGENTS.md; check it after updating Gaffer.");
            }
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
