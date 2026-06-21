<?php

namespace Gaffer\Console\Commands;

use Gaffer\Support\Facades\Config;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: "make:block")]
class MakeBlock extends Command
{
    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $blockName = $input->getArgument("name");

        if (!ctype_alpha((string) $blockName)) {
            $output->writeln(
                "<error>Error</error> Invalid block name, only use alphabetic letters [A-Za-z].",
            );
            return 1;
        }

        $blockDir = Config::get("path.blocks") . "/$blockName";

        if (is_dir($blockDir)) {
            $output->writeln(
                "<error>Error</error> Block directory '$blockDir' already exists.",
            );
            return 1;
        } elseif (!mkdir($blockDir)) {
            $output->writeln(
                "<error>Error</error> Could not create directory: $blockDir.",
            );
            return 1;
        } else {
            $output->writeln(
                "<info>Info</info> Created directory: $blockDir",
                OutputInterface::VERBOSITY_VERBOSE,
            );
        }

        $blockNameLower = strtolower((string) $blockName);
        $blockTitle = ucfirst(
            (string) preg_replace("/(?<!^)([A-Z])/", ' $0', (string) $blockName),
        );

        $jsonFile = <<<JSON
        {
            "name": "acf/$blockNameLower",
            "title": "$blockTitle",
            "description": "Description for $blockTitle",
            "category": "bsmedia",
            "icon": "align-full-width",
            "acf": {
                "blockVersion": 3,
                "renderTemplate": "functions.php"
            }
        }
        JSON;

        if (file_put_contents("$blockDir/block.json", $jsonFile)) {
            $output->writeln(
                "<info>Info</info> Created file: $blockDir/block.json",
                OutputInterface::VERBOSITY_VERBOSE,
            );
        } else {
            $output->writeln(
                "<error>Error</error> Could not create file: $blockDir/block.json",
            );
            return 1;
        }

        $phpFile = <<<PHP
        <?php

        use Gaffer\Support\Facades\Theme;

        if (is_admin()) return;

        Theme::render("@block/{$blockName}/{$blockName}.twig");

        PHP;

        if (file_put_contents("$blockDir/functions.php", $phpFile)) {
            $output->writeln(
                "<info>Info</info> Created file: $blockDir/$blockName.php",
                OutputInterface::VERBOSITY_VERBOSE,
            );
        } else {
            $output->writeln(
                "<error>Error</error> Could not create file: $blockDir/$blockName.php",
            );
            return 1;
        }

        $twigFile = <<<TWIG
        <div></div>
        TWIG;

        if (file_put_contents("$blockDir/$blockName.twig", $twigFile)) {
            $output->writeln(
                "<info>Info</info> Created file: $blockDir/$blockName.twig",
                OutputInterface::VERBOSITY_VERBOSE,
            );
        } else {
            $output->writeln(
                "<error>Error</error> Could not create file: $blockDir/$blockName.twig",
            );
            return 1;
        }

        $output->writeln(
            "<info>Info</info> Block '$blockName' created successfully.",
            OutputInterface::VERBOSITY_NORMAL,
        );

        return 0;
    }

    protected function configure(): void
    {
        $this->setDescription("Creates a block template.")
            ->setHelp(
                'This command creates a block template. Start the block name with "slider" to also create a script with a SliderJS template.',
            )
            ->addArgument(
                "name",
                InputArgument::REQUIRED,
                "The name of the block you want to create",
            );
    }
}
