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

    /** @var array<string, true> the plan's locations, by "{kind}:{id}" */
    private array $planned = [];

    /** @var list<string> */
    private array $problems = [];

    /** @var list<string> */
    private array $notes = [];

    /** Above this many locations the log line counts them per kind; the backup has each one. */
    private const int LOGGED_LOCATIONS = 200;

    /** The backup this run restores (migrate:rollback). */
    private ?string $undoes = null;

    /** Print one line per change summary (with counts) instead of one per location. */
    private bool $summarize = false;

    /** @var (Closure(): array<string, string>)|null what ACF reads for the changed data, by owner and field */
    private ?Closure $values = null;

    /** @param OutputInterface|null $progress where to say what the plan is doing (stderr), while it builds */
    public function __construct(private readonly string $command, private readonly ?OutputInterface $progress = null) {}

    public function progress(string $message): void
    {
        $this->progress?->writeln("<comment>{$message}</comment>");
    }

    /**
     * Print the plan as one line per distinct summary with its count and location kinds
     * (field references: thousands of locations with a few distinct rewrites); -v lists all.
     */
    public function summarize(): void
    {
        $this->summarize = true;
    }

    /**
     * Compare what ACF reads before writing and after the read-back: $read returns a hash per
     * owner and field (formatted get_field()). Any difference fails the run as verify_failed,
     * with the rollback command.
     *
     * @param Closure(): array<string, string> $read
     */
    public function check_values(Closure $read): void
    {
        $this->values = $read;
    }

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
        $key = "{$location->kind}:{$location->id}";
        if (isset($this->planned[$key])) {
            // The second change was planned from content the first one replaces: the run would abort.
            throw new LogicException("{$location->label} is changed twice in one run: rewrite it once");
        }
        $post = ContentStore::post_of($location);
        $lock = $post !== null ? ContentStore::lock($post) : null;
        if ($lock !== null) {
            // One line per post, however many of its rows change (problems are printed once each).
            $minutes = (int) round((time() - $lock['since']) / 60);
            $this->problem("post {$post} \"" . get_the_title($post) . "\": open in the editor by user {$lock['user']} (lock {$minutes} min old); try again when they're done");
            return;
        }
        $this->plan[] = ['location' => $location, 'before' => $before, 'after' => $after, 'summary' => $summary];
        $this->planned[$key] = true;
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
        // Problems first: they decide whether anything is written.
        $problems = array_values(array_unique($this->problems));
        foreach ($problems as $problem) {
            $output->writeln("  <error>{$problem}</error>");
        }
        foreach ($this->notes as $note) {
            $output->writeln("  <comment>{$note}</comment>");
        }
        if ($this->summarize && !$output->isVerbose()) {
            foreach ($this->summary() as $summary => [$count, $kinds, $label]) {
                $output->writeln($count === 1
                    ? "  {$label}: {$summary}"
                    : "  {$summary}: {$count} (" . implode(', ', array_map(static fn(string $kind, int $n): string => "{$n} {$kind}", array_keys($kinds), $kinds)) . ')');
            }
        } else {
            foreach ($this->plan as $change) {
                $output->writeln("  {$change['location']->label}: {$change['summary']}");
            }
        }

        if ($problems !== []) {
            $output->writeln(count($problems) . ' problem(s): nothing was written. Fix them and run again.');
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

    /**
     * The plan by summary: [count, locations per kind ("product", "revision", "option"), the
     * first location's label (printed instead when it's the only one)].
     *
     * @return array<string, array{int, array<string, int>, string}>
     */
    private function summary(): array
    {
        $summary = [];
        foreach ($this->plan as $change) {
            $kind = strtok($change['location']->label, ' ') ?: $change['location']->kind;
            $summary[$change['summary']] ??= [0, [], $change['location']->label];
            $summary[$change['summary']][0]++;
            $summary[$change['summary']][1][$kind] = ($summary[$change['summary']][1][$kind] ?? 0) + 1;
        }

        return $summary;
    }

    private function write(OutputInterface $output): int
    {
        global $wpdb;

        if (!ContentStore::transactional()) {
            $output->writeln('<error>The posts/options tables are not InnoDB (no transactions): nothing was written.</error>');
            return 1;
        }

        if ($this->values !== null) {
            $this->progress('Reading the current values through ACF…');
        }
        $values = $this->values !== null ? ($this->values)() : null;

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

        if ($this->values !== null && $values !== null) {
            $this->progress('Reading the values through ACF again…');
            $after = ($this->values)();
            $differ = array_keys(array_filter($values, static fn(string $hash, string $id): bool => ($after[$id] ?? null) !== $hash, ARRAY_FILTER_USE_BOTH));
            if ($differ !== []) {
                $listed = implode(', ', array_slice($differ, 0, 10)) . (count($differ) > 10 ? ' and ' . (count($differ) - 10) . ' more' : '');
                $this->log($output, 'verify_failed', 'ACF reads other values than before for ' . count($differ) . ": {$listed}", $backup);
                $output->writeln('<error>ACF reads other values than before for ' . count($differ) . " field(s): {$listed}. Restore with: php gaffer migrate:rollback " . basename($backup) . ' --run</error>');
                return 1;
            }
        }

        $this->log($output, 'written', null, $backup);
        $output->writeln('Wrote ' . count($this->plan) . ' location(s) and read them back' . ($values !== null ? ', and ACF reads the same ' . count($values) . ' values as before' : '') . '. Undo with: php gaffer migrate:rollback ' . basename($backup));
        $output->writeln('<comment>' . $this->caches() . '</comment>');

        return 0;
    }

    /**
     * What to purge from page caches: WordPress's post cache is cleaned (page cache plugins
     * like WP Rocket purge a post on that), but a purge can fail or not exist.
     */
    private function caches(): string
    {
        $posts = [];
        $terms = [];
        $everywhere = null;
        foreach ($this->plan as $change) {
            $location = $change['location'];
            match ($location->kind) {
                'widget' => $everywhere = 'block widgets are on every page',
                'option' => $everywhere ??= 'options can be on every page',
                'termmeta' => $terms[(int) ContentStore::owner($location)] = true,
                default => $posts[(int) ContentStore::post_of($location)] = true,
            };
        }
        // Each post or term once: a field migration changes many rows of the same ones.
        $urls = [];
        foreach ([...array_map(get_permalink(...), array_keys($posts)), ...array_map(get_term_link(...), array_keys($terms))] as $url) {
            if (is_string($url)) {
                $urls[$url] = true;
            }
        }

        return 'Check that page caches show the new content'
            . ($everywhere !== null ? " ({$everywhere}: purge the whole cache)" : '')
            . ($urls !== [] ? ': ' . implode(' ', array_keys($urls)) : '.');
    }

    private function log(OutputInterface $output, string $outcome, ?string $reason, ?string $backup): void
    {
        $logged = Log::write([
            'command' => $this->command,
            'outcome' => $outcome,
            'reason' => $reason,
            'backup' => $backup !== null ? basename($backup) : null,
            'undoes' => $this->undoes,
            ...(count($this->plan) <= self::LOGGED_LOCATIONS
                ? ['locations' => array_map(static fn(array $change): array => [
                    ...$change['location']->to_array(),
                    'before_sha1' => sha1($change['before']),
                    'after_sha1' => sha1($change['after']),
                ], $this->plan)]
                : ['location_counts' => array_count_values(array_map(static fn(array $change): string => $change['location']->kind, $this->plan))]),
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
