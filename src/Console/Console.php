<?php

declare(strict_types=1);

namespace Gaffer\Console;

use Gaffer\Console\Commands\AiInstall;
use Gaffer\Console\Commands\AiUpdate;
use Gaffer\Console\Commands\ConfigShow;
use Gaffer\Console\Commands\Doctor;
use Gaffer\Console\Commands\DoctorRender;
use Gaffer\Console\Commands\TwigClear;
use Gaffer\Console\Commands\TwigLint;
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

        $app = new Application('Gaffer', Gaffer::version());
        $app->getDefinition()->addOption(new InputOption(
            'url',
            null,
            InputOption::VALUE_REQUIRED,
            'Site URL for commands that load WordPress (default: console.url)',
        ));
        $app->addCommands([
            new AiInstall(),
            new AiUpdate(),
            new ConfigShow(),
            new Doctor(),
            new DoctorRender(),
            new TwigClear(),
            new TwigLint(),
        ]);

        return $app->run();
    }
}
