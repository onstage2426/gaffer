<?php

declare(strict_types=1);

namespace Gaffer\Console\Commands;

use Gaffer\Console\Checks\AcfJsonCheck;
use Gaffer\Console\Checks\AssetsCheck;
use Gaffer\Console\Checks\AjaxCheck;
use Gaffer\Console\Checks\BlocksCheck;
use Gaffer\Console\Checks\ConfigCheck;
use Gaffer\Console\Checks\IncCheck;
use Gaffer\Console\Checks\MarkupCheck;
use Gaffer\Console\Checks\RenderCheck;
use Gaffer\Console\Checks\SourceCheck;
use Gaffer\Console\Checks\TemplatesCheck;
use Gaffer\Console\Checks\WordPressCheck;
use Gaffer\Console\Command;
use Gaffer\Console\Report;
use Gaffer\Console\WordPress;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('doctor', 'Check the theme against Gaffer conventions (--wp adds WordPress checks and a render smoke test)')]
final class Doctor extends Command
{
    #[\Override]
    protected function configure(): void
    {
        $this->addOption('wp', null, InputOption::VALUE_NONE, 'Also load WordPress: registered blocks, ACF sync, menus, render smoke test');
        $this->addOption('no-render', null, InputOption::VALUE_NONE, 'With --wp: skip the (slower) render smoke test');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $json = (bool) $input->getOption('json');
        $report = new Report();

        foreach ([new BlocksCheck(), new AcfJsonCheck(), new AjaxCheck(), new ConfigCheck(), new SourceCheck(), new TemplatesCheck(), new MarkupCheck(), new IncCheck(), new AssetsCheck()] as $check) {
            $check->run($report);
        }

        if ($input->getOption('wp')) {
            $url = $input->getOption('url');
            WordPress::load($url);
            new WordPressCheck()->run($report);

            if (!$input->getOption('no-render')) {
                $entry = (string) realpath((string) $_SERVER['SCRIPT_FILENAME']);
                new RenderCheck($entry, is_string($url) ? $url : null, static function (string $path) use ($output, $json): void {
                    if (!$json && $output->isVerbose()) {
                        $output->writeln("<comment>rendering</comment> {$path}");
                    }
                })->run($report);
            }
        }

        return $report->render($output, $json);
    }
}
