<?php

declare(strict_types=1);

namespace Gaffer\Tests;

use Gaffer\Config;
use Gaffer\Gaffer;
use Gaffer\View;
use Gaffer\Console\Checks\BlocksCheck;
use Gaffer\Console\Checks\Check;
use Gaffer\Console\Checks\ConfigCheck;
use Gaffer\Console\Checks\MarkupCheck;
use Gaffer\Console\Checks\TemplatesCheck;
use Gaffer\Console\Report;
use Gaffer\Paths;
use Symfony\Component\Console\Output\BufferedOutput;

final class ChecksTest extends TestCase
{
    private string $theme;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->theme = sys_get_temp_dir() . '/gaffer-checks-' . bin2hex(random_bytes(4));
        mkdir("{$this->theme}/blocks/contentFaq", 0755, true);
        mkdir("{$this->theme}/config", 0755, true);
        Paths::set_base($this->theme);
        Config::load("{$this->theme}/config");
    }

    #[\Override]
    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->theme));
    }

    public function test_composer_must_autoload_app_as_theme(): void
    {
        file_put_contents("{$this->theme}/composer.json", '{"autoload": {"psr-4": {"Theme\\\\Types\\\\": "app/Types"}}}');
        self::assertStringContainsString('doesn\'t autoload', $this->messages(new ConfigCheck()));

        file_put_contents("{$this->theme}/composer.json", '{"autoload": {"psr-4": {"Theme\\\\": "app/"}}}');
        self::assertStringNotContainsString('autoload', $this->messages(new ConfigCheck()));
    }

    public function test_fields_functions_php_never_reads_are_reported(): void
    {
        file_put_contents("{$this->theme}/blocks/contentFaq/block.json", '{"name": "acf/content-faq", "description": "FAQ.", "acf": {"renderTemplate": "functions.php"}}');
        file_put_contents("{$this->theme}/blocks/contentFaq/contentFaq.twig", '');
        file_put_contents("{$this->theme}/blocks/contentFaq/fields.php", "<?php return [
            ['label' => 'Velden', 'type' => 'tab'],
            ['name' => 'titel', 'type' => 'text'],
            ['name' => 'subtitel', 'type' => 'text'],
            ['name' => 'vragen', 'type' => 'repeater', 'sub_fields' => [['name' => 'vraag', 'type' => 'text'], ['name' => 'bron', 'type' => 'text']]],
        ];");
        file_put_contents("{$this->theme}/blocks/contentFaq/functions.php", "<?php\nif (is_admin()) { return; }\n"
            . "View::render('x', ['title' => get_field('titel'), 'q' => array_map(fn(\$r) => \$r[\"vraag\"], Acf::field_array(\"vragen\"))]);\n");

        $messages = $this->messages(new BlocksCheck());

        self::assertStringContainsString('Field "subtitel" is never read', $messages);
        self::assertStringContainsString('Field "bron" is never read', $messages);
        self::assertStringNotContainsString('"titel"', $messages);
        self::assertStringNotContainsString('"vraag"', $messages);
        self::assertStringNotContainsString('"vragen"', $messages);
    }

    public function test_inner_blocks_are_reported(): void
    {
        file_put_contents("{$this->theme}/blocks/contentFaq/block.json", '{"name": "acf/content-faq", "description": "FAQ.", "acf": {"renderTemplate": "functions.php"}}');
        file_put_contents("{$this->theme}/blocks/contentFaq/functions.php", "<?php\nif (is_admin()) { return; }\n");
        file_put_contents("{$this->theme}/blocks/contentFaq/contentFaq.twig", '<div><InnerBlocks /></div>');

        self::assertStringContainsString('Uses <InnerBlocks>', $this->messages(new BlocksCheck()));
    }

    public function test_templates_missing_unused_and_not_isolated(): void
    {
        mkdir("{$this->theme}/views/components", 0755, true);
        file_put_contents("{$this->theme}/views/components/card.twig", '{{ post }}');
        file_put_contents("{$this->theme}/views/components/old.twig", 'unused');
        file_put_contents("{$this->theme}/views/page.twig", "{{ include('components/card.twig', { post: post }, with_context = false) }}\n{{ include('components/card.twig') }}\n{{ include('components/gone.twig', {}, with_context = false) }}");
        file_put_contents("{$this->theme}/page.php", "<?php View::render('page.twig', []);\nView::render(\"missing.twig\");\n");
        self::reset(View::class, 'env', null);
        self::reset(Gaffer::class, 'twig', false);

        $messages = $this->messages(new TemplatesCheck());

        self::assertStringContainsString("Renders missing.twig, which doesn't exist", $messages);
        self::assertStringContainsString("include() loads components/gone.twig, which doesn't exist", $messages);
        self::assertSame(1, substr_count($messages, 'passes all of this template'));
        self::assertSame(1, substr_count($messages, 'Nothing renders or includes this template')); // old.twig
    }

    public function test_html_in_php_outside_the_shell(): void
    {
        file_put_contents("{$this->theme}/header.php", "<?php ?>\n<html><body>");
        file_put_contents("{$this->theme}/page.php", "<?php\n// a <div> in a comment is fine\n\$x = 1;\necho '<div class=\"a\">';\n\$y = <<<HTML\n    <p>x</p>\nHTML;\n\$z = 'a < b';\n");

        $messages = $this->messages(new MarkupCheck());

        self::assertSame('HTML in PHP (line 4, 6)', $messages);
    }

    private function messages(Check $check): string
    {
        $report = new Report();
        $check->run($report);
        $output = new BufferedOutput();
        $report->render($output, true);

        return implode("\n", array_column((array) json_decode($output->fetch(), true), 'message'));
    }
}
