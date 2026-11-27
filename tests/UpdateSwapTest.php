<?php

declare(strict_types=1);

namespace Gaffer\Tests;

use Gaffer\Console\Update\Swap;
use PHPUnit\Framework\TestCase as BaseTestCase;

final class UpdateSwapTest extends BaseTestCase
{
    private string $theme;
    private string $dir;

    protected function setUp(): void
    {
        $this->theme = sys_get_temp_dir() . '/gaffer-swap-' . bin2hex(random_bytes(4));
        $this->dir = "{$this->theme}/storage/updates/20261127-120000";
        mkdir("{$this->theme}/vendor/composer", 0755, true);
        mkdir("{$this->theme}/app", 0755, true);
        mkdir("{$this->theme}/public/.vite", 0755, true);
        mkdir($this->dir, 0755, true);
        file_put_contents("{$this->theme}/composer.json", '{"autoload": {"psr-4": {"Theme\\\\": "app/"}}}');
        file_put_contents("{$this->theme}/composer.lock", 'old lock');
        file_put_contents("{$this->theme}/vendor/composer/autoload_psr4.php", "<?php \$baseDir = dirname(dirname(__DIR__)); return ['Theme\\\\' => [\$baseDir . '/app']];");
        file_put_contents("{$this->theme}/vendor/marker", 'old');
        file_put_contents("{$this->theme}/public/.vite/manifest.json", 'old manifest');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->theme));
    }

    public function test_stage_swap_and_back(): void
    {
        $root = Swap::stage($this->theme, $this->dir);

        self::assertSame('old', file_get_contents("{$root}/vendor/marker"), 'vendor/ is a copy');
        self::assertTrue(is_link("{$root}/app") && readlink("{$root}/app") === "{$this->theme}/app", 'the rest is linked');
        self::assertFalse(file_exists("{$root}/storage"), 'storage/ isn\'t linked (the staging folder is in it)');

        // What composer does in the staging copy.
        file_put_contents("{$root}/vendor/marker", 'new');
        file_put_contents("{$root}/composer.lock", 'new lock');
        self::assertSame('old', file_get_contents("{$this->theme}/vendor/marker"), 'the running vendor/ is untouched while composer works');
        self::assertNull(Swap::problem($this->theme, $root));

        Swap::keep_before($this->theme, $this->dir);
        Swap::swap($this->theme, $this->dir, $root);
        file_put_contents("{$this->theme}/public/.vite/manifest.json", 'new manifest'); // the build
        self::assertSame('new', file_get_contents("{$this->theme}/vendor/marker"));
        self::assertSame('new lock', file_get_contents("{$this->theme}/composer.lock"));

        Swap::back($this->theme, $this->dir);
        self::assertSame('old', file_get_contents("{$this->theme}/vendor/marker"));
        self::assertSame('old lock', file_get_contents("{$this->theme}/composer.lock"));
        self::assertSame('old manifest', file_get_contents("{$this->theme}/public/.vite/manifest.json"));
        self::assertSame('new', file_get_contents("{$this->dir}/vendor.failed/marker"), 'the failed vendor/ is kept to look at');
    }

    public function test_an_absolute_path_in_the_autoloader_stops_the_swap(): void
    {
        $root = Swap::stage($this->theme, $this->dir);
        file_put_contents("{$root}/vendor/composer/autoload_classmap.php", "<?php return ['Theme\\\\X' => '{$this->theme}/app/X.php'];");

        self::assertStringContainsString('autoload_classmap.php names', (string) Swap::problem($this->theme, $root));
    }
}
