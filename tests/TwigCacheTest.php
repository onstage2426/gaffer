<?php

declare(strict_types=1);

namespace Gaffer\Tests;

use Gaffer\Twig\Cache;
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
        $env = new Environment(new Loader("{$this->dir}/views"), ['cache' => new Cache("{$this->dir}/cache"), 'auto_reload' => true]);
        self::assertSame('new', $env->render('a.twig'));

        // A rollback: the old content with an older modification time than the compiled file.
        file_put_contents($file, 'old');
        touch($file, time() - 3600);
        $env = new Environment(new Loader("{$this->dir}/views"), ['cache' => new Cache("{$this->dir}/cache"), 'auto_reload' => true]);

        self::assertSame('old', $env->render('a.twig'), 'Twig alone keeps the newer compiled file');
    }

    public function test_a_cache_it_cant_write_renders_without_it(): void
    {
        file_put_contents("{$this->dir}/views/a.twig", 'fine');
        file_put_contents("{$this->dir}/cache", 'a file where the folder should be');
        $env = new Environment(new Loader("{$this->dir}/views"), ['cache' => new Cache("{$this->dir}/cache"), 'auto_reload' => true]);

        self::assertSame('fine', $env->render('a.twig'));
    }

    public function test_a_new_version_folder_removes_the_previous_ones(): void
    {
        mkdir("{$this->dir}/cache/old-version/ab", 0755, true);
        file_put_contents("{$this->dir}/cache/old-version/ab/x.php", '<?php');
        file_put_contents("{$this->dir}/views/a.twig", 'x');
        $env = new Environment(new Loader("{$this->dir}/views"), ['cache' => new Cache("{$this->dir}/cache"), 'auto_reload' => true]);
        $env->render('a.twig');

        $left = array_values(array_diff((array) scandir("{$this->dir}/cache"), ['.', '..']));
        self::assertCount(1, $left);
        self::assertNotSame('old-version', $left[0]);
    }
}
