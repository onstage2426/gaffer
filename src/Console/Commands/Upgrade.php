<?php

declare(strict_types=1);

namespace Gaffer\Console\Commands;

use Gaffer\Console\ChildProcess;
use Gaffer\Console\Command;
use Gaffer\Console\Upgrade\Change;
use Gaffer\Console\Upgrade\Theme;
use Gaffer\Console\Upgrade\Upgrader;
use Gaffer\Paths;
use Gaffer\Storage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Applies the mechanical part of Gaffer's CHANGELOG entries to the theme. Without WordPress: it
 * only reads and writes the theme's files.
 */
#[AsCommand('upgrade', 'Apply the mechanical CHANGELOG changes to this theme: a dry run with the diff, --write to apply (backed up, checked, put back on new errors)')]
final class Upgrade extends Command
{
    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('write', null, InputOption::VALUE_NONE, 'Apply the changes (a backup first; twig:lint and doctor after: new errors put everything back)')
            ->addOption('rule', null, InputOption::VALUE_REQUIRED, 'Only this rule (its id, e.g. 2026-11-23-file-inputs)')
            ->addOption('no-check', null, InputOption::VALUE_NONE, 'Skip twig:lint and doctor after writing')
            ->addOption('restore', null, InputOption::VALUE_REQUIRED, 'Put back an earlier --write from its backup folder (printed by that run)');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $theme = new Theme(rtrim(Paths::base(), '/'));
        $restore = $input->getOption('restore');
        if (is_string($restore)) {
            Upgrader::restore($theme, $restore);
            $output->writeln("Restored from {$restore}.");

            return self::SUCCESS;
        }

        $only = $input->getOption('rule');
        $plan = Upgrader::plan($theme, is_string($only) ? $only : null);
        if ($plan === []) {
            $output->writeln('Nothing to upgrade.');

            return self::SUCCESS;
        }

        $changes = [];
        foreach ($plan as $id => $item) {
            $output->writeln("<info>{$id}</info>: " . $item['rule']->describe());
            foreach ($item['changes'] as $change) {
                $output->writeln(self::diff($change));
                $changes[] = $change;
            }
            foreach ($item['by_hand'] as $todo) {
                $output->writeln("  <comment>By hand:</comment> {$todo}");
            }
        }
        if (!$input->getOption('write') || $changes === []) {
            $output->writeln($changes === [] ? 'Nothing to change automatically.' : 'Dry run: nothing was written. Apply with --write.');

            return $changes === [] ? self::SUCCESS : self::FAILURE;
        }

        $check = !$input->getOption('no-check');
        $before = $check ? self::errors($theme) : 0;
        $backup = Storage::theme_dir('upgrades') . '/' . date('Ymd-His');
        Upgrader::apply($theme, $changes, $backup);
        if (array_filter($changes, static fn(Change $c): bool => str_starts_with($c->path, 'app/') && ($c->before === null || $c->after === null)) !== []) {
            ChildProcess::run(['composer', 'dump-autoload', '-q'], $theme->root, 120); // a classmap lists the files
        }
        $after = $check ? self::errors($theme) : 0;
        if ($after > $before) {
            Upgrader::restore($theme, $backup);
            $output->writeln("<error>The upgrade made new errors ({$before} before, {$after} after): everything was put back.</error> Run it again without --write and fix what twig:lint and doctor report.");

            return 2;
        }
        $output->writeln('Written (' . count($changes) . " files). Backup: {$backup} (undo: php gaffer upgrade --restore={$backup}).");

        return self::SUCCESS;
    }

    /** twig:lint and doctor errors, from their JSON (the theme's own commands, so the theme's code is what runs). */
    private static function errors(Theme $theme): int
    {
        $errors = 0;
        foreach ([['twig:lint', '--json'], ['doctor', '--json']] as $command) {
            $run = ChildProcess::run([PHP_BINARY, "{$theme->root}/gaffer", ...$command], $theme->root, 600);
            $json = json_decode($run['stdout'] ?? '', true);
            $errors += is_array($json) && is_int($json['errors'] ?? null) ? $json['errors'] : 1000; // couldn't run: count as broken
        }

        return $errors;
    }

    private static function diff(Change $change): string
    {
        if ($change->before === null) {
            return "  <comment>new</comment> {$change->path}";
        }
        if ($change->after === null) {
            return "  <comment>delete</comment> {$change->path}";
        }
        $dir = sys_get_temp_dir() . '/gaffer-upgrade-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents("{$dir}/a", $change->before);
        file_put_contents("{$dir}/b", $change->after);
        $run = ChildProcess::run(['diff', '-u', '--label', "a/{$change->path}", '--label', "b/{$change->path}", "{$dir}/a", "{$dir}/b"], null, 30);
        @unlink("{$dir}/a");
        @unlink("{$dir}/b");
        @rmdir($dir);

        return $run !== null && $run['stdout'] !== '' ? rtrim($run['stdout']) : "  <comment>change</comment> {$change->path}";
    }
}
