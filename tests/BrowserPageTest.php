<?php

declare(strict_types=1);

namespace Gaffer\Tests;

use Gaffer\Console\Env;
use Gaffer\Console\Render\BrowserPage;
use Gaffer\Paths;

final class BrowserPageTest extends TestCase
{
    private string $theme;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->theme = sys_get_temp_dir() . '/gaffer-browser-' . bin2hex(random_bytes(4));
        mkdir($this->theme);
        Paths::set_base($this->theme);
        self::reset(Env::class, 'values', null);
    }

    #[\Override]
    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->theme));
        self::reset(Env::class, 'values', null);
    }

    public function test_design_cookies_from_env(): void
    {
        self::assertSame([], BrowserPage::design_cookies('https://design.test/home.html'));

        file_put_contents("{$this->theme}/.env", "DESIGN_COOKIE=\"pw=abc=1; sess = x\"\n");
        self::reset(Env::class, 'values', null);
        self::assertSame(
            [['name' => 'pw', 'value' => 'abc=1', 'url' => 'https://design.test'], ['name' => 'sess', 'value' => 'x', 'url' => 'https://design.test']],
            BrowserPage::design_cookies('https://design.test/home.html'),
        );
    }

    public function test_targets_that_need_no_render(): void
    {
        file_put_contents("{$this->theme}/design.html", '<p>x</p>');

        self::assertSame(['url' => 'file://' . realpath("{$this->theme}/design.html"), 'cookies' => []], BrowserPage::target("{$this->theme}/design.html"));
        self::assertSame('https://design.test/a', BrowserPage::target('https://design.test/a')['url'] ?? null);
        self::assertIsString(BrowserPage::target('design.html')); // relative to the cwd, not there
    }
}
