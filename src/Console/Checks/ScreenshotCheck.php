<?php

declare(strict_types=1);

namespace Gaffer\Console\Checks;

use Gaffer\Console\Report;
use Gaffer\Paths;

/**
 * Whether render:screenshot can run: Playwright installed in the theme. Only that: starting
 * the browser takes seconds, and render:screenshot says why when it can't.
 */
final class ScreenshotCheck implements Check
{
    #[\Override]
    public function run(Report $report): void
    {
        if (!is_file(Paths::base('package.json')) || is_dir(Paths::base('node_modules/playwright'))) {
            return;
        }
        $report->info('screenshot', 'render:screenshot is off: Playwright isn\'t installed in the theme', null, null,
            'Run `npm install --save-dev playwright` and `npx playwright install chromium` (or set PLAYWRIGHT_WS_ENDPOINT in .env to a Playwright server).');
    }
}
