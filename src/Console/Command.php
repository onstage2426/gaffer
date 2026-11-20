<?php

declare(strict_types=1);

namespace Gaffer\Console;

use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

abstract class Command extends SymfonyCommand
{
    /** Set to true when the command needs WordPress loaded before it runs. */
    protected bool $wordpress = false;

    #[\Override]
    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        if ($this->wordpress) {
            WordPress::load($input->getOption('url'));
        }
    }
}
