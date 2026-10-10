<?php

declare(strict_types=1);

namespace Gaffer\Console\Commands;

use Gaffer\Console\Command;
use Gaffer\Console\Render\Renderer;
use Gaffer\Console\Render\Snapshot;
use Gaffer\Paths;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Saves how pages look now, to compare with render:diff after changing code
 * (migrating a block, rewriting a template). The pages: doctor's sample plus every
 * published post using an ACF block (or the given blocks). Rendered here like
 * doctor does, or with --from fetched from a site over HTTP (the live site on the
 * old theme, while this copy runs the new one).
 */
#[AsCommand('render:snapshot', 'Save the rendered HTML of pages to compare with render:diff after a code change')]
final class RenderSnapshot extends Command
{
    protected bool $wordpress = true;

    #[\Override]
    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::OPTIONAL, 'Snapshot name', 'snapshot');
        $this->addOption('block', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Only doctor\'s sample and pages using this block (repeatable), e.g. acf/content-faq');
        $this->addOption('from', null, InputOption::VALUE_REQUIRED, 'Fetch the pages over HTTP from this site instead of rendering them, e.g. https://www.example.com');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = (string) $input->getArgument('name');
        $from = $input->getOption('from');
        $blocks = array_values(array_map('strval', (array) $input->getOption('block')));
        $file = Snapshot::file($name);

        $paths = array_values(array_unique([...Renderer::sample(), ...Renderer::using_blocks($blocks)]));
        $output->writeln('Saving ' . count($paths) . ' pages ' . (is_string($from) ? "from {$from}" : 'rendered with this code') . '...');
        $progress = static function (string $path) use ($output): void {
            if ($output->isVerbose()) {
                $output->writeln("<comment>rendering</comment> {$path}");
            }
        };

        $pages = is_string($from)
            ? array_map(self::fetch(...), array_combine($paths, array_map(static fn(string $p): string => rtrim($from, '/') . $p, $paths)))
            : array_map(Snapshot::page(...), new Renderer(is_string($url = $input->getOption('url')) ? $url : null, $progress)->render($paths, true));

        new Snapshot($name, is_string($from) ? $from : 'rendered', gmdate('c'), $pages)->save();

        foreach ($pages as $path => $page) {
            if ($page['error'] !== null) {
                $output->writeln("  <comment>{$path}: {$page['error']}</comment>");
            }
        }
        $output->writeln('Saved ' . substr($file, strlen(Paths::base()) + 1) . ". After the change: php gaffer render:diff {$name}");

        return self::SUCCESS;
    }

    /** @return array{http: int, redirect: ?string, error: ?string, html: string} */
    private static function fetch(string $url): array
    {
        $response = \wp_remote_get($url, ['timeout' => 30, 'redirection' => 0]);
        if (\is_wp_error($response)) {
            return ['http' => 0, 'redirect' => null, 'error' => $response->get_error_message(), 'html' => ''];
        }
        $http = (int) \wp_remote_retrieve_response_code($response);
        $location = \wp_remote_retrieve_header($response, 'location');

        return [
            'http' => $http,
            'redirect' => $http >= 300 && $http < 400 && is_string($location) && $location !== '' ? $location : null,
            'error' => null,
            'html' => \wp_remote_retrieve_body($response),
        ];
    }
}
