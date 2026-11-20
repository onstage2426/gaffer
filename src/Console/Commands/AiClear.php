<?php

declare(strict_types=1);

namespace Gaffer\Console\Commands;

use Gaffer\Ai\Installer;
use Gaffer\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('ai:clear', 'Remove the generated AI guidelines and skills (keeps .ai/, config/ai.php and hand-written content)')]
final class AiClear extends Command
{
    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $removed = Installer::clear();
        if ($removed === []) {
            $output->writeln('No generated AI files to remove.');
            return self::SUCCESS;
        }

        foreach ($removed as $line) {
            $output->writeln("  {$line}");
        }
        $output->writeln('Run `php gaffer ai:update` to write them again.');

        return self::SUCCESS;
    }
}
