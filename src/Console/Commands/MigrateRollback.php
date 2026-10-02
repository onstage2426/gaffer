<?php

declare(strict_types=1);

namespace Gaffer\Console\Commands;

use Gaffer\Console\Migrate\Backup;
use Gaffer\Console\Migrate\ContentStore;
use Gaffer\Console\Migrate\Location;
use Gaffer\Console\Migrate\Migration;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('migrate:rollback', 'Restore the content a migrate:* run changed, from its backup (no backup given: list them)')]
final class MigrateRollback extends MigrateCommand
{
    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->addArgument('backup', InputArgument::OPTIONAL, 'Backup file name (from storage/backups/migrate/)');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getArgument('backup') !== null) {
            return parent::execute($input, $output);
        }

        $files = Backup::all();
        if ($files === []) {
            $output->writeln('No migration backups in ' . Backup::dir() . '.');
        }
        foreach ($files as $file) {
            try {
                $backup = Backup::load($file);
                $output->writeln(basename($file) . "  {$backup['command']}  (" . count($backup['locations']) . ' locations)');
            } catch (RuntimeException $e) {
                $output->writeln(basename($file) . "  <error>{$e->getMessage()}</error>");
            }
        }

        return self::SUCCESS;
    }

    #[\Override]
    protected function plan(Migration $migration, InputInterface $input): void
    {
        try {
            $backup = Backup::load((string) $input->getArgument('backup'));
        } catch (RuntimeException $e) {
            $migration->problem($e->getMessage());
            return;
        }

        $migration->note("Restores the content from before `{$backup['command']}` ({$backup['created']}). Revert the code change too (fields.php, block directory).");
        foreach ($backup['locations'] as $saved) {
            $location = new Location($saved['kind'], $saved['id'], $saved['label']);
            try {
                $current = ContentStore::read($location);
            } catch (RuntimeException $e) {
                $migration->problem($e->getMessage());
                continue;
            }

            match (sha1($current)) {
                $saved['after_sha1'] => $migration->change($location, $current, $saved['before'], 'restore'),
                $saved['before_sha1'] => $migration->note("{$location->label}: already restored"),
                default => $migration->problem("{$location->label}: changed since the migration (edited?), not restoring over it; its old content is in the backup"),
            };
        }
    }
}
