<?php

declare(strict_types=1);

namespace Gaffer\Console\Commands;

use Gaffer\Console\Command;
use Gaffer\Console\Migrate\Backup;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The backups migrate:* --run saved (storage/backups/migrate/), with the command that made
 * each and what the log says about it (written, aborted, undone): what migrate:rollback takes.
 */
#[AsCommand('migrate:backups', 'List the backups migrate:* runs saved, for migrate:rollback')]
final class MigrateBackups extends Command
{
    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $files = Backup::all();
        if ($files === []) {
            $output->writeln('No migration backups in ' . Backup::dir() . '.');
            return self::SUCCESS;
        }
        $status = MigrateRollback::status();
        foreach ($files as $file) {
            try {
                $backup = Backup::load($file);
                $output->writeln(basename($file) . "  {$backup['command']}  (" . count($backup['locations']) . ' locations)  ' . ($status[basename($file)] ?? 'not in the log'));
            } catch (RuntimeException $e) {
                $output->writeln(basename($file) . "  <error>{$e->getMessage()}</error>");
            }
        }
        $output->writeln('Undo one with: php gaffer migrate:rollback <backup> (a dry run; then --run)');

        return self::SUCCESS;
    }
}
