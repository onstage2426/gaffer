<?php

declare(strict_types=1);

namespace Gaffer\Console;

use Gaffer\Console\Commands\AiClear;
use Gaffer\Console\Commands\AiInstall;
use Gaffer\Console\Commands\AiUpdate;
use Gaffer\Console\Commands\AjaxCall;
use Gaffer\Console\Commands\AjaxEnd;
use Gaffer\Console\Commands\ConfigShow;
use Gaffer\Console\Commands\Doctor;
use Gaffer\Console\Commands\FormsProbe;
use Gaffer\Console\Commands\FormsShow;
use Gaffer\Console\Commands\Init;
use Gaffer\Console\Commands\MigrateAdoptFields;
use Gaffer\Console\Commands\MigrateBackups;
use Gaffer\Console\Commands\MigrateRemoveBlock;
use Gaffer\Console\Commands\MigrateRemoveField;
use Gaffer\Console\Commands\MigrateRemoveLeftovers;
use Gaffer\Console\Commands\MigrateRenameBlock;
use Gaffer\Console\Commands\MigrateRenameField;
use Gaffer\Console\Commands\MigrateRollback;
use Gaffer\Console\Commands\MigrateSet;
use Gaffer\Console\Commands\RenderCompare;
use Gaffer\Console\Commands\RenderDiff;
use Gaffer\Console\Commands\RenderPage;
use Gaffer\Console\Commands\RenderScreenshot;
use Gaffer\Console\Commands\RenderSnapshot;
use Gaffer\Console\Commands\RenderStyles;
use Gaffer\Console\Commands\TwigClear;
use Gaffer\Console\Commands\TwigTrace;
use Gaffer\Console\Commands\TwigLint;
use Gaffer\Gaffer;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\InputOption;

/**
 * The theme's CLI (`php gaffer`).
 */
final class Console
{
    /**
     * Entry point for the theme's `gaffer` file. Returns the process exit code.
     */
    public static function boot(string $dir): int
    {
        Gaffer::configure($dir);

        $app = new Application('Gaffer', Gaffer::version());
        $app->getDefinition()->addOption(new InputOption(
            'url',
            null,
            InputOption::VALUE_REQUIRED,
            'Site URL for commands that load WordPress (default: SITE_URL in the theme\'s .env)',
        ));
        $app->addCommands([
            new AiClear(),
            new AiInstall(),
            new AiUpdate(),
            new AjaxCall(),
            new AjaxEnd(),
            new ConfigShow(),
            new Doctor(),
            new FormsShow(),
            new FormsProbe(),
            new Init(),
            new MigrateAdoptFields(),
            new MigrateBackups(),
            new MigrateRemoveBlock(),
            new MigrateRemoveField(),
            new MigrateRemoveLeftovers(),
            new MigrateRenameBlock(),
            new MigrateRenameField(),
            new MigrateRollback(),
            new MigrateSet(),
            new RenderCompare(),
            new RenderDiff(),
            new RenderPage(),
            new RenderScreenshot(),
            new RenderSnapshot(),
            new RenderStyles(),
            new TwigClear(),
            new TwigLint(),
            new TwigTrace(),
        ]);

        return $app->run();
    }
}
