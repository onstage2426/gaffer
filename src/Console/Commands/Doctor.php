<?php

declare(strict_types=1);

namespace Gaffer\Console\Commands;

use Gaffer\Console\Checks\AcfJsonCheck;
use Gaffer\Console\Checks\AppCheck;
use Gaffer\Console\Checks\AssetsCheck;
use Gaffer\Console\Checks\AjaxCheck;
use Gaffer\Console\Checks\ContractsCheck;
use Gaffer\Console\Checks\FormsCheck;
use Gaffer\Console\Checks\HtmxCheck;
use Gaffer\Console\Checks\BlocksCheck;
use Gaffer\Console\Checks\ConfigCheck;
use Gaffer\Console\Checks\DeprecationsCheck;
use Gaffer\Console\Checks\InternalApiCheck;
use Gaffer\Console\Checks\FieldGroupsCheck;
use Gaffer\Console\Checks\GuidelinesCheck;
use Gaffer\Console\Checks\IncCheck;
use Gaffer\Console\Checks\MarkupCheck;
use Gaffer\Console\Checks\RenderCheck;
use Gaffer\Console\Checks\ScreenshotCheck;
use Gaffer\Console\Checks\SearchCheck;
use Gaffer\Console\Checks\SourceCheck;
use Gaffer\Console\Checks\TemplateGlobalsCheck;
use Gaffer\Console\Checks\TagsCheck;
use Gaffer\Console\Checks\TemplatesCheck;
use Gaffer\Console\Checks\TexturizeCheck;
use Gaffer\Console\Checks\WordPressCheck;
use Gaffer\Console\Baseline;
use Gaffer\Console\Command;
use Gaffer\Console\Report;
use Gaffer\Console\WordPress;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
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
        $this->addOption('baseline', null, InputOption::VALUE_NONE, 'Write the current warnings to ' . Baseline::FILE . ' (commit it); later runs only show new ones');
        $this->addOption('prune', null, InputOption::VALUE_NONE, 'Remove the warnings that no longer occur from ' . Baseline::FILE . ' (adds none)');
        $this->addOption('all', null, InputOption::VALUE_NONE, 'Also show the warnings ' . Baseline::FILE . ' knows');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $json = (bool) $input->getOption('json');
        $misuse = $this->misuse($input);
        if ($misuse !== null) {
            return $this->refuse($output, $misuse);
        }
        $report = new Report();

        foreach ([new BlocksCheck(), new FieldGroupsCheck(), new AcfJsonCheck(), new AjaxCheck(), new FormsCheck(), new HtmxCheck(), new ConfigCheck(), new SourceCheck(), new TemplatesCheck(), new TagsCheck(), new TexturizeCheck(), new MarkupCheck(), new TemplateGlobalsCheck(), new IncCheck(), new AssetsCheck(), new AppCheck(), new GuidelinesCheck(), new DeprecationsCheck(), new InternalApiCheck(), new ScreenshotCheck(), new SearchCheck()] as $check) {
            $check->run($report);
        }

        if ($input->getOption('wp')) {
            $url = $input->getOption('url');
            WordPress::load($url);
            $wrong = WordPress::wrong_site();
            if ($wrong !== null) {
                // Every WordPress check would describe another site: stop here.
                $report->error('wp', $wrong, null, null, 'Run `php gaffer init --url=<the URL of the site that runs this theme>`; the WordPress checks were skipped.');
            } else {
                new WordPressCheck()->run($report);
                new ContractsCheck()->run($report);
            }

            if ($wrong === null && !$input->getOption('no-render')) {
                new RenderCheck(is_string($url) ? $url : null, static function (string $path) use ($output, $json): void {
                    if (!$json && $output->isVerbose()) {
                        $output->writeln("<comment>rendering</comment> {$path}");
                    }
                })->run($report);
            }
            if ($wrong === null) {
                new WordPressCheck()->served_assets($report); // last: a page request runs the site's WP-cron
            }
        }

        $wp = (bool) $input->getOption('wp');
        if ($input->getOption('baseline')) {
            $count = $report->save_baseline($wp);
            $output->writeln("Wrote {$count} warnings to " . Baseline::FILE . ' (commit it). Errors are never baselined.');
            return $report->has_errors() ? self::FAILURE : self::SUCCESS;
        }

        if ($input->getOption('prune')) {
            try {
                $baseline = Baseline::load() ?? throw new RuntimeException('There is no ' . Baseline::FILE . ' to prune: write one with doctor --baseline');
            } catch (RuntimeException $e) {
                return $this->refuse($output, $e->getMessage());
            }
            if ($baseline->wp !== $wp) {
                return $this->refuse($output, Baseline::FILE . ' was written ' . ($baseline->wp ? 'with' : 'without') . ' --wp: prune it the same way, or it drops the other checks\' warnings.');
            }
            $dropped = $report->prune_baseline($baseline);
            $output->writeln("Removed {$dropped} warnings that no longer occur from " . Baseline::FILE . ' (commit it); added none.');
            return $report->has_errors() ? self::FAILURE : self::SUCCESS;
        }

        $footer = '';
        if (!$input->getOption('all')) {
            try {
                $baseline = Baseline::load();
            } catch (RuntimeException $e) {
                $report->error('baseline', $e->getMessage());
                $baseline = null;
            }
            $footer = $baseline !== null ? $report->apply_baseline($baseline, $wp) : '';
        }

        return $report->render($output, $json, $footer);
    }

    /**
     * Options that contradict each other: exit code 2 before anything runs.
     */
    private function misuse(InputInterface $input): ?string
    {
        $writes = array_values(array_filter(['baseline', 'prune'], static fn(string $o): bool => (bool) $input->getOption($o)));
        if (count($writes) === 2) {
            return '--baseline and --prune both write ' . Baseline::FILE . ': use one.';
        }
        if ($writes !== [] && $input->getOption('json')) {
            return "--{$writes[0]} writes " . Baseline::FILE . ' and prints a line, not findings: drop --json.';
        }
        if ($writes !== [] && $input->getOption('all')) {
            return "--all only changes what's shown; --{$writes[0]} writes " . Baseline::FILE . ': use one.';
        }
        if ($input->getOption('no-render') && !$input->getOption('wp')) {
            return '--no-render only means something with --wp (the render smoke test is part of --wp).';
        }

        return null;
    }

    /** Exit code 2 (misuse): the message on stderr, so stdout stays clean for --json. */
    private function refuse(OutputInterface $output, string $message): int
    {
        $error = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $error->writeln("<error>{$message}</error>");

        return self::INVALID;
    }
}
