<?php

namespace Gaffer\Console\Commands;

use Gaffer\Support\Facades\Config;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: "make:shortcode")]
class MakeShortcode extends Command
{
    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $shortcodeName = $input->getArgument("name");
        $shortcodesDir = Config::get("path.shortcodes");

        if (!is_dir($shortcodesDir)) {
            if (mkdir($shortcodesDir)) {
                $output->writeln(
                    "<info>Info</info> Created directory: $shortcodesDir",
                    OutputInterface::VERBOSITY_VERBOSE,
                );
            } else {
                $output->writeln(
                    "<error>Error</error> Could not create directory: $shortcodesDir.",
                );
                return 1;
            }
        }

        $phpFile = <<<PHP
        <?php

        if ( ! shortcode_exists('{$shortcodeName}') ) {
            add_shortcode(
                '{$shortcodeName}',
                function ( array \$atts, string \$content = '' ): mixed {
                    \$atts = shortcode_atts( [], \$atts, '{$shortcodeName}' );
                    //
                }
            );
        } else {
            wp_die( "Shortcode {$shortcodeName} already exists. Check <code>inc/shortcodes/{$shortcodeName}.php</code> in your theme." );
        }

        PHP;

        if (file_put_contents("$shortcodesDir/$shortcodeName.php", $phpFile)) {
            $output->writeln(
                "<info>Info</info> Created file: $shortcodesDir/$shortcodeName.php",
                OutputInterface::VERBOSITY_VERBOSE,
            );
        } else {
            $output->writeln(
                "<error>Error</error> Could not create file: $shortcodesDir/$shortcodeName.php",
            );
            return 1;
        }

        $output->writeln(
            "<info>Info</info> Shortcode '$shortcodeName' created successfully.",
            OutputInterface::VERBOSITY_NORMAL,
        );

        return 0;
    }

    protected function configure(): void
    {
        $this->setDescription("Creates a shortcode template.")
            ->setHelp("This command creates a shortcode template.")
            ->addArgument(
                "name",
                InputArgument::REQUIRED,
                "The name of the shortcode you want to create",
            );
    }
}
