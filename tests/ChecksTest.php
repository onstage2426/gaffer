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
use Gaffer\Console\Checks\SourceCheck;
use Gaffer\Console\Checks\TemplateGlobalsCheck;
use Gaffer\Console\Checks\TemplatesCheck;
use Gaffer\Console\Checks\WordPressCheck;
use Gaffer\Console\Env;
use Gaffer\Console\Report;
use Gaffer\Console\WordPress;
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

    public function test_new_markup_in_php_counts_as_raw(): void
    {
        mkdir("{$this->theme}/views", 0755, true);
        mkdir("{$this->theme}/inc", 0755, true);
        file_put_contents("{$this->theme}/views/a.twig", "{{ a|raw }}\n{{ b }}\n");
        file_put_contents("{$this->theme}/inc/x.php", "<?php\n\$c = new \\Twig\\Markup(\$html, 'UTF-8');\n\$d = new MarkupThing();\n");

        self::assertSame('2 uses of |raw (templates) and new Markup (PHP)', $this->messages(new SourceCheck()));
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

    public function test_unknown_config_keys(): void
    {
        file_put_contents("{$this->theme}/config/theme.php", "<?php return ['cahce' => true, 'cache' => false];");
        Config::load("{$this->theme}/config");

        $messages = $this->messages(new ConfigCheck());

        self::assertStringContainsString('Unknown key theme.cahce', $messages);
        self::assertStringNotContainsString('theme.cache ', $messages);
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

    public function test_env_set_keeps_other_lines(): void
    {
        file_put_contents("{$this->theme}/.env.example", "# Vite\nSITE_URL=\nVITE_PORT=5173\n");
        self::reset(Env::class, 'values', null);

        Env::set('SITE_URL', 'https://example.test/shop/');
        self::assertSame("# Vite\nSITE_URL=https://example.test/shop/\nVITE_PORT=5173\n", file_get_contents("{$this->theme}/.env"));
        self::assertSame('https://example.test/shop/', Env::get('SITE_URL'));

        Env::set('OTHER', 'x');
        self::assertStringEndsWith("VITE_PORT=5173\nOTHER=x\n", (string) file_get_contents("{$this->theme}/.env"));
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

    public function test_replaced_gaffer_guidelines_and_skills_are_listed(): void
    {
        mkdir("{$this->theme}/.ai/guidelines/plugins", 0755, true);
        mkdir("{$this->theme}/.ai/skills/gaffer-form", 0755, true);
        file_put_contents("{$this->theme}/.ai/guidelines/views.md", "## Views\n");
        file_put_contents("{$this->theme}/.ai/guidelines/plugins/woocommerce.md", "## WooCommerce\n");
        file_put_contents("{$this->theme}/.ai/guidelines/shop.md", "## Shop\n");
        file_put_contents("{$this->theme}/.ai/skills/gaffer-form/SKILL.md", "---\nname: gaffer-form\n---\n");

        $messages = $this->messages(new GuidelinesCheck());

        self::assertStringContainsString("Replaces Gaffer's views guideline", $messages);
        self::assertStringContainsString("Replaces Gaffer's plugins/woocommerce guideline", $messages);
        self::assertStringContainsString("Replaces Gaffer's gaffer-form skill", $messages);
        self::assertStringNotContainsString('shop', $messages);
    }

    public function test_twig_functions_that_look_content_up(): void
    {
        $code = <<<'PHP'
            <?php
            class E {
                #[AsTwigFunction("product")]
                public static function product(int $id): ?Product { return Product::from($id); }
                #[AsTwigFilter("money")]
                public static function money(float $v): string { return number_format($v, 2, ',', '.'); }
                #[\Twig\Attribute\AsTwigFunction("faq")]
                public static function faq(): array { return \get_field('vragen', 'option') ?: []; }
                public static function helper(): mixed { return get_field('x'); }
                #[AsTwigFunction("label")]
                public static function label($product): string { return $product->get_title() . self::from(1); }
            }
            PHP;

        self::assertSame(
            [['product', 'Product::from', 4], ['faq', 'get_field', 8]],
            AppCheck::twig_lookups($code),
        );
    }

    public function test_wordpress_globals_assigned_in_template_files(): void
    {
        $code = <<<'PHP'
            <?php
            global $product;
            $post = Post::current();
            $page = Post::current();
            foreach ($items as $wp_query) {}
            foreach ($items as $i => $posts) {}
            $product ??= null;
            $post->title();
            if ($post == null) {}
            $render = function () use ($x): void { $post = 1; };
            function helper(): void { $post = 2; }
            $html = "{$post}";
            PHP;

        self::assertSame(
            [['post', 3], ['wp_query', 5], ['posts', 6], ['product', 7]],
            TemplateGlobalsCheck::assignments($code),
        );
    }

    public function test_only_template_files_are_checked(): void
    {
        mkdir("{$this->theme}/woocommerce", 0755, true);
        file_put_contents("{$this->theme}/page.php", "<?php\n\$post = 1;\n");
        file_put_contents("{$this->theme}/functions.php", "<?php\n\$post = 1;\n");
        file_put_contents("{$this->theme}/woocommerce/single-product.php", "<?php\nglobal \$product;\n\$product = 1;\n");

        $messages = $this->messages(new TemplateGlobalsCheck());

        self::assertSame(2, substr_count($messages, 'a WordPress global'));
        self::assertStringContainsString('$product', $messages);
    }

    public function test_the_cli_skips_page_cache_drop_ins(): void
    {
        $before = $GLOBALS['wp_filter'] ?? null;
        WordPress::skip_page_cache();
        $hook = $GLOBALS['wp_filter']['enable_loading_advanced_cache_dropin'][10][0] ?? null;
        $GLOBALS['wp_filter'] = $before;

        self::assertIsCallable($hook['function'] ?? null);
        self::assertFalse(($hook['function'])(true));
    }

    public function test_the_cli_names_the_missing_multisite_site(): void
    {
        $before = $GLOBALS['wp_filter'] ?? null;
        WordPress::fail_on_unknown_site();
        $network = $GLOBALS['wp_filter']['ms_network_not_found'][10][0]['function'] ?? null;
        $site = $GLOBALS['wp_filter']['ms_site_not_found'][10][0]['function'] ?? null;
        $GLOBALS['wp_filter'] = $before;

        self::assertIsCallable($network);
        self::assertIsCallable($site);
        try {
            $site(new \stdClass(), 'localhost', '/');
            self::fail('No exception');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('no site at localhost/', $e->getMessage());
        }
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
