<?php

declare(strict_types=1);

namespace Gaffer\Console\Commands;

use Gaffer\Ai\Agent;
use Gaffer\Ai\Installer;
use Gaffer\Config;
use Gaffer\Console\Command;
use Gaffer\Paths;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;

#[AsCommand('ai:install', 'Choose AI agents (saved in config/ai.php), gitignore their files and write guidelines and skills')]
final class AiInstall extends Command
{
    #[\Override]
    protected function configure(): void
    {
        $this->addOption('agents', null, InputOption::VALUE_REQUIRED, 'Comma-separated, e.g. claude,codex (skips the question)');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $all = Agent::all();
        $given = $input->getOption('agents');

        if (is_string($given)) {
            $agents = array_values(array_filter(array_map(trim(...), explode(',', $given))));
        } else {
            $detected = array_keys(array_filter($all, static fn(Agent $a): bool => $a->detected(Paths::base())));
            $current = Config::get('ai.agents');
            $defaults = is_array($current) && $current !== [] ? $current : ($detected ?: ['claude']);

            $question = new ChoiceQuestion(
                'Which AI agents does this theme use? (comma-separated)',
                array_map(static fn(Agent $a): string => $a->label, $all),
                implode(',', $defaults),
            );
            $question->setMultiselect(true);

            /** @var QuestionHelper $helper */
            $helper = $this->getHelper('question');
            $agents = array_values((array) $helper->ask($input, $output, $question));
        }

        Installer::agents($agents); // validates the names
        file_put_contents(Paths::base('config/ai.php'), self::config_file($agents));
        $output->writeln('Saved config/ai.php: ' . implode(', ', $agents));

        $status = AiUpdate::generate($agents, $input, $output);

        $output->writeln('');
        $output->writeln('Commit config/ai.php and .gitignore (not the generated files). To regenerate after `composer update`, add to composer.json:');
        $output->writeln('  "scripts": { "post-update-cmd": ["@php gaffer ai:update --ansi"] }');

        return $status;
    }

    /** @param list<string> $agents */
    private static function config_file(array $agents): string
    {
        $list = implode(', ', array_map(static fn(string $a): string => "'{$a}'", $agents));

        return <<<PHP
        <?php

        declare(strict_types=1);

        // Written by `php gaffer ai:install`. Options: vendor/onstage2426/gaffer/config/ai.php
        return [
            'agents' => [{$list}],
        ];

        PHP;
    }
}
