<?php

declare(strict_types=1);

namespace Gaffer\Console\Commands;

use Gaffer\Console\Command;
use Gaffer\Console\Render\HtmlDiff;
use Gaffer\Console\Render\Renderer;
use Gaffer\Console\Render\Snapshot;
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
        $this->addOption('ignore', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Remove matches of this regular expression first (repeatable), e.g. \'/data-delay="\d+"/\'');
        $this->addOption('lines', null, InputOption::VALUE_REQUIRED, 'Diff lines shown per page', '40');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $select = $input->getOption('select');
        $ignore = array_values(array_map('strval', (array) $input->getOption('ignore')));
        $max = max(1, (int) $input->getOption('lines'));

        try {
            $snapshot = Snapshot::load((string) $input->getArgument('name'));
            HtmlDiff::lines('', $ignore); // invalid expressions fail before rendering
        } catch (RuntimeException | InvalidArgumentException $e) {
            $output->writeln("<error>{$e->getMessage()}</error>");
            return self::FAILURE;
        }
        $output->writeln("Comparing with {$snapshot->name} ({$snapshot->source}, {$snapshot->created}): " . count($snapshot->pages) . ' pages...');

        $progress = static function (string $path) use ($output): void {
            if ($output->isVerbose()) {
                $output->writeln("<comment>rendering</comment> {$path}");
            }
        };
        $now = new Renderer(is_string($url = $input->getOption('url')) ? $url : null, $progress)->render(array_keys($snapshot->pages), true);

        $same = 0;
        $differ = 0;
        foreach ($snapshot->pages as $path => $before) {
            $after = Snapshot::page($now[$path]);
            try {
                $changes = self::compare($before, $after, is_string($select) ? $select : null, $ignore, $max);
            } catch (InvalidArgumentException $e) {
                $output->writeln("<error>{$e->getMessage()}</error>");
                return self::FAILURE;
            }
            if ($changes === []) {
                $same++;
                continue;
            }
            $differ++;
            $output->writeln("<info>{$path}</info>");
            foreach ($changes as $line) {
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

        $output->writeln("{$same} the same, {$differ} different.");

        return $differ === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param array{http: int, redirect: ?string, error: ?string, html: string} $before
     * @param array{http: int, redirect: ?string, error: ?string, html: string} $after
     * @param list<string> $ignore
     * @return list<string>
     */
    private static function compare(array $before, array $after, ?string $select, array $ignore, int $max): array
    {
        $changes = [];
        if ($after['error'] !== null) {
            $changes[] = "! now: {$after['error']}";
        }
        if ($before['http'] !== $after['http'] || $before['redirect'] !== $after['redirect']) {
            $changes[] = "- HTTP {$before['http']}" . ($before['redirect'] !== null ? " → {$before['redirect']}" : '');
            $changes[] = "+ HTTP {$after['http']}" . ($after['redirect'] !== null ? " → {$after['redirect']}" : '');
        }

        $html = static fn(string $page): string => $select !== null ? implode("\n", HtmlDiff::select_all($page, $select)) : $page;
        array_push($changes, ...HtmlDiff::diff(HtmlDiff::lines($html($before['html']), $ignore), HtmlDiff::lines($html($after['html']), $ignore), 2, $max));

        return $changes;
    }
}
