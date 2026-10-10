<?php

declare(strict_types=1);

namespace Gaffer\Console;

use Gaffer\Paths;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Findings from check commands. Renders as grouped text or JSON; any error
 * makes the command exit with 1.
 */
final class Report
{
    /** @var list<array{level: string, check: string, message: string, file: ?string, line: ?int, hint: ?string}> */
    private array $findings = [];

    public function error(string $check, string $message, ?string $file = null, ?int $line = null, ?string $hint = null): void
    {
        $this->add('error', $check, $message, $file, $line, $hint);
    }

    public function warning(string $check, string $message, ?string $file = null, ?int $line = null, ?string $hint = null): void
    {
        $this->add('warning', $check, $message, $file, $line, $hint);
    }

    public function info(string $check, string $message, ?string $file = null, ?int $line = null, ?string $hint = null): void
    {
        $this->add('info', $check, $message, $file, $line, $hint);
    }

    public function has_errors(): bool
    {
        return in_array('error', array_column($this->findings, 'level'), true);
    }

    /**
     * Writes the current warnings as the baseline.
     */
    public function save_baseline(bool $wp): int
    {
        $baseline = Baseline::from($this->findings, $wp);
        $baseline->save();

        return $baseline->count();
    }

    /**
     * Hides the warnings a baseline knows; returns a line saying what it hid ('' when nothing).
     */
    public function apply_baseline(Baseline $baseline, bool $wp): string
    {
        $result = $baseline->apply($this->findings);
        $this->findings = $result['findings'];

        $line = $result['known'] > 0 ? "{$result['known']} known warnings hidden (" . Baseline::FILE . '; --all shows them)' : '';
        // Only when this run checks what the baseline was made with (with or without --wp).
        if ($result['gone'] > 0 && $wp === $baseline->wp) {
            $line .= ($line !== '' ? '; ' : '') . "{$result['gone']} known warnings no longer occur: run doctor --baseline" . ($wp ? ' --wp' : '') . ' to shrink it';
        }

        return $line;
    }

    public function render(OutputInterface $output, bool $json, string $footer = ''): int
    {
        if ($json) {
            $output->writeln((string) json_encode($this->findings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return $this->has_errors() ? 1 : 0;
        }

        $styles = ['error' => 'error', 'warning' => 'comment', 'info' => 'info'];

        foreach ($this->findings as $f) {
            $where = $f['file'] !== null ? $f['file'] . ($f['line'] !== null ? ":{$f['line']}" : '') . '  ' : '';
            $output->writeln(sprintf('<%1$s>%2$-7s</%1$s> [%3$s] %4$s%5$s', $styles[$f['level']], $f['level'], $f['check'], $where, $f['message']));
            if ($f['hint'] !== null) {
                $output->writeln("        → {$f['hint']}");
            }
        }

        $counts = array_count_values(array_column($this->findings, 'level'));
        $output->writeln(sprintf(
            '%s%d errors, %d warnings, %d info',
            $this->findings === [] ? '' : "\n",
            $counts['error'] ?? 0,
            $counts['warning'] ?? 0,
            $counts['info'] ?? 0,
        ));
        if ($footer !== '') {
            $output->writeln("<comment>{$footer}</comment>");
        }

        return $this->has_errors() ? 1 : 0;
    }

    private function add(string $level, string $check, string $message, ?string $file, ?int $line, ?string $hint): void
    {
        // Paths are shown relative to the theme root.
        if ($file !== null && str_starts_with($file, Paths::base() . '/')) {
            $file = substr($file, strlen(Paths::base()) + 1);
        }

        $this->findings[] = compact('level', 'check', 'message', 'file', 'line', 'hint');
    }
}
