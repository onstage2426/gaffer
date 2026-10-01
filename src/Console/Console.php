<?php

declare(strict_types=1);

namespace Gaffer\Console;

use Composer\InstalledVersions;
use Gaffer\Console\Commands\ConfigShow;
use Gaffer\Console\Commands\TwigClear;
use Gaffer\Gaffer;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\InputOption;

final class Console
{
    /**
     * Entry point for the theme's `gaffer` file. Returns the process exit code.
     */
    public static function boot(string $dir): int
    {
        if (!class_exists(Application::class)) {
            fwrite(STDERR, "The Gaffer CLI needs symfony/console: composer require --dev symfony/console\n");
            return 1;
        }

        Gaffer::configure($dir);

        $app = new Application('Gaffer', self::version());
        $app->getDefinition()->addOption(new InputOption(
            'url',
            null,
            InputOption::VALUE_REQUIRED,
            'Site URL for commands that load WordPress (default: console.url)',
        ));
        $app->addCommands([
            new ConfigShow(),
            new TwigClear(),
        ]);

        return $app->run();
    }

    private static function version(): string
    {
        if (!class_exists(InstalledVersions::class) || !InstalledVersions::isInstalled('onstage2426/gaffer')) {
            return 'dev';
        }

        $reference = InstalledVersions::getReference('onstage2426/gaffer');

        return InstalledVersions::getPrettyVersion('onstage2426/gaffer') . ($reference ? ' @ ' . substr($reference, 0, 7) : '');
    }
}
