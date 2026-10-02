<?php

declare(strict_types=1);

namespace Gaffer\Console\Commands;

use Gaffer\Ai\Installer;
use Gaffer\Config;
use Gaffer\Console\Command;
use Gaffer\Console\WordPress;
use Gaffer\Gaffer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('ai:update', "Regenerate the AI guidelines and skills for the agents in config/ai.php")]
final class AiUpdate extends Command
{
    #[\Override]
    protected function configure(): void
    {
        $this->addOption('no-wp', null, InputOption::VALUE_NONE, "Don't load WordPress (skips plugin guidelines such as WooCommerce)");
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $agents = Config::get('ai.agents');
        if (!is_array($agents) || $agents === []) {
            $output->writeln('<error>No agents in config/ai.php. Run `php gaffer ai:install` first.</error>');
            return self::FAILURE;
        }

        return self::generate(array_values($agents), $input, $output);
    }

    /**
     * Shared with ai:install.
     *
     * @param list<string> $agents
     */
    public static function generate(array $agents, InputInterface $input, OutputInterface $output): int
    {
        $plugins = [];
        if ($input->hasOption('no-wp') && $input->getOption('no-wp')) {
            Gaffer::twig();
            $output->writeln('<comment>Skipping WordPress: no plugin guidelines.</comment>');
        } else {
            WordPress::load($input->getOption('url'));
            $plugins = array_keys(array_filter([
                'woocommerce' => function_exists('WC'),
                'acf' => class_exists('ACF'),
            ]));
        }

        foreach (Installer::update($agents, $plugins) as $path) {
            $output->writeln("  wrote {$path}");
        }
        $output->writeln('Plugin guidelines: ' . ($plugins === [] ? 'none' : implode(', ', $plugins)) . '. These files are gitignored; regenerate after updating Gaffer.');

        return self::SUCCESS;
    }
}
