<?php

declare(strict_types=1);

namespace Gaffer\Console\Commands;

use Gaffer\Console\Command;
use Gaffer\Console\Env;
use Gaffer\Console\WordPress;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

#[AsCommand('init', 'Set up this checkout: SITE_URL in .env (checked: it loads the site that runs this theme), then ai:install')]
final class Init extends Command
{
    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $url = $input->getOption('url');
        if (!is_string($url)) {
            /** @var QuestionHelper $helper */
            $helper = $this->getHelper('question');
            $url = $helper->ask($input, $output, new Question('Site URL that uses this theme (e.g. https://example.com/shop/): ', Env::get('SITE_URL')));
        }
        if (!is_string($url) || parse_url($url, PHP_URL_HOST) === null) {
            $output->writeln('<error>A site URL with a host is required (--url when not interactive).</error>');
            return self::FAILURE;
        }

        WordPress::load($url); // a multisite without a site at $url stops here
        if (!WordPress::is_theme_active()) {
            $output->writeln('<error>' . \home_url('/') . ' runs the theme "' . \get_stylesheet() . '", not this one. Use the URL of the site that runs this theme.</error>');
            return self::FAILURE;
        }

        Env::set('SITE_URL', $url);
        $output->writeln("Saved SITE_URL in .env: {$url}");
        $output->writeln('');

        $install = new ArrayInput([]);
        $install->setInteractive($input->isInteractive());

        return $this->getApplication()?->find('ai:install')->run($install, $output) ?? self::FAILURE;
    }
}
