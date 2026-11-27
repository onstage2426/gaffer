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
        self::assertSame('link', Swap::swap($this->theme, $this->dir, $root));
        file_put_contents("{$this->theme}/public/.vite/manifest.json", 'new manifest'); // the build
        self::assertSame('new', file_get_contents("{$this->theme}/vendor/marker"));
        self::assertSame('vendor.20261127-120000', readlink("{$this->theme}/vendor"), 'a relative link to a folder at vendor/\'s depth');
        self::assertSame('old', file_get_contents("{$this->dir}/vendor.old/marker"), 'the first linked update moves the real folder aside');
        self::assertSame('new lock', file_get_contents("{$this->theme}/composer.lock"));

        Swap::back($this->theme, $this->dir);
        self::assertFalse(is_link("{$this->theme}/vendor"));
        self::assertSame('old', file_get_contents("{$this->theme}/vendor/marker"));
        self::assertSame('old lock', file_get_contents("{$this->theme}/composer.lock"));
        self::assertSame('old manifest', file_get_contents("{$this->theme}/public/.vite/manifest.json"));
        self::assertSame('new', file_get_contents("{$this->dir}/vendor.failed/marker"), 'the failed vendor/ is kept to look at');
        self::assertFalse(file_exists("{$this->theme}/vendor.20261127-120000"), 'nothing at the theme root that a Gaffer from before links would read as theme code');
    }

    public function test_a_linked_update_switches_the_link_back_and_old_targets_are_pruned(): void
    {
        // The first linked update.
        Swap::keep_before($this->theme, $this->dir);
        $root = Swap::stage($this->theme, $this->dir);
        file_put_contents("{$root}/vendor/marker", 'one');
        Swap::swap($this->theme, $this->dir, $root);

        // A second one, staged from the linked vendor/.
        $second = "{$this->theme}/storage/updates/20261128-120000";
        mkdir($second, 0755, true);
        Swap::keep_before($this->theme, $second);
        $root = Swap::stage($this->theme, $second);
        self::assertFalse(is_link("{$root}/vendor"), 'the staged vendor/ is a folder, copied through the link');
        self::assertSame('one', file_get_contents("{$root}/vendor/marker"));
        self::assertFalse(file_exists("{$root}/vendor.20261127-120000"), 'vendor targets aren\'t linked into the stage');
        file_put_contents("{$root}/vendor/marker", 'two');
        self::assertSame('link', Swap::swap($this->theme, $second, $root));
        self::assertSame('vendor.20261128-120000', readlink("{$this->theme}/vendor"));
        self::assertSame('two', file_get_contents("{$this->theme}/vendor/marker"));

        Swap::back($this->theme, $second);
        self::assertSame('vendor.20261127-120000', readlink("{$this->theme}/vendor"), 'back is the link pointed back');
        self::assertSame('one', file_get_contents("{$this->theme}/vendor/marker"));

        // Only the second update is kept: its own and previous targets stay, nothing else exists.
        self::assertSame([], Swap::prune_targets($this->theme, [$second]));
        mkdir("{$this->theme}/vendor.20261101-120000");
        self::assertSame(['vendor.20261101-120000'], Swap::prune_targets($this->theme, [$second]));
        self::assertSame(['vendor.20261128-120000'], Swap::prune_targets($this->theme, []), 'the current target always stays');
        self::assertTrue(is_dir("{$this->theme}/vendor.20261127-120000"));
    }

    public function test_an_absolute_path_in_the_autoloader_stops_the_swap(): void
    {
        $root = Swap::stage($this->theme, $this->dir);
        file_put_contents("{$root}/vendor/composer/autoload_classmap.php", "<?php return ['Theme\\\\X' => '{$this->theme}/app/X.php'];");

        self::assertStringContainsString('autoload_classmap.php names', (string) Swap::problem($this->theme, $root));
    }
}
