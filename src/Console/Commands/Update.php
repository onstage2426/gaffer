<?php

declare(strict_types=1);

namespace Gaffer\Console\Commands;

use Gaffer\Console\ChildProcess;
use Gaffer\Console\Command;
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
            $output->writeln('<error>Another update is running on this theme.</error>');

            return 2;
        }
        $json = (bool) $input->getOption('json');
        $say = static function (string $line) use ($output, $json): void {
            if (!$json) {
                $output->writeln($line);
            }
        };

        $rollback = $input->getOption('rollback');
        if ($rollback !== false) {
            $id = is_string($rollback) ? basename($rollback) : self::latest($base);
            if ($id === null || !is_dir("{$base}/{$id}")) {
                $output->writeln('<error>No update to roll back.</error>');

                return 2;
            }
            self::rollback($theme, "{$base}/{$id}", $say);
            $say("Rolled back {$id}.");

            return self::SUCCESS;
        }

        $report = ['format' => 1, 'result' => null, 'from' => self::reference($theme), 'to' => null, 'errors_before' => null, 'errors_after' => null, 'pages_changed' => null, 'upgrade' => null, 'build' => null, 'id' => null];
        $finish = static function (string $result, int $code) use (&$report, $output, $json): int {
            $report['result'] = $result;
            if ($json) {
                $output->writeln((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            }

            return $code;
        };

        $problem = self::preflight($theme, $base, (string) $input->getOption('composer'));
        if ($problem !== null) {
            $say("<error>{$problem}</error>");

            return $finish('refused: ' . $problem, 2);
        }

        $say('Checking the site before updating …');
        $report['errors_before'] = self::doctor_errors($theme);
        if ($report['errors_before'] === null) {
            $say('<error>doctor --wp couldn\'t run: fix that first (an update couldn\'t be checked).</error>');

            return $finish('refused: doctor --wp could not run', 2);
        }
        if ($report['errors_before'] > 0 && !$input->getOption('force')) {
            $say("<error>doctor --wp has {$report['errors_before']} errors before the update: fix them first, or --force.</error>");

            return $finish('refused: errors before', 2);
        }
        $id = date('Ymd-His');
        $report['id'] = $id;
        $dir = "{$base}/{$id}";
        $render = !$input->getOption('no-render');
        if ($render) {
            self::gaffer($theme, ['render:snapshot', "gaffer-update-{$id}"], 1800);
        }

        $say('Updating Gaffer in a staging copy …');
        try {
            $root = Swap::stage($theme, $dir);
            $composer = ChildProcess::run([(string) $input->getOption('composer'), 'update', '-W', 'onstage2426/gaffer', '--no-interaction', '--no-scripts', '--no-progress'], $root, 900);
            if ($composer === null || $composer['code'] !== 0) {
                Swap::clean_stage($root);
                $say('<error>composer failed:</error> ' . trim(($composer['stderr'] ?? '') . "\n" . ($composer['stdout'] ?? '')));

                return $finish('failed: composer', 2);
            }
            $problem = Swap::problem($theme, $root);
            if ($problem !== null) {
                Swap::clean_stage($root);
                $say("<error>{$problem}</error> Nothing was changed.");

                return $finish('failed: ' . $problem, 2);
            }
            $report['to'] = self::reference($root);
            if ($report['to'] === $report['from']) {
                Swap::clean_stage($root);
                ChildProcess::run(['rm', '-rf', $dir], null, 120);
                $say("Gaffer is already at {$report['from']}.");

                return $finish('up to date', self::SUCCESS);
            }
            Swap::keep_before($theme, $dir);
            Swap::swap($theme, $dir, $root);
            Swap::clean_stage($root);
        } catch (Throwable $e) {
            $say('<error>' . $e->getMessage() . '</error>');

            return $finish('failed: ' . $e->getMessage(), 2);
        }
        $say("Gaffer {$report['from']} → {$report['to']} swapped in.");

        self::gaffer($theme, ['ai:update'], 300);
        $upgrade = self::gaffer($theme, ['upgrade', '--write'], 1200);
        $report['upgrade'] = trim((string) preg_replace('/\s+/', ' ', substr($upgrade['stdout'] ?? '', -300)));
        if (preg_match('/upgrade --restore=(\S+?)\)?\.?$/m', $upgrade['stdout'] ?? '', $m)) {
            file_put_contents("{$dir}/upgrade-backup", $m[1]);
        }
        $report['build'] = self::build($theme);
        $say('Build: ' . $report['build']);

        $report['errors_after'] = self::doctor_errors($theme);
        if ($render) {
            $diff = self::gaffer($theme, ['render:diff', "gaffer-update-{$id}", '--json', '--hide-reorders'], 1800);
            $data = json_decode($diff['stdout'] ?? '', true);
            $report['pages_changed'] = is_array($data) && is_int($data['different'] ?? null) ? $data['different'] : null;
        }

        if ($report['errors_after'] === null || $report['errors_after'] > $report['errors_before']) {
            $why = $report['errors_after'] === null ? 'doctor --wp no longer runs' : "doctor --wp: {$report['errors_before']} errors before, {$report['errors_after']} after";
            if ($input->getOption('keep')) {
                $say("<error>{$why}.</error> Kept (--keep): undo with php gaffer update --rollback={$id}");

                return $finish('kept with new errors', 1);
            }
            self::rollback($theme, $dir, $say);
            $say("<error>{$why}: the update was rolled back.</error> Run it again with --keep to look at the errors.");

            return $finish('rolled back: ' . $why, 1);
        }

        self::prune($base);
        $say('Done: doctor --wp ' . $report['errors_after'] . ' errors' . ($report['pages_changed'] !== null ? ", {$report['pages_changed']} pages render differently (php gaffer render:diff gaffer-update-{$id})" : '') . ". Undo: php gaffer update --rollback={$id}");

        return $finish('updated', self::SUCCESS);
    }

    private static function preflight(string $theme, string $base, string $composer): ?string
    {
        foreach (['composer.json', 'composer.lock', 'vendor'] as $needed) {
            if (!file_exists("{$theme}/{$needed}")) {
                return "The theme has no {$needed}.";
            }
        }
        if (@stat($theme)['dev'] !== @stat($base)['dev']) {
            return "storage/updates/ is on another file system than the theme (GAFFER_STORAGE?): vendor/ can't be swapped by rename.";
        }
        $version = ChildProcess::run([$composer, '--version', '--no-interaction'], $theme, 60);
        if ($version === null || $version['code'] !== 0) {
            return "{$composer} doesn't run here (--composer=<path>).";
        }
        $size = ChildProcess::run(['du', '-sk', "{$theme}/vendor"], null, 120);
        $kb = (int) ($size['stdout'] ?? 0);
        $free = (int) (disk_free_space($base) ?: 0);
        if ($kb > 0 && $free < $kb * 1024 * 3) {
            return 'Not enough disk space for a staging copy of vendor/ (three times its size).';
        }

        return null;
    }

    /** @param \Closure(string): void $say */
    private static function rollback(string $theme, string $dir, \Closure $say): void
    {
        $backup = is_file("{$dir}/upgrade-backup") ? trim((string) file_get_contents("{$dir}/upgrade-backup")) : '';
        Swap::back($theme, $dir);
        if ($backup !== '') {
            self::gaffer($theme, ['upgrade', "--restore={$backup}"], 300);
        }
        self::gaffer($theme, ['ai:update'], 300);
        $say('vendor/, composer.lock, the build\'s manifest' . ($backup !== '' ? ' and the files upgrade changed' : '') . ' are back.');
    }

    /**
     * @param list<string> $arguments
     * @return array{stdout: string, stderr: string, code: int, timed_out: bool}|null
     */
    private static function gaffer(string $theme, array $arguments, int $timeout): ?array
    {
        return ChildProcess::run([PHP_BINARY, "{$theme}/gaffer", ...$arguments], $theme, $timeout);
    }

    private static function doctor_errors(string $theme): ?int
    {
        $run = self::gaffer($theme, ['doctor', '--wp', '--json'], 1800);
        $json = json_decode($run['stdout'] ?? '', true);

        return is_array($json) && is_int($json['errors'] ?? null) ? $json['errors'] : null;
    }

    /** The theme's own build with its package manager: bun.lock → bun, package-lock.json → npm. */
    private static function build(string $theme): string
    {
        $command = match (true) {
            is_file("{$theme}/bun.lock") || is_file("{$theme}/bun.lockb") => (ChildProcess::run(['bun', '--version'], null, 30)['code'] ?? 1) === 0 ? ['bun', 'run', 'build'] : ['npx', '-y', 'bun@1', 'run', 'build'],
            is_file("{$theme}/package-lock.json") => ['npm', 'run', 'build'],
            default => null,
        };
        if ($command === null) {
            return 'skipped (no bun.lock or package-lock.json)';
        }
        $run = ChildProcess::run($command, $theme, 1200);

        return $run !== null && $run['code'] === 0 ? 'ok (' . implode(' ', $command) . ')' : 'failed (' . implode(' ', $command) . '): ' . substr(trim(($run['stderr'] ?? '') . ' ' . ($run['stdout'] ?? '')), -300);
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
