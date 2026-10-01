<?php

declare(strict_types=1);

namespace Gaffer\Console\Commands;

use FilesystemIterator;
use Gaffer\Console\Command;
use Gaffer\Paths;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('twig:clear', 'Delete the compiled Twig template cache (nothing else)')]
final class TwigClear extends Command
{
    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dir = Paths::twig_cache();

        if (!is_dir($dir)) {
            $output->writeln('No Twig cache to clear.');
            return self::SUCCESS;
        }

        $files = 0;
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
                $files++;
            }
        }

        $output->writeln("Cleared {$files} compiled templates from " . substr($dir, strlen(Paths::base()) + 1) . '.');

        return self::SUCCESS;
    }
}
