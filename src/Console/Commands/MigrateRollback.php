<?php

declare(strict_types=1);

namespace Gaffer\Console\Commands;

use Gaffer\Console\Migrate\Backup;
use Gaffer\Console\Migrate\ContentStore;
use Gaffer\Console\Migrate\Location;
use Gaffer\Console\Migrate\Log;
use Gaffer\Console\Migrate\Migration;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;

#[AsCommand('migrate:rollback', 'Restore the content a migrate:* run changed, from its backup (migrate:backups lists them)')]
final class MigrateRollback extends MigrateCommand
{
    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->addArgument('backup', InputArgument::REQUIRED, 'Backup file name (from storage/backups/migrate/; php gaffer migrate:backups lists them)');
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

        if ($backup['site'] !== null && \untrailingslashit($backup['site']) !== \untrailingslashit(\home_url())) {
            $migration->problem("This backup is of {$backup['site']}, not this site (" . \home_url() . '): run the rollback there (--url on a multisite)');
            return;
        }

        $name = basename((string) $input->getArgument('backup'), '.json') . '.json';
        $migration->undoes($name);
        $migration->summarize();
        $migration->note("Restores the content from before `{$backup['command']}` ({$backup['created']}). Revert the code change too (fields.php, block directory).");
        if (str_starts_with(self::status()[$name] ?? '', 'aborted')) {
            $migration->note('The log says this run was aborted: it wrote nothing, so there is nothing to undo.');
        }
        foreach ($backup['locations'] as $saved) {
            $location = new Location($saved['kind'], $saved['id'], $saved['label'], $saved['owner'] ?? null, $saved['name'] ?? null);
            // null: the row doesn't exist (deleted by the run, or what a restore inserts again).
            if (!self::owner_exists($location)) {
                $migration->note("{$location->label}: its post or term was deleted since the migration: skipped");
                continue;
            }
            $current = ContentStore::read_row($location);
            $hash = $current !== null ? sha1($current) : null;

            match (true) {
                $hash === $saved['after_sha1'] => $migration->change($location, $current, $saved['before'], $saved['before'] === null ? 'restore (delete again)' : ($current === null ? 'restore (insert)' : 'restore')),
                $hash === $saved['before_sha1'] => $migration->note("{$location->label}: already restored"),
                $current === null => $migration->problem("{$location->label} no longer exists (deleted since the migration?); its old content is in the backup"),
                default => $migration->problem("{$location->label}: changed since the migration (edited?), not restoring over it; its old content is in the backup"),
            };
        }
    }

    /** Whether a meta row's post or term (or the post of post_content) still exists. */
    private static function owner_exists(Location $location): bool
    {
        return match ($location->kind) {
            'post' => \get_post($location->id) !== null,
            'postmeta' => $location->owner === null || \get_post($location->owner) !== null,
            'termmeta' => $location->owner === null || \get_term($location->owner) instanceof \WP_Term,
            default => true,
        };
    }

    /**
     * What the log says about each backup: "written", "aborted: …", "written, undone by …".
     *
     * @return array<string, string>
     */
    public static function status(): array
    {
        $status = [];
        foreach (Log::entries() as $entry) {
            if (!is_string($entry['backup'] ?? null)) {
                continue;
            }
            $outcome = (string) ($entry['outcome'] ?? '?');
            $status[$entry['backup']] = $outcome . (is_string($entry['reason'] ?? null) ? ": {$entry['reason']}" : '');
            if ($outcome === 'written' && is_string($entry['undoes'] ?? null) && isset($status[$entry['undoes']])) {
                $status[$entry['undoes']] .= ", undone by {$entry['backup']}";
            }
        }

        return $status;
    }
}
