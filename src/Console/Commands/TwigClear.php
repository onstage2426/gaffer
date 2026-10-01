<?php

declare(strict_types=1);

namespace Gaffer\Console\Commands;

use Gaffer\Console\Command;
use Gaffer\Paths;
use Gaffer\TwigCache;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('twig:clear', 'Delete the compiled Twig template cache (nothing else)')]
final class TwigClear extends Command
{
    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!is_dir(Paths::twig_cache())) {
            $output->writeln('No Twig cache to clear.');
            return self::SUCCESS;
        }

        $files = TwigCache::clear();
        $output->writeln("Cleared {$files} compiled templates from " . substr(Paths::twig_cache(), strlen(Paths::base()) + 1) . '.');

        return self::SUCCESS;
    }
}
