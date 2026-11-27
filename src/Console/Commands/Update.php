<?php

declare(strict_types=1);

namespace Gaffer\Console\Commands;

use Gaffer\Console\ChildProcess;
use Gaffer\Console\Command;
use Gaffer\Console\Update\Steps;
use Gaffer\Console\Update\Swap;
use Gaffer\Paths;
use Gaffer\Storage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * One site's whole Gaffer update, with a way back: checks before, composer in a staging copy,
 * vendor/ swapped by rename (a site never runs half an update), then ai:update, upgrade --write,
 * the build, twig:lint, doctor --wp and the pages compared with before. New doctor errors put
 * everything back. Each step runs as the theme's own `php gaffer`, so the new code checks itself.
 */
#[AsCommand('update', 'Update Gaffer (and its dependencies) on this site: staged, swapped in at once, checked, rolled back on new errors; --rollback to undo')]
final class Update extends Command
{
    private const int KEEP = 3;

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('rollback', null, InputOption::VALUE_OPTIONAL, 'Undo an update: the latest, or this one (its folder name in storage/updates/)', false)
            ->addOption('force', null, InputOption::VALUE_NONE, 'Update even when doctor --wp has errors before')
            ->addOption('keep', null, InputOption::VALUE_NONE, 'Keep the update when it causes new errors (to look at them), instead of rolling back')
            ->addOption('no-render', null, InputOption::VALUE_NONE, 'Skip comparing the rendered pages before and after')
            ->addOption('composer', null, InputOption::VALUE_REQUIRED, 'The composer command', 'composer')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print the report as one JSON object (for a monitor or a hub)');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $theme = rtrim(Paths::base(), '/');
        $base = Storage::theme_dir('updates');
        $lock = fopen("{$base}/.lock", 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            $output->writeln("<error>Another update is running on this theme ({$base}/.lock is held).</error>");

            return 2;
        }
        $json = (bool) $input->getOption('json');
        $composer = (string) $input->getOption('composer');

        $rollback = $input->getOption('rollback');
        if ($rollback !== false) {
            $id = is_string($rollback) ? basename($rollback) : self::latest($base);
            if ($id === null || !is_dir("{$base}/{$id}")) {
                $output->writeln("<error>No update to roll back in {$base}.</error>");

                return 2;
            }
            $steps = new Steps($output, $json, "{$base}/{$id}/rollback.log", $theme);
            self::rollback($theme, "{$base}/{$id}", $steps);
            $output->writeln($json ? (string) json_encode(['format' => 1, 'result' => 'rolled back', 'id' => $id, 'reference' => self::reference($theme), 'log' => $steps->log]) : "Rolled back {$id}: Gaffer is at " . self::reference($theme) . ". Log: {$steps->log}");

            return self::SUCCESS;
        }

        $id = date('Ymd-His');
        $dir = "{$base}/{$id}";
        $steps = new Steps($output, $json, "{$dir}/update.log", $theme);
        $report = ['format' => 1, 'result' => null, 'id' => $id, 'log' => $steps->log, 'from' => self::reference($theme), 'to' => null, 'packages' => [], 'errors_before' => null, 'errors_after' => null, 'pages_changed' => null, 'changed_pages' => [], 'upgrade' => null, 'build' => null];
        $finish = static function (string $result, int $code) use (&$report, $output, $json): int {
            $report['result'] = $result;
            if ($json) {
                $output->writeln((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            }

            return $code;
        };

        $steps->step("Preflight: {$theme}");
        $steps->detail("Gaffer now: {$report['from']} (composer.lock); PHP " . PHP_VERSION . ' (' . PHP_BINARY . ')');
        $problem = self::preflight($theme, $base, $composer, $steps);
        if ($problem !== null) {
            $steps->detail("<error>✗ {$problem}</error>");

            return $finish('refused: ' . $problem, 2);
        }

        $steps->step('doctor --wp before the update (an update never starts on a red site)');
        $before = self::doctor($theme, $steps);
        $report['errors_before'] = $before['errors'] ?? null;
        if ($before === null) {
            $steps->detail('<error>✗ doctor --wp gave no report, so the update couldn\'t be checked: run `php gaffer doctor --wp` and fix what stops it.</error>');

            return $finish('refused: doctor --wp could not run', 2);
        }
        if ($before['errors'] > 0 && !$input->getOption('force')) {
            $steps->detail("<error>✗ {$before['errors']} errors: fix them first (php gaffer doctor --wp), or --force.</error>");

            return $finish('refused: errors before', 2);
        }

        $render = !$input->getOption('no-render');
        if ($render) {
            $steps->step("Rendered pages before the update (render:snapshot gaffer-update-{$id})");
            $snapshot = $steps->run([PHP_BINARY, "{$theme}/gaffer", 'render:snapshot', "gaffer-update-{$id}"], $theme, 1800);
            $steps->detail(self::last_line($snapshot) . ' (' . ($snapshot['seconds'] ?? '?') . ' s)');
        }

        $steps->step('Staging copy and composer');
        try {
            $root = Swap::stage($theme, $dir);
            $steps->detail("Staging: {$root} (composer.json, composer.lock, a copy of vendor/, links to the rest)");
            $run = $steps->run([$composer, 'update', '-W', 'onstage2426/gaffer', '--no-interaction', '--no-scripts', '--no-progress'], $root, 900);
            if ($run === null || $run['code'] !== 0) {
                $steps->failed('composer update', $run);
                Swap::clean_stage($root);
                $steps->detail('Nothing was changed: the site still runs its vendor/.');

                return $finish('failed: composer', 2);
            }
            $steps->detail("composer: {$run['seconds']} s");
            $report['packages'] = self::package_changes($theme, $root);
            foreach ($report['packages'] as $name => $change) {
                $steps->detail("  {$name}: {$change}");
            }
            $problem = Swap::problem($theme, $root);
            if ($problem !== null) {
                $steps->detail("<error>✗ {$problem}</error> Nothing was changed.");
                Swap::clean_stage($root);

                return $finish('failed: ' . $problem, 2);
            }
            $steps->detail('Autoloader paths: relative (checked vendor/composer/*.php)');
            $report['to'] = self::reference($root);
            if ($report['to'] === $report['from']) {
                Swap::clean_stage($root);
                $steps->detail("Gaffer is already at {$report['from']}: nothing to swap.");
                ChildProcess::run(['rm', '-rf', $dir], null, 120);

                return $finish('up to date', self::SUCCESS);
            }

            $steps->step("Swap: Gaffer {$report['from']} → {$report['to']}");
            Swap::keep_before($theme, $dir);
            $steps->detail("Kept for a rollback: {$dir}/before/ (composer files, the Vite manifest and stamp)");
            Swap::swap($theme, $dir, $root);
            Swap::clean_stage($root);
            $steps->detail("vendor/ → {$dir}/vendor.old; the staged vendor/ → vendor/ (two renames); composer.lock copied");
        } catch (Throwable $e) {
            $steps->detail('<error>✗ ' . $e->getMessage() . '</error>');

            return $finish('failed: ' . $e->getMessage(), 2);
        }

        $steps->step('ai:update (the guidelines and skills of the new Gaffer)');
        $ai = $steps->run([PHP_BINARY, "{$theme}/gaffer", 'ai:update'], $theme, 300);
        $ai !== null && $ai['code'] === 0 ? $steps->detail('ok (' . $ai['seconds'] . ' s)') : $steps->failed('ai:update (not fatal)', $ai);

        $steps->step('upgrade --write (the mechanical CHANGELOG changes)');
        $upgrade = $steps->run([PHP_BINARY, "{$theme}/gaffer", 'upgrade', '--write'], $theme, 1200);
        $text = $upgrade['stdout'] ?? '';
        $report['upgrade'] = str_contains($text, 'Nothing to upgrade') ? 'nothing' : trim((string) preg_replace('/\s+/', ' ', self::last_line($upgrade)));
        foreach (array_filter(explode("\n", $text), static fn(string $l): bool => (bool) preg_match('/^(\d{4}-\d\d-\d\d-\S+:|\+\+\+ b\/|  (new|delete) |  By hand:)/', $l)) as $line) {
            $steps->detail(trim($line));
        }
        $steps->detail($report['upgrade']);
        if (preg_match('/upgrade --restore=(\S+?)\)?\.?$/m', $text, $m)) {
            file_put_contents("{$dir}/upgrade-backup", $m[1]);
        }

        $steps->step('Build');
        $report['build'] = self::build($theme, $steps);

        $steps->step('doctor --wp after the update');
        $after = self::doctor($theme, $steps);
        $report['errors_after'] = $after['errors'] ?? null;
        if ($render) {
            $steps->step("Rendered pages after (render:diff gaffer-update-{$id} --hide-reorders)");
            $diff = $steps->run([PHP_BINARY, "{$theme}/gaffer", 'render:diff', "gaffer-update-{$id}", '--json', '--hide-reorders'], $theme, 1800);
            $data = json_decode($diff['stdout'] ?? '', true);
            $report['pages_changed'] = is_array($data) && is_int($data['different'] ?? null) ? $data['different'] : null;
            $pages = [];
            foreach (is_array($data) ? (array) ($data['changes'] ?? []) : [] as $change) {
                if (is_array($change) && !($change['reorder_only'] ?? false)) {
                    array_push($pages, ...array_map('strval', (array) ($change['pages'] ?? [])));
                }
            }
            $report['changed_pages'] = array_values(array_unique($pages));
            $steps->detail(is_array($data) ? ($data['same'] ?? '?') . ' the same, ' . ($report['pages_changed'] ?? '?') . ' different' . ($report['changed_pages'] !== [] ? ': ' . implode(', ', array_slice($report['changed_pages'], 0, 10)) . (count($report['changed_pages']) > 10 ? ', …' : '') : '') : 'no comparison: ' . self::last_line($diff));
            if ($report['changed_pages'] !== []) {
                // Cached pages still work after any update (old HTML with new code, old builds kept a
                // week); these show something new, which a page cache keeps showing until it expires.
                $steps->detail('A page cache shows the old versions of these until it expires: purge it to show them now.');
            }
        }

        if ($after === null || $after['errors'] > $before['errors']) {
            $why = $after === null ? 'doctor --wp gave no report after the update' : "doctor --wp: {$before['errors']} errors before, {$after['errors']} after";
            if ($input->getOption('keep')) {
                $steps->detail("<error>✗ {$why}.</error> Kept (--keep): look, then undo with php gaffer update --rollback={$id}");

                return $finish('kept with new errors', 1);
            }
            $steps->step("Rollback ({$why})");
            self::rollback($theme, $dir, $steps);
            $steps->detail("<error>✗ The update was rolled back.</error> Run it again with --keep to look at the errors. Log: {$steps->log}");

            return $finish('rolled back: ' . $why, 1);
        }

        self::prune($base);
        $steps->step("Done: Gaffer {$report['from']} → {$report['to']} in {$steps->elapsed()} s");
        $steps->detail("Undo: php gaffer update --rollback={$id}  (vendor.old and the rest in {$dir}; the last " . self::KEEP . ' updates are kept)');
        $steps->detail("Log: {$steps->log}");

        return $finish('updated', self::SUCCESS);
    }

    private static function preflight(string $theme, string $base, string $composer, Steps $steps): ?string
    {
        foreach (['composer.json', 'composer.lock', 'vendor'] as $needed) {
            if (!file_exists("{$theme}/{$needed}")) {
                return "The theme has no {$needed}.";
            }
        }
        if (@stat($theme)['dev'] !== @stat($base)['dev']) {
            return "{$base} is on another file system than the theme (GAFFER_STORAGE?): vendor/ can't be swapped by rename.";
        }
        $version = $steps->run([$composer, '--version', '--no-interaction'], $theme, 60);
        if ($version === null || $version['code'] !== 0) {
            $steps->failed('composer --version', $version);

            return "{$composer} doesn't run here (--composer=<path>).";
        }
        $steps->detail(strtok(trim($version['stdout']), "\n") ?: '(no version)');
        $size = ChildProcess::run(['du', '-sk', "{$theme}/vendor"], null, 120);
        $kb = (int) ($size['stdout'] ?? 0);
        $free = (int) (disk_free_space($base) ?: 0);
        $steps->detail(sprintf('vendor/ %.1f MB, %.0f MB free in %s', $kb / 1024, $free / 1048576, $base));
        if ($kb > 0 && $free < $kb * 1024 * 3) {
            return 'Not enough disk space for a staging copy of vendor/ (three times its size).';
        }

        return null;
    }

    private static function rollback(string $theme, string $dir, Steps $steps): void
    {
        $backup = is_file("{$dir}/upgrade-backup") ? trim((string) file_get_contents("{$dir}/upgrade-backup")) : '';
        Swap::back($theme, $dir);
        $steps->detail("vendor/ ← {$dir}/vendor.old (the update's vendor/ kept as vendor.failed); composer files and the Vite manifest and stamp ← {$dir}/before/");
        if ($backup !== '') {
            $restore = $steps->run([PHP_BINARY, "{$theme}/gaffer", 'upgrade', "--restore={$backup}"], $theme, 300);
            $restore !== null && $restore['code'] === 0 ? $steps->detail('upgrade\'s changes put back') : $steps->failed('upgrade --restore', $restore);
        }
        $ai = $steps->run([PHP_BINARY, "{$theme}/gaffer", 'ai:update'], $theme, 300);
        if ($ai === null || $ai['code'] !== 0) {
            $steps->failed('ai:update (not fatal)', $ai);
        }
    }

    /** @return array{errors: int, warnings: int}|null doctor --wp's counts, its errors named; null without a report */
    private static function doctor(string $theme, Steps $steps): ?array
    {
        $run = $steps->run([PHP_BINARY, "{$theme}/gaffer", 'doctor', '--wp', '--json'], $theme, 1800);
        $json = json_decode($run['stdout'] ?? '', true);
        if (!is_array($json) || !is_int($json['errors'] ?? null)) {
            $steps->failed('doctor --wp', $run);

            return null;
        }
        $steps->detail("{$json['errors']} errors, " . (int) ($json['warnings'] ?? 0) . ' warnings (' . ($run['seconds'] ?? '?') . ' s)');
        foreach ((array) ($json['findings'] ?? []) as $finding) {
            if (is_array($finding) && ($finding['level'] ?? null) === 'error') {
                $steps->detail('  error [' . ($finding['check'] ?? '?') . '] ' . ($finding['file'] ?? '') . (isset($finding['line']) ? ":{$finding['line']}" : '') . '  ' . ($finding['message'] ?? ''));
            }
        }

        return ['errors' => $json['errors'], 'warnings' => (int) ($json['warnings'] ?? 0)];
    }

    /** The theme's own build with its package manager: bun.lock → bun, package-lock.json → npm. */
    private static function build(string $theme, Steps $steps): string
    {
        $command = match (true) {
            is_file("{$theme}/bun.lock") || is_file("{$theme}/bun.lockb") => (ChildProcess::run(['bun', '--version'], null, 30)['code'] ?? 1) === 0 ? ['bun', 'run', 'build'] : ['npx', '-y', 'bun@1', 'run', 'build'],
            is_file("{$theme}/package-lock.json") => ['npm', 'run', 'build'],
            default => null,
        };
        if ($command === null) {
            $steps->detail('skipped: no bun.lock or package-lock.json');

            return 'skipped';
        }
        $run = $steps->run($command, $theme, 1200);
        if ($run === null || $run['code'] !== 0) {
            $steps->failed(implode(' ', $command), $run);

            return 'failed';
        }
        $steps->detail("ok ({$run['seconds']} s)");

        return 'ok';
    }

    /** @param array{stdout: string, stderr: string}|null $run */
    private static function last_line(?array $run): string
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", ($run['stdout'] ?? '') . "\n" . ($run['stderr'] ?? '')))));

        return $lines === [] ? '(no output)' : (string) end($lines);
    }

    /**
     * Packages whose version changed between the theme's composer.lock and the staged one.
     *
     * @return array<string, string> name => "old → new"
     */
    private static function package_changes(string $theme, string $root): array
    {
        $versions = static function (string $file): array {
            $lock = json_decode((string) @file_get_contents($file), true);
            $out = [];
            foreach ((array) ($lock['packages'] ?? []) as $p) {
                if (is_array($p) && is_string($p['name'] ?? null)) {
                    $ref = $p['source']['reference'] ?? $p['dist']['reference'] ?? null;
                    $version = (string) ($p['version'] ?? '?');
                    $out[$p['name']] = $version . (is_string($ref) && str_contains($version, 'dev') ? '@' . substr($ref, 0, 7) : '');
                }
            }

            return $out;
        };
        $old = $versions("{$theme}/composer.lock");
        $new = $versions("{$root}/composer.lock");
        $changes = [];
        foreach ($new + $old as $name => $_) {
            if (($old[$name] ?? null) !== ($new[$name] ?? null)) {
                $changes[$name] = ($old[$name] ?? '(new)') . ' → ' . ($new[$name] ?? '(removed)');
            }
        }
        ksort($changes);

        return $changes;
    }

    /** Gaffer's reference in a composer.lock (7 characters), or its version. */
    private static function reference(string $dir): ?string
    {
        $lock = json_decode((string) @file_get_contents("{$dir}/composer.lock"), true);
        foreach ((array) ($lock['packages'] ?? []) as $package) {
            if (is_array($package) && ($package['name'] ?? null) === 'onstage2426/gaffer') {
                $reference = $package['source']['reference'] ?? $package['dist']['reference'] ?? null;

                return is_string($reference) ? substr($reference, 0, 7) : (is_string($package['version'] ?? null) ? $package['version'] : null);
            }
        }

        return null;
    }

    private static function latest(string $base): ?string
    {
        $dirs = array_map(basename(...), glob("{$base}/2*", GLOB_ONLYDIR) ?: []);
        rsort($dirs);

        return $dirs[0] ?? null;
    }

    /** The last KEEP updates stay (vendor.old is the rollback); older folders go. */
    private static function prune(string $base): void
    {
        $dirs = glob("{$base}/2*", GLOB_ONLYDIR) ?: [];
        rsort($dirs);
        foreach (array_slice($dirs, self::KEEP) as $old) {
            ChildProcess::run(['rm', '-rf', $old], null, 300);
        }
    }
}
