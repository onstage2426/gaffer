<?php

declare(strict_types=1);

namespace Gaffer\Console\Update;

use Gaffer\Console\ChildProcess;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The update's steps as a developer reads them: what runs (the command, where), how long it took
 * and what it found, numbered; a failure names the step, the command, its exit code and the end
 * of its output. Every command's whole output goes to the log file.
 *
 * @internal
 */
final class Steps
{
    private int $number = 0;

    private readonly float $start;

    /** @param string $theme shown as `.`, and its `php gaffer` as `php gaffer` (the log has full paths) */
    public function __construct(
        private readonly OutputInterface $output,
        private readonly bool $quiet,
        public readonly string $log,
        private readonly string $theme = '',
    ) {
        $this->start = microtime(true);
        if (!is_dir(dirname($log))) {
            mkdir(dirname($log), 0700, true);
        }
        file_put_contents($log, '# php gaffer update, ' . date('c') . ' (step times below are UTC)' . "\n");
    }

    /** A new numbered step. */
    public function step(string $title): void
    {
        $this->number++;
        $this->line("<info>[{$this->number}]</info> {$title}");
        // The time of day too, to line a step up with a server's logs or a monitor.
        $this->append("\n## [{$this->number}] {$title}  ({$this->time()}, +{$this->elapsed()} s)\n");
    }

    /** An indented detail of the current step. */
    public function detail(string $line): void
    {
        $this->line('    ' . ($this->theme !== '' ? str_replace("{$this->theme}/", '', $line) : $line));
        $this->append("{$line}\n");
    }

    /**
     * Runs a command, says which and for how long, and logs its whole output.
     *
     * @param list<string> $command
     * @return array{stdout: string, stderr: string, code: int, timed_out: bool, seconds: float}|null
     */
    public function run(array $command, ?string $cwd, int $timeout): ?array
    {
        $shown = implode(' ', array_map(static fn(string $a): string => preg_match('/^[\w.\/:=@,+-]+$/', $a) === 1 ? $a : escapeshellarg($a), $command));
        $short = $shown;
        if ($this->theme !== '') {
            $short = str_replace([PHP_BINARY . " {$this->theme}/gaffer", "{$this->theme}/"], ['php gaffer', ''], $short);
        }
        $where = $cwd === null || $cwd === $this->theme ? '' : '  (in ' . ($this->theme !== '' && str_starts_with($cwd, "{$this->theme}/") ? substr($cwd, strlen($this->theme) + 1) : $cwd) . ')';
        $this->line("    \$ {$short}{$where}");
        $this->append("\$ {$shown}" . ($cwd !== null ? "  (in {$cwd})" : '') . "\n");
        $start = microtime(true);
        $run = ChildProcess::run($command, $cwd, $timeout);
        $seconds = round(microtime(true) - $start, 1);
        if ($run === null) {
            $this->append("(couldn't start)\n");

            return null;
        }
        $this->append("exit {$run['code']}" . ($run['timed_out'] ? ' (timed out)' : '') . ", {$seconds} s\n--- stdout\n{$run['stdout']}\n--- stderr\n{$run['stderr']}\n");

        return [...$run, 'seconds' => $seconds];
    }

    /** Now, in UTC to the millisecond (21:53:37.123Z). */
    public function time(): string
    {
        $now = microtime(true);

        return gmdate('H:i:s', (int) $now) . sprintf('.%03dZ', (int) (($now - floor($now)) * 1000));
    }

    /** Seconds since the update started. */
    public function elapsed(): float
    {
        return round(microtime(true) - $this->start, 1);
    }

    /** @param array{stdout: string, stderr: string, code: int, timed_out: bool, seconds: float}|null $run */
    public function failed(string $what, ?array $run): void
    {
        $why = $run === null ? 'couldn\'t start' : ($run['timed_out'] ? "timed out after {$run['seconds']} s" : "exit {$run['code']}");
        $this->line("<error>    ✗ {$what} failed: {$why}</error>");
        $tail = $run === null ? [] : array_slice(array_values(array_filter(explode("\n", trim($run['stderr'] . "\n" . $run['stdout'])))), -15);
        foreach ($tail as $line) {
            $this->line("      | {$line}");
        }
        $this->line("    Whole output: {$this->log}");
        $this->append("FAILED: {$what} ({$why})\n");
    }

    private function line(string $line): void
    {
        if (!$this->quiet) {
            $this->output->writeln($line);
        }
    }

    private function append(string $text): void
    {
        file_put_contents($this->log, $text, FILE_APPEND);
    }
}
