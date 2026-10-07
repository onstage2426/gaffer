<?php

declare(strict_types=1);

namespace Gaffer\Tests;

use Gaffer\Paths;
use Gaffer\Vite;

final class ViteTest extends TestCase
{
    private string $theme;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->theme = sys_get_temp_dir() . '/gaffer-vite-' . bin2hex(random_bytes(4));
        mkdir("{$this->theme}/public/.vite", 0755, true);
        Paths::set_base($this->theme);
        file_put_contents("{$this->theme}/public/.vite/manifest.json", (string) json_encode([
            'assets/js/app.js' => ['file' => 'assets/app-A1.js', 'isEntry' => true, 'imports' => ['_vendor-B2.js'], 'css' => ['assets/app-C3.css']],
            '_vendor-B2.js' => ['file' => 'assets/vendor-B2.js', 'imports' => ['_shared-D4.js'], 'css' => ['assets/vendor-E5.css']],
            '_shared-D4.js' => ['file' => 'assets/shared-D4.js', 'imports' => ['_vendor-B2.js']],
            'assets/css/app.css' => ['file' => 'assets/app-C3.css', 'isEntry' => true],
        ]));
        self::reset(Vite::class, 'manifest', null);
        self::reset(Vite::class, 'dev_mode', null);
        self::reset(Vite::class, 'dev_server', null);
        self::reset(Vite::class, 'printed', []);
        $GLOBALS['gaffer_test_admin'] = false;
    }

    public function test_built_tags(): void
    {
        ob_start();
        Vite::tags(['assets/css/app.css', 'assets/js/app.js', 'assets/js/gone.js']);
        $html = (string) ob_get_clean();

        $base = 'https://site.test/wp-content/themes/t/public/assets';
        self::assertSame(
            "<link rel=\"stylesheet\" href=\"{$base}/app-C3.css\">\n"
            . "<link rel=\"stylesheet\" href=\"{$base}/vendor-E5.css\">\n"
            . "<link rel=\"modulepreload\" href=\"{$base}/vendor-B2.js\">\n"
            . "<link rel=\"modulepreload\" href=\"{$base}/shared-D4.js\">\n"
            . "<script type=\"module\" src=\"{$base}/app-A1.js\"></script>\n",
            $html,
        );
        self::assertNull(Vite::url('assets/js/gone.js'));
    }

    public function test_dev_server_for_administrators(): void
    {
        file_put_contents("{$this->theme}/public/.vite/hotfile", "http://localhost:5173\n");
        $GLOBALS['gaffer_test_admin'] = true;

        ob_start();
        Vite::tags(['assets/css/app.css', 'assets/js/app.js']);
        $html = (string) ob_get_clean();

        self::assertSame(
            "<script type=\"module\" src=\"http://localhost:5173/@vite/client\"></script>\n"
            . "<link rel=\"stylesheet\" href=\"http://localhost:5173/assets/css/app.css\">\n"
            . "<script type=\"module\" src=\"http://localhost:5173/assets/js/app.js\"></script>\n",
            $html,
        );
    }
}
