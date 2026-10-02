<?php

declare(strict_types=1);

namespace Gaffer\Console\Commands;

use Gaffer\Console\Command;
use Gaffer\Console\Migrate\Migration;
use RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Shared by the migrate:* commands: WordPress loaded, dry run unless --run.
 */
abstract class MigrateCommand extends Command
{
    protected bool $wordpress = true;

    #[\Override]
    protected function configure(): void
    {
        $this->addOption('run', null, InputOption::VALUE_NONE, 'Write the changes (default: dry run)');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!function_exists('acf_get_field')) {
            $output->writeln('<error>ACF is not active.</error>');
            return self::FAILURE;
        }

        $arguments = array_filter($input->getArguments(), static fn(mixed $value, string $name): bool => $name !== 'command' && is_string($value), ARRAY_FILTER_USE_BOTH);
        $migration = new Migration(trim($this->getName() . ' ' . implode(' ', $arguments)));
        try {
            $this->plan($migration, $input);
        } catch (RuntimeException $e) {
            $migration->problem($e->getMessage());
        }

        return $migration->finish($output, (bool) $input->getOption('run'));
    }

    abstract protected function plan(Migration $migration, InputInterface $input): void;
}
