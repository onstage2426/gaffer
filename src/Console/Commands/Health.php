<?php

declare(strict_types=1);

namespace Gaffer\Console\Commands;

use Gaffer\AdminBar;
use Gaffer\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The admin bar's health lines for a crontab: a site nobody looks at every day still tells
 * someone. Silent while everything is green, so cron mails only when something isn't.
 */
#[AsCommand('health', 'The admin bar\'s health lines (Gaffer and its plugins): silent when all are green, else each line and exit 1 (a daily crontab with MAILTO)')]
final class Health extends Command
{
    protected bool $wordpress = true;

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $states = AdminBar::states();
        $worst = AdminBar::worst(array_values($states));
        if ($worst['color'] === 'green' && !$output->isVerbose()) {
            return self::SUCCESS;
        }
        foreach ($states as $name => $state) {
            $output->writeln("{$state['color']}\t{$name}: {$state['reason']}");
        }
        if ($worst['color'] !== 'green') {
            $output->writeln('Details: php gaffer doctor --wp' . (isset($states['gaffer-search']) && $states['gaffer-search']['color'] !== 'green' ? ', wp gaffer-search status' : ''));
        }

        return $worst['color'] === 'green' ? self::SUCCESS : self::FAILURE;
    }
}
