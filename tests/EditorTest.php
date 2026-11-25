<?php

declare(strict_types=1);

namespace Gaffer\Tests;

use Gaffer\Acf;
use Gaffer\Config;
use Gaffer\Editor;

final class EditorTest extends TestCase
{
    private string $dir;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/gaffer-editor-' . bin2hex(random_bytes(4));
        mkdir("{$this->dir}/config", 0755, true);
        $this->previews(true);
    }

    #[\Override]
    protected function tearDown(): void
    {
        self::reset(Config::class, 'config', []);
        parent::tearDown();
    }

    private function previews(bool $on): void
    {
        file_put_contents("{$this->dir}/config/theme.php", '<?php return ' . var_export(['block_previews' => $on], true) . ';');
        Config::load("{$this->dir}/config");
    }

    public function test_the_admin_styles_leave_the_canvas(): void
    {
        $html = "<link rel='stylesheet' id='wp-theme-css' href='a.css' media='all' />\n"
            . "<link rel='stylesheet' id='common-css' href='common.min.css' media='all' />\n"
            . "<link rel='stylesheet' id='forms-css' href='forms.min.css' media='all' />\n"
            . "<link rel='stylesheet' id='wp-reset-editor-styles-css' href='reset.min.css' media='all' />\n"
            . "<link rel='stylesheet' id='gforms_formsmain_css-css' href='gf.css' media='all' />\n"
            . "<style id='common-inline-css'>p{}</style>\n";

        self::assertSame(
            "<link rel='stylesheet' id='wp-theme-css' href='a.css' media='all' />\n"
            . "<link rel='stylesheet' id='gforms_formsmain_css-css' href='gf.css' media='all' />\n"
            . "<style id='common-inline-css'>p{}</style>\n",
            Editor::without_admin_styles($html),
        );
    }

    public function test_a_preview_renders_as_a_block(): void
    {
        file_put_contents("{$this->dir}/functions.php", '<?php echo "preview:", json_encode($is_preview), ":", json_encode(Gaffer\Acf::block_array("x"));');

        self::assertSame('preview:true:[]', $this->render(true));
        self::assertSame('preview:false:[]', $this->render(false));
    }

    public function test_previews_are_off_unless_turned_on(): void
    {
        file_put_contents("{$this->dir}/functions.php", '<?php echo "rendered";');
        self::reset(Config::class, 'config', []);
        self::assertFalse(Editor::enabled());
        self::assertSame('', $this->render(true));
        self::assertSame('rendered', $this->render(false));

        $this->previews(false);
        self::assertSame('', $this->render(true));
        $this->previews(true);
        self::assertSame('rendered', $this->render(true));
    }

    public function test_a_failing_preview_shows_its_error_in_the_block(): void
    {
        file_put_contents("{$this->dir}/functions.php", '<?php ob_start(); echo "half"; throw new RuntimeException("no <title>");');

        $html = $this->render(true);
        self::assertStringContainsString('class="' . Acf::PREVIEW_ERROR . '"', $html);
        self::assertStringContainsString('acf/x: no &lt;title&gt; (functions.php:1)', $html);
        self::assertStringNotContainsString('half', $html);

        $this->expectException(\RuntimeException::class); // on the site it still throws
        $this->render(false);
    }

    private function render(bool $preview): string
    {
        $level = ob_get_level();
        ob_start();
        $log = ini_set('error_log', '/dev/null');
        try {
            Acf::render_block(['name' => 'acf/x', 'path' => $this->dir], '', $preview);
            return (string) ob_get_clean();
        } finally {
            ini_set('error_log', (string) $log);
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }
    }
}
