<?php

declare(strict_types=1);

namespace Gaffer\Console\Commands;

use Gaffer\Config;
use Gaffer\Console\Command;
use Gaffer\Console\Render\HtmlDiff;
use Gaffer\Console\Render\Renderer;
use Gaffer\Console\Render\Snapshot;
use Gaffer\Console\Render\StagedTheme;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Renders a snapshot's pages with the code as it is now and shows what changed,
 * after normalizing (entities decoded, whitespace collapsed, one tag per line).
 */
#[AsCommand('render:diff', 'Render the pages of a render:snapshot again and show what changed')]
final class RenderDiff extends Command
{
    protected bool $wordpress = true;

    #[\Override]
    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::OPTIONAL, 'Snapshot name', 'snapshot');
        $this->addOption('select', null, InputOption::VALUE_REQUIRED, 'Compare only the elements matching this CSS selector, e.g. main or ".faq"');
        $this->addOption('ignore', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Remove matches of this regular expression first (repeatable), e.g. \'/data-delay="\d+"/\'. It matches the page as rendered (before --select and normalizing): mind single and double quotes');
        $this->addOption('theme', null, InputOption::VALUE_REQUIRED, 'Render with a full copy of this theme next to it (e.g. blueprint-stage: cp -r, vendor/ included): staged code against live, before switching');
        $this->addOption('lines', null, InputOption::VALUE_REQUIRED, 'Lines shown per change', '40');
        $this->addOption('hide-reorders', null, InputOption::VALUE_NONE, 'Hide changes that only reorder lines (random suggestions, equal counts); a page with nothing else counts as the same');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON: the counts and each change with its pages and lines (all of them)');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $select = $input->getOption('select');
        // The theme's own (console.render_ignore: its plugins' per-request tokens), then this run's.
        $ignore = [
            ...array_values(array_map('strval', (array) (Config::get('console.render_ignore') ?? []))),
            ...array_values(array_map('strval', (array) $input->getOption('ignore'))),
        ];
        $json = (bool) $input->getOption('json');
        $max = max(1, (int) $input->getOption('lines'));

        try {
            $entry = is_string($theme = $input->getOption('theme')) ? StagedTheme::entry($theme) : null;
            $snapshot = Snapshot::load((string) $input->getArgument('name'));
            HtmlDiff::without('', $ignore); // invalid expressions fail before rendering
        } catch (RuntimeException | InvalidArgumentException $e) {
            $output->writeln("<error>{$e->getMessage()}</error>");
            return self::FAILURE;
        }
        if (!$json) {
            $output->writeln("Comparing with {$snapshot->name} ({$snapshot->source}, {$snapshot->created}): " . count($snapshot->pages) . ' pages' . ($entry !== null ? ', rendered with ' . basename(dirname($entry)) : '') . '...');
        }

        $progress = static function (string $path) use ($output, $json): void {
            if ($output->isVerbose() && !$json) {
                $output->writeln("<comment>rendering</comment> {$path}");
            }
        };
        $now = new Renderer(is_string($url = $input->getOption('url')) ? $url : null, $progress, $snapshot->visitor, $entry)->render(array_keys($snapshot->pages), true);

        // The same change on many pages (a header, a footer) is shown once, with its pages.
        $hide = (bool) $input->getOption('hide-reorders');
        $same = 0;
        $differ = 0;
        $hidden = 0;
        $groups = [];
        foreach ($snapshot->pages as $path => $before) {
            $after = Snapshot::page($now[$path]);
            try {
                $changes = self::compare($before, $after, is_string($select) ? $select : null, $ignore);
            } catch (InvalidArgumentException $e) {
                $output->writeln("<error>{$e->getMessage()}</error>");
                return self::FAILURE;
            }
            $shown = 0;
            foreach (HtmlDiff::hunks($changes) as $hunk) {
                $reorder = HtmlDiff::reorder_only($hunk);
                if ($reorder && $hide) {
                    $hidden++;
                    continue;
                }
                $key = implode("\n", array_filter($hunk, static fn(string $line): bool => !str_starts_with($line, '  ')));
                $groups[$key] ??= ['hunk' => $hunk, 'paths' => [], 'reorder' => $reorder];
                $groups[$key]['paths'][$path] = true;
                $shown++;
            }
            $shown > 0 ? $differ++ : $same++;
        }

        uasort($groups, static fn(array $a, array $b): int => count($b['paths']) <=> count($a['paths']));
        if ($json) {
            $output->writeln((string) json_encode([
                'snapshot' => $snapshot->name,
                'same' => $same,
                'different' => $differ,
                'hidden_reorders' => $hidden,
                'changes' => array_values(array_map(static fn(array $group): array => ['pages' => array_keys($group['paths']), 'reorder_only' => $group['reorder'], 'lines' => $group['hunk']], $groups)),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
            return $differ === 0 ? self::SUCCESS : self::FAILURE;
        }
        foreach ($groups as ['hunk' => $hunk, 'paths' => $paths, 'reorder' => $reorder]) {
            $paths = array_keys($paths);
            $shown = array_slice($paths, 0, 3);
            $more = count($paths) - count($shown);
            $output->writeln('<info>' . implode(', ', $shown) . ($more > 0 ? " and {$more} more" : '') . '</info>' . (count($paths) > 1 ? ' (' . count($paths) . ' pages)' : '')
                . ($reorder ? ' <comment>(reorder only)</comment>' : ''));
            $hunk = HtmlDiff::shorten($hunk);
            if (count($hunk) > $max) {
                $hunk = [...array_slice($hunk, 0, $max), '… ' . (count($hunk) - $max) . ' more lines'];
            }
            foreach ($hunk as $line) {
                $style = match ($line[0] ?? '') {
                    '-' => 'fg=red',
                    '+' => 'fg=green',
                    '!' => 'fg=yellow',
                    default => null,
                };
                $line = OutputFormatter::escape($line);
                $output->writeln($style !== null ? "<{$style}>{$line}</>" : "  {$line}");
            }
        }

        $reorders = count(array_filter($groups, static fn(array $group): bool => $group['reorder']));
        $output->writeln("{$same} the same, {$differ} different" . ($groups !== [] ? ', ' . count($groups) . ' distinct changes' : '')
            . ($reorders > 0 ? " ({$reorders} only reorder lines: --hide-reorders)" : '') . ($hidden > 0 ? ", {$hidden} reorders hidden" : '') . '.');

        return $differ === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param array{http: int, redirect: ?string, error: ?string, html: string} $before
     * @param array{http: int, redirect: ?string, error: ?string, html: string} $after
     * @param list<string> $ignore
     * @return list<string>
     */
    private static function compare(array $before, array $after, ?string $select, array $ignore): array
    {
        $changes = [];
        if ($after['error'] !== null) {
            $changes[] = "! now: {$after['error']}";
            $changes[] = '…';
        }
        if ($before['http'] !== $after['http'] || $before['redirect'] !== $after['redirect']) {
            $changes[] = "- HTTP {$before['http']}" . ($before['redirect'] !== null ? " → {$before['redirect']}" : '');
            $changes[] = "+ HTTP {$after['http']}" . ($after['redirect'] !== null ? " → {$after['redirect']}" : '');
            $changes[] = '…';
        }

        $lines = static function (string $page) use ($select, $ignore): array {
            $page = HtmlDiff::without($page, $ignore);
            return HtmlDiff::lines($select !== null ? implode("\n", HtmlDiff::select_all($page, $select)) : $page);
        };
        array_push($changes, ...HtmlDiff::diff($lines($before['html']), $lines($after['html']), 2, PHP_INT_MAX));

        return $changes;
    }
}
