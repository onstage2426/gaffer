<?php

declare(strict_types=1);

namespace Gaffer\Tests;

use Gaffer\Twig\Cache;
use Gaffer\TwigCache;
use Gaffer\Twig\Loader;
use Twig\Environment;

final class TwigCacheTest extends TestCase
{
    private string $dir;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/gaffer-twig-cache-' . getmypid() . '-' . bin2hex(random_bytes(3));
        mkdir("{$this->dir}/views", 0755, true);
    }

    #[\Override]
    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
        parent::tearDown();
    }

    public function test_a_template_put_back_with_an_older_time_compiles_again(): void
    {
        $file = "{$this->dir}/views/a.twig";
        file_put_contents($file, 'new');
        $env = new Environment(new Loader("{$this->dir}/views"), ['cache' => new Cache("{$this->dir}/cache", [], true), 'auto_reload' => true]);
        self::assertSame('new', $env->render('a.twig'));

        // A rollback: the old content with an older modification time than the compiled file.
        file_put_contents($file, 'old');
        touch($file, time() - 3600);
        $env = new Environment(new Loader("{$this->dir}/views"), ['cache' => new Cache("{$this->dir}/cache", [], true), 'auto_reload' => true]);

        self::assertSame('old', $env->render('a.twig'), 'Twig alone keeps the newer compiled file');
    }

    public function test_a_cache_it_cant_write_renders_without_it(): void
    {
        file_put_contents("{$this->dir}/views/a.twig", 'fine');
        file_put_contents("{$this->dir}/cache", 'a file where the folder should be');
        $env = new Environment(new Loader("{$this->dir}/views"), ['cache' => new Cache("{$this->dir}/cache", [], true), 'auto_reload' => true]);

        self::assertSame('fine', $env->render('a.twig'));
    }

    public function test_a_new_version_folder_removes_the_previous_ones(): void
    {
        mkdir("{$this->dir}/cache/old-version/ab", 0755, true);
        file_put_contents("{$this->dir}/cache/old-version/ab/x.php", '<?php');
        file_put_contents("{$this->dir}/views/a.twig", 'x');
        $env = new Environment(new Loader("{$this->dir}/views"), ['cache' => new Cache("{$this->dir}/cache", [], true), 'auto_reload' => true]);
        $env->render('a.twig');

        $left = array_values(array_diff((array) scandir("{$this->dir}/cache"), ['.', '..']));
        self::assertCount(1, $left);
        self::assertNotSame('old-version', $left[0]);
    }

    public function test_a_changed_extension_file_gets_its_own_folder(): void
    {
        $file = "{$this->dir}/Extension.php";
        file_put_contents($file, '<?php // price()');
        $before = new Cache("{$this->dir}/cache", [$file])->folder();
        self::assertSame($before, new Cache("{$this->dir}/cache", [$file])->folder());

        // A renamed method: templates compiled before would still call the old one.
        file_put_contents($file, '<?php // format_price()');
        self::assertNotSame($before, new Cache("{$this->dir}/cache", [$file])->folder());

        self::assertNotSame(new Cache("{$this->dir}/cache")->folder(), new Cache("{$this->dir}/cache", [$file])->folder());
    }

    public function test_the_cli_reads_the_cache_but_never_writes_it(): void
    {
        mkdir("{$this->dir}/cache/web-version", 0755, true);
        file_put_contents("{$this->dir}/views/a.twig", 'x');
        $env = new Environment(new Loader("{$this->dir}/views"), ['cache' => new Cache("{$this->dir}/cache", [], false), 'auto_reload' => true]);

        self::assertSame('x', $env->render('a.twig'));
        self::assertSame(['web-version'], array_values(array_diff((array) scandir("{$this->dir}/cache"), ['.', '..'])), 'nothing written, nothing removed');
    }

    public function test_writable_looks_at_every_folder_in_it(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('root writes everywhere');
        }
        self::assertTrue(TwigCache::writable("{$this->dir}/cache/v1"), 'not there yet: the folder above counts');
        mkdir("{$this->dir}/cache/v1/ab", 0755, true);
        self::assertTrue(TwigCache::writable("{$this->dir}/cache/v1"));

        chmod("{$this->dir}/cache/v1/ab", 0555); // created by another user
        self::assertFalse(TwigCache::writable("{$this->dir}/cache/v1"));
        chmod("{$this->dir}/cache/v1/ab", 0755);
    }
}
