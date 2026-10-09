<?php

declare(strict_types=1);

namespace Gaffer\Console\Migrate;

use Closure;
use LogicException;
use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Plans a content change, then writes it only when nothing looks off: every
 * location round-trips through WordPress's block parser, isn't open in the editor
 * and has data in the expected shape; the backup is written first; all writes
 * happen in one transaction that checks the content is still what was planned;
 * afterwards the stored content is read back and compared. Every --run is
 * logged with its outcome (Log).
 */
final class Migration
{
    /** @var list<array{location: Location, before: string, after: string, summary: string}> */
    private array $plan = [];

    /** @var list<string> */
    private array $problems = [];

    /** @var list<string> */
    private array $notes = [];

    /** The backup this run restores (migrate:rollback). */
    private ?string $undoes = null;

    public function __construct(private readonly string $command) {}

    public function problem(string $message): void
    {
        $this->problems[] = $message;
    }

    public function undoes(string $backup): void
    {
        $this->undoes = $backup;
    }

    public function has_problems(): bool
    {
        return $this->problems !== [];
    }

    public function note(string $message): void
    {
        $this->notes[] = $message;
    }

    public function change(Location $location, string $before, string $after, string $summary): void
    {
        foreach ($this->plan as $planned) {
            if ($planned['location']->kind === $location->kind && $planned['location']->id === $location->id) {
                // The second change was planned from content the first one replaces: the run would abort.
                throw new LogicException("{$location->label} is changed twice in one run: rewrite it once");
            }
        }
        if ($user = ContentStore::locked_by($location)) {
            $this->problem("{$location->label}: open in the editor by user {$user}; try again when they're done");
            return;
        }
        $this->plan[] = ['location' => $location, 'before' => $before, 'after' => $after, 'summary' => $summary];
    }

    /**
     * Rewrites a location's blocks. $fn gets every block and returns [block, changes, problems].
     *
     * @param Closure(array<string, mixed>): array{array<string, mixed>, int, list<string>} $fn
     */
    public function rewrite(Location $location, Closure $fn): void
    {
        $this->rewrite_blocks($location, static function (array $blocks) use ($fn): array {
            $changes = 0;
            $problems = [];
            $after = BlockData::walk($blocks, static function (array $block) use ($fn, &$changes, &$problems): array {
                [$rewritten, $count, $found] = $fn($block);
                array_push($problems, ...$found);
                if ($count > 0) {
                    $changes += $count;
                    array_push($problems, ...self::unresolved($block, $rewritten));
                }
                return $rewritten;
            });

            return [$after, $changes, $problems];
        });
    }

    /**
     * Rewrites a location's whole block list. $fn gets parse_blocks() output and returns
     * [blocks, changes, problems].
     *
     * @param Closure(array<mixed>): array{array<mixed>, int, list<string>} $fn
     */
    public function rewrite_blocks(Location $location, Closure $fn): void
    {
        $before = ContentStore::read($location);
        $blocks = parse_blocks($before);
        if (serialize_blocks($blocks) !== $before) {
            $this->problem("{$location->label}: WordPress doesn't reproduce this content exactly from its blocks, so it can't be rewritten safely");
            return;
        }

        [$after, $changes, $problems] = $fn($blocks);

        foreach (array_unique($problems) as $problem) {
            $this->problem("{$location->label}: {$problem}");
        }
        if ($problems !== [] || $changes === 0) {
            return;
        }

        $content = serialize_blocks($after);
        $this->change($location, $before, $content, $changes === 1 ? '1 change' : "{$changes} changes");
        if (trim($content) === '') {
            $this->note("{$location->label} is empty afterwards (delete it yourself if it should go)");
        }
    }

    /**
     * Prints the plan; with $run and no problems, writes it. Returns the exit code.
     */
    public function finish(OutputInterface $output, bool $run): int
    {
        foreach ($this->plan as $change) {
            $output->writeln("  {$change['location']->label}: {$change['summary']}");
        }
        foreach ($this->notes as $note) {
            $output->writeln("  <comment>{$note}</comment>");
        }
        foreach ($this->problems as $problem) {
            $output->writeln("  <error>{$problem}</error>");
        }

        if ($this->problems !== []) {
            $output->writeln(count($this->problems) . ' problem(s): nothing was written. Fix them and run again.');
            return 1;
        }
        if ($this->plan === []) {
            $output->writeln('Nothing to change.');
            return 0;
        }
        if (!$run) {
            $output->writeln(count($this->plan) . ' location(s) would change. Dry run: nothing was written. Make a database backup, then run again with --run.');
            return 0;
        }

        return $this->write($output);
    }

    private function write(OutputInterface $output): int
    {
        global $wpdb;

        if (!ContentStore::transactional()) {
            $output->writeln('<error>The posts/options tables are not InnoDB (no transactions): nothing was written.</error>');
            return 1;
        }

        try {
            $backup = Backup::save($this->command, $this->plan);
        } catch (RuntimeException $e) {
            $this->log($output, 'aborted', $e->getMessage(), null);
            $output->writeln("<error>{$e->getMessage()}</error>");
            return 1;
        }
        $output->writeln("Backup: {$backup}");

        try {
            if ($wpdb->query('START TRANSACTION') === false) {
                throw new RuntimeException("Could not start a transaction: {$wpdb->last_error}");
            }
            foreach ($this->plan as $change) {
                if (ContentStore::read($change['location'], true) !== $change['before']) {
                    throw new RuntimeException("{$change['location']->label} changed since it was read (edited meanwhile?)");
                }
                ContentStore::write($change['location'], $change['after']);
            }
            if ($wpdb->query('COMMIT') === false) {
                throw new RuntimeException("Commit failed: {$wpdb->last_error}");
            }
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            $this->log($output, 'aborted', $e->getMessage(), $backup);
            $output->writeln("<error>{$e->getMessage()}: rolled back, nothing was written.</error>");
            return 1;
        }

        // Committed: from here on nothing may throw past this point unlogged.
        $failed = [];
        foreach ($this->plan as $change) {
            try {
                ContentStore::flush($change['location']);
                if (ContentStore::read($change['location']) !== $change['after']) {
                    $failed[] = $change['location']->label;
                }
            } catch (Throwable $e) {
                $failed[] = "{$change['location']->label} ({$e->getMessage()})";
            }
        }
        if ($failed !== []) {
            $this->log($output, 'verify_failed', 'stored content differs from what was written: ' . implode(', ', $failed), $backup);
            $output->writeln('<error>Stored content differs from what was written for: ' . implode(', ', $failed) . '. Restore with: php gaffer migrate:rollback ' . basename($backup) . ' --run</error>');
            return 1;
        }

        $this->log($output, 'written', null, $backup);
        $output->writeln('Wrote ' . count($this->plan) . ' location(s) and read them back. Undo with: php gaffer migrate:rollback ' . basename($backup));
        $output->writeln('<comment>' . $this->caches() . '</comment>');

        return 0;
    }

    /**
     * What to purge from page caches: WordPress's post cache is cleaned (page cache plugins
     * like WP Rocket purge a post on that), but a purge can fail or not exist.
     */
    private function caches(): string
    {
        $urls = [];
        $widgets = false;
        foreach ($this->plan as $change) {
            if ($change['location']->kind === 'widget') {
                $widgets = true;
            } elseif (is_string($url = get_permalink($change['location']->id))) {
                $urls[] = $url;
            }
        }

        return 'Check that page caches show the new content'
            . ($widgets ? ' (block widgets are on every page: purge the whole cache)' : '')
            . ($urls !== [] ? ': ' . implode(' ', $urls) : '.');
    }

    private function log(OutputInterface $output, string $outcome, ?string $reason, ?string $backup): void
    {
        $logged = Log::write([
            'command' => $this->command,
            'outcome' => $outcome,
            'reason' => $reason,
            'backup' => $backup !== null ? basename($backup) : null,
            'undoes' => $this->undoes,
            'locations' => array_map(static fn(array $change): array => [
                ...$change['location']->to_array(),
                'before_sha1' => sha1($change['before']),
                'after_sha1' => sha1($change['after']),
            ], $this->plan),
        ]);
        if (!$logged) {
            $output->writeln('<comment>Could not write ' . Log::file() . '.</comment>');
        }
    }

    /**
     * References a rewrite wrote that no ACF field has (the code isn't updated yet). References
     * it left alone are checked by the run that changes them, so one field at a time works.
     *
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @return list<string>
     */
    private static function unresolved(array $before, array $after): array
    {
        if (!function_exists('acf_get_field')) {
            return ['ACF is not active'];
        }
        $old = is_array($before['attrs']['data'] ?? null) ? $before['attrs']['data'] : [];
        $problems = [];
        foreach (is_array($after['attrs']['data'] ?? null) ? $after['attrs']['data'] : [] as $name => $key) {
            if (($old[$name] ?? null) === $key) {
                continue;
            }
            if (str_starts_with((string) $name, '_') && is_string($key) && !acf_get_field($key)) {
                $problems[] = "{$after['blockName']}: \"" . substr((string) $name, 1) . "\" would point to {$key}, which no field has (update fields.php first)";
            }
        }

        return $problems;
    }
}
