<?php

namespace Gaffer\Console\Commands;

use Gaffer\Support\Facades\Config;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: "make:function")]
class MakeFunction extends Command
{
    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $functionName = $input->getArgument("name");
        $functionsDir = Config::get("path.functions");

        if (!is_dir($functionsDir)) {
            if (mkdir($functionsDir)) {
                $output->writeln(
                    "<info>Info</info> Created directory: $functionsDir",
                    OutputInterface::VERBOSITY_VERBOSE,
                );
            } else {
                $output->writeln(
                    "<error>Error</error> Could not create directory: $functionsDir.",
                );
                return 1;
            }
        }

        $phpFile = <<<PHP
        <?php

        if ( ! function_exists('{$functionName}') ) {
            function {$functionName}() {
                //
            }
        } else {
            wp_die( "Function $functionName already exists. Check <code>inc/functions/$functionName.php</code> in your theme." );
        }

        PHP;

        if (file_put_contents("$functionsDir/$functionName.php", $phpFile)) {
            $output->writeln(
                "<info>Info</info> Created file: $functionsDir/$functionName.php",
                OutputInterface::VERBOSITY_VERBOSE,
            );
        } else {
            $output->writeln(
                "<error>Error</error> Could not create file: $functionsDir/$functionName.php",
            );
            return 1;
        }

        $output->writeln(
            "<info>Info</info> Function '$functionName' created successfully.",
            OutputInterface::VERBOSITY_NORMAL,
        );

        return 0;
    }

    protected function configure(): void
    {
        $this->setDescription("Creates a function template.")
            ->setHelp("This command creates a function template.")
            ->addArgument(
                "name",
                InputArgument::REQUIRED,
                "The name of the function you want to create",
            );
    }
}
