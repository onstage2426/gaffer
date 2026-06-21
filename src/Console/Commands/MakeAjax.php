<?php

namespace Gaffer\Console\Commands;

use Gaffer\Support\Facades\Config;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: "make:ajax")]
class MakeAjax extends Command
{
    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $ajaxName = $input->getArgument("name");
        $ajaxDir = Config::get("path.ajax");

        if (!is_dir($ajaxDir)) {
            if (mkdir($ajaxDir)) {
                $output->writeln(
                    "<info>Info</info> Created directory: $ajaxDir",
                    OutputInterface::VERBOSITY_VERBOSE,
                );
            } else {
                $output->writeln(
                    "<error>Error</error> Could not create directory: $ajaxDir.",
                );
                return 1;
            }
        }

        $phpFile = <<<PHP
        <?php

        namespace Gaffer\Ajax;

        use Gaffer\Support\Facades\Theme;
        use Gaffer\AjaxAction;

        class {$ajaxName} extends AjaxAction {
            public function run( \$request ) {
                //
            }

            public function configure() {
                //
            }
        }


        PHP;

        if (file_put_contents("$ajaxDir/$ajaxName.php", $phpFile)) {
            $output->writeln(
                "<info>Info</info> Created file: $ajaxDir/$ajaxName.php",
                OutputInterface::VERBOSITY_VERBOSE,
            );
        } else {
            $output->writeln(
                "<error>Error</error> Could not create file: $ajaxDir/$ajaxName.php",
            );
            return 1;
        }

        $output->writeln(
            "<info>Info</info> Ajax action '$ajaxName' created successfully.",
            OutputInterface::VERBOSITY_NORMAL,
        );

        return 0;
    }

    protected function configure(): void
    {
        $this->setDescription("Creates a ajax action template.")
            ->setHelp("This command creates a ajax action template.")
            ->addArgument(
                "name",
                InputArgument::REQUIRED,
                "The name of the ajax action you want",
            );
    }
}
