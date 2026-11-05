<?php

declare(strict_types=1);

namespace Gaffer\Console\Commands;

use Gaffer\Console\Command;
use Gaffer\Console\Render\BrowserPage;
use Gaffer\Console\Render\Request;
use Gaffer\Console\Render\StagedTheme;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The computed styles of elements in a headless browser, for matching a design: on a page of
 * this site (rendered as doctor renders it), a design's URL (DESIGN_COOKIE in .env for one
 * behind a login) or a local HTML file. Per match (at most 10): its box, the properties that
 * differ from the browser's defaults for its tag (fonts, spacing, colours, borders, shadows),
 * ::before/::after when they have content, and with --children its children's boxes (alignment).
 */
#[AsCommand('render:styles', 'Computed styles of elements on a page, a design URL or an HTML file')]
final class RenderStyles extends Command
{
    protected bool $wordpress = true;

    #[\Override]
    protected function configure(): void
    {
        $this->addArgument('target', InputArgument::REQUIRED, 'A path of this site (/contact/), a URL (https://design.example/page.html) or an HTML file');
        $this->addArgument('selectors', InputArgument::REQUIRED | InputArgument::IS_ARRAY, 'CSS selectors, e.g. "footer h2" ".hero .button"');
        $this->addOption('width', null, InputOption::VALUE_REQUIRED, 'Viewport width', '1440');
        $this->addOption('children', null, InputOption::VALUE_NONE, "Also each match's children's boxes");
        $this->addOption('session', null, InputOption::VALUE_REQUIRED, 'A path as the visitor of this ajax:call session');
        $this->addOption('theme', null, InputOption::VALUE_REQUIRED, 'Render a full copy of the theme next to it, with its own assets');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Print the result as JSON (the gaffer-styles MCP tool)');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $json = (bool) $input->getOption('json');
        $fail = static function (string $error) use ($output, $json): int {
            $output->writeln($json ? (string) json_encode(['error' => $error], JSON_UNESCAPED_SLASHES) : "<error>{$error}</error>");
            return self::FAILURE;
        };
        $width = (int) $input->getOption('width');
        if ($width < 200 || $width > 3840) {
            return $fail('--width takes a width in pixels between 200 and 3840');
        }
        try {
            $page = self::page((string) $input->getArgument('target'), $input);
        } catch (\InvalidArgumentException $e) {
            return $fail($e->getMessage());
        }
        if (is_string($page)) {
            return $fail($page);
        }

        $result = BrowserPage::run('design.cjs', ['mode' => 'styles', 'page' => $page, 'selectors' => array_values(array_map('strval', (array) $input->getArgument('selectors'))), 'children' => (bool) $input->getOption('children'), 'width' => $width]);
        if (isset($result['error'])) {
            return $fail((string) $result['error']);
        }
        if ($json) {
            $output->writeln((string) json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return self::SUCCESS;
        }

        foreach ((array) ($result['elements'] ?? []) as $element) {
            $output->writeln("<info>{$element['selector']}</info>: " . (isset($element['error']) ? $element['error'] : "{$element['matches']} match(es)"));
            foreach ((array) ($element['items'] ?? []) as $i => $item) {
                $box = $item['box'];
                $output->writeln("  #" . ($i + 1) . " <{$item['tag']}> {$box['width']}×{$box['height']} at {$box['x']},{$box['y']}" . ($item['text'] !== '' ? " \"{$item['text']}\"" : ''));
                foreach ((array) $item['styles'] as $property => $value) {
                    $output->writeln("      {$property}: {$value}");
                }
                foreach (['before', 'after'] as $pseudo) {
                    if (isset($item[$pseudo])) {
                        $output->writeln("    ::{$pseudo} " . implode('; ', array_map(static fn(string $p, string $v): string => "{$p}: {$v}", array_keys($item[$pseudo]), $item[$pseudo])));
                    }
                }
                foreach ((array) ($item['children'] ?? []) as $child) {
                    $b = $child['box'];
                    $output->writeln("    child <{$child['tag']}> {$b['width']}×{$b['height']} at {$b['x']},{$b['y']}" . ($child['text'] !== '' ? " \"{$child['text']}\"" : ''));
                }
            }
        }

        return self::SUCCESS;
    }

    /**
     * A target as the browser script takes it (a path rendered with --session/--theme).
     *
     * @return array<string, mixed>|string
     * @throws \InvalidArgumentException for an unknown session or staged theme
     */
    public static function page(string $target, InputInterface $input, string $name = 'page'): array|string
    {
        $session = is_string($session = $input->getOption('session')) ? $session : null;
        $entry = is_string($theme = $input->getOption('theme')) ? StagedTheme::entry($theme) : null;

        return BrowserPage::target(
            $target,
            is_string($url = $input->getOption('url')) ? $url : null,
            $session !== null ? ["--session={$session}"] : [],
            $session !== null ? Request::session($session) : [],
            $entry,
            $name,
        );
    }
}
