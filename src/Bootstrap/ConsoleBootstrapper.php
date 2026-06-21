<?php

declare(strict_types=1);

namespace Gaffer\Bootstrap;

use Symfony\Component\Console\Application;
use Gaffer\Support\Facades\Config;

class ConsoleBootstrapper implements Bootable
{
    public function boot(): void
    {
        if (defined('WP_CLI') && WP_CLI) {
            return;
        }

        $application = new Application();

        foreach (glob(Config::get("path.console") . "/Commands/*.php") ?: [] as $file) {
            $class_full = "Gaffer\\Console\\Commands\\" . basename($file, ".php");

            if (class_exists($class_full)) {
                $application->addCommand(new $class_full());
            }
        }

        $application->run();
    }
}
