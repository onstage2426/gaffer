<?php

declare(strict_types=1);

namespace Gaffer\Console\Commands;

use Closure;
use Gaffer\Console\Command;
use Gaffer\Console\Migrate\ContentStore;
use Gaffer\Console\Migrate\Location;
use Gaffer\Console\Migrate\Migration;
use RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Shared by the migrate:* commands: WordPress loaded, dry run unless --run.
 */
abstract class MigrateCommand extends Command
{
    protected bool $wordpress = true;

    #[\Override]
    protected function configure(): void
    {
        $this->addOption('run', null, InputOption::VALUE_NONE, 'Write the changes (default: dry run)');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!function_exists('acf_get_field')) {
            $output->writeln('<error>ACF is not active.</error>');
            return self::FAILURE;
        }

        $arguments = array_filter($input->getArguments(), static fn(mixed $value, string $name): bool => $name !== 'command' && is_string($value), ARRAY_FILTER_USE_BOTH);
        $migration = new Migration(trim($this->getName() . ' ' . implode(' ', $arguments)));
        try {
            $this->plan($migration, $input);
        } catch (RuntimeException $e) {
            $migration->problem($e->getMessage());
        }

        return $migration->finish($output, (bool) $input->getOption('run'));
    }

    abstract protected function plan(Migration $migration, InputInterface $input): void;

    /**
     * Rewrites every location that has one of these blocks, once, giving each block to the
     * handler of its name (see Migration::rewrite()). A page with two of the blocks is one
     * change: two would be planned from the same content and the second would abort the run.
     *
     * @param array<string, Closure(array<string, mixed>): array{array<string, mixed>, int, list<string>}> $handlers block name => handler
     */
    protected static function rewrite_blocks(Migration $migration, array $handlers): void
    {
        $locations = [];
        foreach (array_keys($handlers) as $block) {
            foreach (ContentStore::find("<!-- wp:{$block} ") as $location) {
                $locations["{$location->kind}:{$location->id}"] = $location;
            }
        }
        usort($locations, static fn(Location $a, Location $b): int => [$a->kind, $a->id] <=> [$b->kind, $b->id]);

        foreach ($locations as $location) {
            $migration->rewrite($location, static function (array $block) use ($handlers): array {
                $handler = is_string($block['blockName'] ?? null) ? ($handlers[$block['blockName']] ?? null) : null;

                return $handler !== null ? $handler($block) : [$block, 0, []];
            });
        }
    }
}
