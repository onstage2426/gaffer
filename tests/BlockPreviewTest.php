<?php

declare(strict_types=1);

namespace Gaffer\Tests;

use Gaffer\Acf;
use Gaffer\BlockPreview;

final class BlockPreviewTest extends TestCase
{
    private string $dir;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/gaffer-preview-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0755, true);
        file_put_contents("{$this->dir}/functions.php", '<?php echo "site:", json_encode(Gaffer\Acf::block_array("x"));');
    }

    public function test_the_editor_shows_a_placeholder_and_the_site_the_block(): void
    {
        $block = ['name' => 'acf/hero', 'title' => 'Hero', 'path' => $this->dir, 'data' => ['field_1' => 'Welcome <b>home</b>', '_title' => 'field_1']];

        $editor = $this->render($block, true);
        self::assertStringContainsString('class="gaffer-block-placeholder"', $editor);
        self::assertStringContainsString('>Hero</strong>', $editor);
        self::assertStringContainsString('>Welcome home</span>', $editor);
        self::assertStringNotContainsString('site:', $editor);

        self::assertSame('site:[]', $this->render($block, false));
    }

    public function test_the_summary_is_the_first_text_typed(): void
    {
        self::assertSame('', BlockPreview::summary([]));
        self::assertSame('Over ons', BlockPreview::summary([
            '_heading' => 'field_abc',
            'image' => '42',
            'items' => [['text' => 'nested']],
            'empty' => '  <p></p> ',
            'heading' => "<h2>Over\n ons</h2>",
            'text' => 'later',
        ]));
        $long = BlockPreview::summary(['text' => str_repeat('woord ', 40)]);
        self::assertSame(120, mb_strlen($long));
        self::assertStringEndsWith('…', $long);
    }

    public function test_a_block_without_a_title_shows_its_name(): void
    {
        self::assertStringContainsString('>acf/x</strong>', BlockPreview::placeholder(['name' => 'acf/x', 'title' => '']));
    }

    /** @param array<string, mixed> $block */
    private function render(array $block, bool $preview): string
    {
        $level = ob_get_level();
        ob_start();
        try {
            Acf::render_block($block, '', $preview);
            return (string) ob_get_clean();
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }
    }
}
