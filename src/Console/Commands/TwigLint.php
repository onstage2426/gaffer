<?php

declare(strict_types=1);

namespace Gaffer\Console\Commands;

use Gaffer\Console\Command;
use Gaffer\Console\Report;
use Gaffer\Console\Templates;
use Gaffer\Paths;
use Gaffer\View;
use Gaffer\Gaffer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Twig\Error\Error as TwigError;

#[AsCommand('twig:lint', 'Compile every Twig template: syntax errors and unknown functions/filters/tests')]
final class TwigLint extends Command
{
    #[\Override]
    protected function configure(): void
    {
        $this->addArgument('path', InputArgument::OPTIONAL, 'Only lint templates under this path (relative to the theme root)');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        Gaffer::twig();
        $env = View::env();
        $report = new Report();

        $only = $input->getArgument('path');
        $only = is_string($only) ? Paths::base(trim($only, '/')) : null;
        $count = 0;

        foreach (Templates::all() as $name => $file) {
            if ($only !== null && !str_starts_with($file, $only)) {
                continue;
            }
            $count++;

            try {
                $env->compileSource($env->getLoader()->getSourceContext($name));
            } catch (TwigError $e) {
                $report->error('twig', $e->getRawMessage(), $file, $e->getTemplateLine() > 0 ? $e->getTemplateLine() : null);
            }
        }

        if (!$input->getOption('json')) {
            $output->writeln("Linted {$count} templates.");
        }

        return $report->render($output, (bool) $input->getOption('json'));
    }
}
