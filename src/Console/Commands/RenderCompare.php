<?php

declare(strict_types=1);

namespace Gaffer\Console\Commands;

use Gaffer\Console\Command;
use Gaffer\Console\Render\BrowserPage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * A design next to the page that implements it: every visible element with text of its own in
 * the design (a URL, DESIGN_COOKIE in .env for one behind a login, or an HTML file) is paired
 * with the same text on this site's page (the most alike when it occurs more than once), and what differs is listed:
 * font size, weight, line height, letter spacing, font, text-transform, colour, and the gap to
 * the text above. Texts the site doesn't have are listed too. --within compares one part of
 * both pages (footer, .hero).
 */
#[AsCommand('render:compare', 'Compare the type and spacing of a design with a page of this site')]
final class RenderCompare extends Command
{
    protected bool $wordpress = true;

    #[\Override]
    protected function configure(): void
    {
        $this->addArgument('design', InputArgument::REQUIRED, 'The design: a URL (https://…) or an HTML file');
        $this->addArgument('path', InputArgument::REQUIRED, 'The path of this site that implements it, e.g. /');
        $this->addOption('width', null, InputOption::VALUE_REQUIRED, 'Viewport width', '1440');
        $this->addOption('within', null, InputOption::VALUE_REQUIRED, 'Compare only inside the first match of this selector on both pages, e.g. footer');
        $this->addOption('session', null, InputOption::VALUE_REQUIRED, 'The path as the visitor of this ajax:call session');
        $this->addOption('theme', null, InputOption::VALUE_REQUIRED, 'Render a full copy of the theme next to it, with its own assets');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Print the result as JSON (the gaffer-compare MCP tool)');
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
        $path = (string) $input->getArgument('path');
        if (!str_starts_with($path, '/') || is_file($path)) {
            return $fail("\"{$path}\" is not a path of this site (it starts with /)");
        }
        $design = BrowserPage::target((string) $input->getArgument('design'));
        if (is_string($design)) {
            return $fail($design);
        }
        try {
            $site = RenderStyles::page($path, $input);
        } catch (\InvalidArgumentException $e) {
            return $fail($e->getMessage());
        }
        if (is_string($site)) {
            return $fail($site);
        }

        $within = $input->getOption('within');
        $result = BrowserPage::run('design.cjs', ['mode' => 'compare', 'design' => $design, 'site' => $site, 'within' => is_string($within) ? $within : null, 'width' => $width]);
        if (isset($result['error'])) {
            return $fail((string) $result['error']);
        }
        if ($json) {
            $output->writeln((string) json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return self::SUCCESS;
        }

        $fonts = (array) ($result['fonts'] ?? []);
        $output->writeln("At {$width}px: {$result['matched']} of {$result['of']} design texts found on the site; " . count((array) $result['pairs']) . ' differ.');
        $output->writeln('Fonts: design ' . implode(', ', (array) ($fonts['design'] ?? [])) . ' | site ' . implode(', ', (array) ($fonts['site'] ?? [])));
        foreach ((array) $result['pairs'] as $pair) {
            $tags = $pair['design'] === $pair['site'] ? "<{$pair['design']}>" : "<{$pair['design']}> → <{$pair['site']}>";
            $output->writeln("  \"{$pair['text']}\" {$tags}: " . implode('; ', (array) $pair['differences']));
        }
        if ($result['missing'] !== []) {
            $output->writeln('<comment>Not on the site:</comment>');
            foreach (array_slice((array) $result['missing'], 0, 30) as $text) {
                $output->writeln("  \"{$text}\"");
            }
            if (count((array) $result['missing']) > 30) {
                $output->writeln('  … and ' . (count((array) $result['missing']) - 30) . ' more (--json lists all)');
            }
        }

        return self::SUCCESS;
    }
}
