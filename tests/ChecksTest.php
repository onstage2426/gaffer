<?php

declare(strict_types=1);

namespace Gaffer\Tests;

use Gaffer\Config;
use Gaffer\Console\Checks\AjaxCheck;
use Gaffer\Console\Checks\AppCheck;
use Gaffer\Console\Checks\AssetsCheck;
use Gaffer\Console\Checks\BlocksCheck;
use Gaffer\Console\Checks\Check;
use Gaffer\Console\Checks\ConfigCheck;
use Gaffer\Console\Checks\GuidelinesCheck;
use Gaffer\Console\Checks\IncCheck;
use Gaffer\Console\Checks\MarkupCheck;
use Gaffer\Console\Checks\TemplatesCheck;
use Gaffer\Console\Checks\WordPressCheck;
use Gaffer\Console\Env;
use Gaffer\Console\Report;
use Gaffer\Gaffer;
use Gaffer\Paths;
use Gaffer\View;
use Gaffer\Vite;
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

    public function test_declarations_in_inc(): void
    {
        $code = <<<'PHP'
            <?php
            use function strlen;
            function bs_helper(): void {}
            if (!function_exists('bs_guarded')) { function bs_guarded(): void {} }
            final class Thing {}
            enum Mode {}
            add_action('init', function (): void {});
            add_filter('x', fn(): string => Foo::class);
            $anon = new class {};
            PHP;

        self::assertSame(
            [['function', 'bs_helper()', 3], ['function', 'bs_guarded()', 4], ['class', 'Thing', 5], ['enum', 'Mode', 6]],
            IncCheck::declarations($code),
        );
    }

    public function test_assets_build_missing_stale_and_unknown_entries(): void
    {
        mkdir("{$this->theme}/assets/js", 0755, true);
        file_put_contents("{$this->theme}/assets/js/app.js", '');
        self::reset(Vite::class, 'manifest', null);
        self::reset(Vite::class, 'dev_mode', null);
        self::assertStringContainsString('No Vite build', $this->messages(new AssetsCheck()));

        mkdir("{$this->theme}/public/.vite", 0755, true);
        file_put_contents("{$this->theme}/public/.vite/manifest.json", '{"assets/js/app.js": {"file": "assets/app-1.js"}}');
        touch("{$this->theme}/public/.vite/manifest.json", time() - 60);
        file_put_contents("{$this->theme}/page.php", "<?php Vite::tags([\"assets/js/app.js\", 'assets/js/gone.js']);\n");
        self::reset(Vite::class, 'manifest', null);

        $messages = $this->messages(new AssetsCheck());
        self::assertStringContainsString('older than its sources (assets/js/app.js', $messages);
        self::assertStringContainsString('assets/js/gone.js is not a Vite entry', $messages);
        self::assertStringNotContainsString('assets/js/app.js is not', $messages);

        touch("{$this->theme}/public/.vite/hotfile");
        self::reset(Vite::class, 'dev_mode', null);
        self::assertSame('', $this->messages(new AssetsCheck())); // dev server running: not checked
        self::reset(Vite::class, 'dev_mode', null);
    }

    public function test_camel_case_methods_in_app(): void
    {
        $code = <<<'PHP'
            <?php
            final class X implements \JsonSerializable {
                public function snake_case(): void {}
                public static function relativeLink(): void {}
                private function helperThing(): void {}
                public function __toString(): string { return ''; }
                #[\Override]
                public function jsonSerialize(): mixed { return null; }
                #[\Deprecated] #[\Override] public static function getIterator(): void {}
                public function run(): void { $f = function () {}; }
            }
            PHP;

        self::assertSame([['relativeLink', 4], ['helperThing', 5]], AppCheck::camel_case_methods($code));
    }

    public function test_keys_that_moved_out_of_config(): void
    {
        file_put_contents("{$this->theme}/config/theme.php", "<?php return ['debug' => true, 'cache' => false];");
        file_put_contents("{$this->theme}/config/console.php", "<?php return ['url' => 'https://x.test'];");
        Config::load("{$this->theme}/config");

        $messages = $this->messages(new ConfigCheck());

        self::assertStringContainsString('theme.debug is no longer read', $messages);
        self::assertStringContainsString('console.url is no longer read', $messages);
        self::assertStringNotContainsString('theme.cache', $messages);
    }

    public function test_env_file(): void
    {
        file_put_contents("{$this->theme}/.env", "# comment\nSITE_URL=\"https://blueprint.test\"\nEMPTY=\n");
        self::reset(Env::class, 'values', null);

        self::assertSame('https://blueprint.test', Env::get('SITE_URL'));
        self::assertNull(Env::get('EMPTY'));
        self::assertNull(Env::get('MISSING'));
        self::reset(Env::class, 'values', null);
    }

    public function test_json_decoded_string_parameters(): void
    {
        $action = new class {
            /** @param array<string, string> $attributes */
            public function run(string $variation, string $note = '', array $attributes = []): void {}
        };
        $code = '$selected = json_validate($variation) ? json_decode($variation, true) : []; echo esc_html($note);';

        self::assertSame(['variation'], AjaxCheck::json_strings(new \ReflectionMethod($action, 'run'), $code));
    }

    public function test_unknown_woocommerce_templates(): void
    {
        $known = ['single-product.php', 'archive-product.php', 'notices/success.php'];

        self::assertSame(
            ['archive-product-shop.php', 'notices/succes.php'],
            WordPressCheck::unknown_templates(
                ['single-product.php', 'archive-product-shop.php', 'notices/succes.php', 'archive-product.php'],
                static fn(string $file): bool => in_array($file, $known, true),
            ),
        );
    }

    public function test_stale_mentions_in_guidelines(): void
    {
        mkdir("{$this->theme}/views/components", 0755, true);
        file_put_contents("{$this->theme}/views/components/hero.twig", '');
        $markdown = 'Uses `components/hero.twig`, `components/gone.twig`, `blocks/x/{name}.twig`, '
            . '`acf/content-faq`, `acf/faq-old`, `Theme\\Gone::make()`, `assets.php`, `views/*.twig`.';

        self::assertSame(
            ['components/gone.twig', 'acf/faq-old', 'Theme\\Gone::make()'],
            GuidelinesCheck::missing($markdown, ['acf/content-faq' => 'contentFaq']),
        );
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
