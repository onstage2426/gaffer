<?php

declare(strict_types=1);

namespace Gaffer\Tests;

use Gaffer\Config;
use Gaffer\Console\Checks\AjaxCheck;
use Gaffer\Console\Checks\AppCheck;
use Gaffer\Console\Checks\AssetsCheck;
use Gaffer\Console\Checks\BlocksCheck;
use Gaffer\Console\Checks\AcfJsonCheck;
use Gaffer\Console\Checks\Check;
use Gaffer\Console\Checks\FieldGroupsCheck;
use Gaffer\Console\Checks\ConfigCheck;
use Gaffer\Console\Checks\GuidelinesCheck;
use Gaffer\Console\Checks\IncCheck;
use Gaffer\Console\Checks\MarkupCheck;
use Gaffer\Console\Checks\SearchCheck;
use Gaffer\Console\Checks\SourceCheck;
use Gaffer\Console\Checks\TemplateGlobalsCheck;
use Gaffer\Console\Checks\TagsCheck;
use Gaffer\Console\Checks\TemplatesCheck;
use Gaffer\Console\Checks\TexturizeCheck;
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

        file_put_contents("{$this->theme}/config/ai.php", '<?php return [];');
        self::assertStringContainsString('doesn\'t run ai:update', $this->messages(new ConfigCheck()));
        file_put_contents("{$this->theme}/composer.json", '{"autoload": {"psr-4": {"Theme\\\\": "app/"}}, "scripts": {"post-update-cmd": ["@php gaffer ai:update --ansi"]}}');
        self::assertStringNotContainsString('ai:update', $this->messages(new ConfigCheck()));
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

    public function test_field_groups_in_code(): void
    {
        mkdir("{$this->theme}/fields/options_page", 0755, true);
        mkdir("{$this->theme}/fields/user", 0755, true);
        mkdir("{$this->theme}/inc", 0755, true);
        file_put_contents("{$this->theme}/fields/options_page/algemeen.php", "<?php return [['name' => 'team', 'type' => 'text'], ['name' => 'telefoon', 'type' => 'text']];");
        file_put_contents("{$this->theme}/fields/options_page/footer.php", "<?php return [['name' => 'team', 'type' => 'text']];");
        file_put_contents("{$this->theme}/inc/x.php", "<?php\nget_field('team', 'option');\n");

        $messages = $this->messages(new FieldGroupsCheck());

        self::assertStringContainsString("fields/user/ isn't an ACF location", $messages);
        self::assertStringContainsString('Field "team" is on two options pages (options_page/algemeen, options_page/footer)', $messages);
        self::assertStringContainsString('Field "telefoon" is never read', $messages);
        self::assertStringNotContainsString('Field "team" is never read', $messages);

        file_put_contents("{$this->theme}/fields/options_page/footer.php", "<?php return [['name' => 'thumbnail_id', 'type' => 'image']];");
        self::assertStringNotContainsString('"thumbnail_id" is never read', $this->messages(new FieldGroupsCheck())); // Term::thumbnail() reads it
    }

    public function test_conditional_logic_on_a_field_that_does_not_exist(): void
    {
        $fields = [
            ['key' => 'field_a', 'name' => 'aan', 'type' => 'true_false'],
            ['key' => 'field_b', 'name' => 'tekst', 'type' => 'text', 'conditional_logic' => [[['field' => 'field_a', 'operator' => '==', 'value' => '1']]]],
            ['key' => 'field_c', 'name' => 'uit', 'type' => 'text', 'conditional_logic' => [[['field' => 'field_weg', 'operator' => '==', 'value' => 'dit veld is uit geschakeld']]]],
            ['key' => 'field_d', 'name' => 'rijen', 'type' => 'repeater', 'conditional_logic' => 0, 'sub_fields' => [
                ['key' => 'field_e', 'name' => 'x', 'type' => 'text', 'conditional_logic' => [[['field' => 'field_zzz', 'operator' => '!=empty']]]],
            ]],
        ];

        self::assertSame(['uit', 'x'], AcfJsonCheck::dead_conditions($fields));
    }

    public function test_ui_field_groups_are_code_now(): void
    {
        mkdir("{$this->theme}/storage/acf-json", 0755, true);
        mkdir("{$this->theme}/fields/post_type", 0755, true);
        file_put_contents("{$this->theme}/fields/post_type/review.php", "<?php return [['name' => 'score', 'type' => 'number']];");
        $group = static fn(string $title, string $param, string $value): string => (string) json_encode(['key' => 'group_' . md5($title), 'title' => $title, 'fields' => [],
            'location' => [[['param' => $param, 'operator' => '==', 'value' => $value]]]]);
        file_put_contents("{$this->theme}/storage/acf-json/group_a.json", $group('Review: Info', 'post_type', 'review'));
        file_put_contents("{$this->theme}/storage/acf-json/group_b.json", $group('Tax: pa_kleur', 'taxonomy', 'pa_kleur'));
        file_put_contents("{$this->theme}/storage/acf-json/post_type_c.json", '{"key": "post_type_c", "title": "Reviews", "post_type": "review"}');

        $findings = $this->findings(new AcfJsonCheck());

        file_put_contents("{$this->theme}/storage/acf-json/group_d.json", (string) json_encode(['key' => 'group_d', 'title' => 'Merk en categorie', 'fields' => [],
            'location' => [[['param' => 'taxonomy', 'operator' => '==', 'value' => 'product_cat']], [['param' => 'taxonomy', 'operator' => '==', 'value' => 'pa_merk']]]]));
        $findings = $this->findings(new AcfJsonCheck());
        self::assertStringContainsString('"Merk en categorie" (taxonomy == product_cat or taxonomy == pa_merk)', (string) end($findings)['hint']);
        array_pop($findings);

        self::assertSame(['error', 'warning'], array_column($findings, 'level'));
        self::assertStringContainsString('targets post_type/review, which defines its fields in fields/post_type/review.php', $findings[0]['message']);
        self::assertStringContainsString('"Tax: pa_kleur" is made in the ACF UI', $findings[1]['message']);
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
        file_put_contents("{$this->theme}/views/footer.twig", 'footer');
        file_put_contents("{$this->theme}/page.php", "<?php View::render('page.twig', []);\nView::render(\"missing.twig\");\nView::render(\n    'footer.twig',\n    ['a' => 1],\n);\n");
        self::reset(View::class, 'env', null);
        self::reset(Gaffer::class, 'twig', false);

        $messages = $this->messages(new TemplatesCheck());

        self::assertStringContainsString("Renders missing.twig, which doesn't exist", $messages);
        self::assertStringContainsString("include() loads components/gone.twig, which doesn't exist", $messages);
        self::assertSame(1, substr_count($messages, 'passes all of this template'));
        self::assertSame(1, substr_count($messages, 'Nothing renders or includes this template')); // old.twig, not the multi-line footer.twig
    }

    public function test_nested_forms_and_meta_in_templates(): void
    {
        mkdir("{$this->theme}/views/components", 0755, true);
        file_put_contents("{$this->theme}/views/components/card.twig", "{{ term.meta('tint') }}\n{{ include('components/add-to-cart.twig', {}, with_context = false) }}");
        file_put_contents("{$this->theme}/views/components/add-to-cart.twig", '<form method="post"></form>');
        file_put_contents("{$this->theme}/views/archive.twig", "<form>\n{{ include('components/card.twig', {}, with_context = false) }}\n</form>\n{{ include('components/card.twig', {}, with_context = false) }}");
        file_put_contents("{$this->theme}/archive.php", "<?php View::render('archive.twig', []);");
        self::reset(View::class, 'env', null);
        self::reset(Gaffer::class, 'twig', false);

        $found = array_map(static fn(array $f): string => basename((string) $f['file']) . ":{$f['line']} {$f['message']}", $this->findings(new TemplatesCheck()));
        sort($found);
        self::assertSame([
            'archive.twig:2 Includes components/card.twig inside a <form>, and it includes components/add-to-cart.twig, which has a <form> itself',
            'card.twig:1 Calls meta(): the template reads a field by name',
        ], $found);
    }

    public function test_a_gt_inside_an_attribute(): void
    {
        $source = <<<'TWIG'
            <div class="[&>svg]:size-8">
            <p x-show="count > 0" class='a>b'>{{ a > b ? 'x' : 'y' }}</p>
            {% if a > b %}<span class="{{ x > 1 ? 'a' : 'b' }}">{# > #}</span>{% endif %}
            <!-- <i class="a>b"> -->
            <script>if (a > b) { el.innerHTML = '<b class="c">x</b>' }</script>
            <a href="/x">></a>
            TWIG;

        self::assertSame([1, 2], TexturizeCheck::lines($source));
    }

    public function test_texturize_checks_only_what_blocks_render(): void
    {
        mkdir("{$this->theme}/views/components", 0755, true);
        file_put_contents("{$this->theme}/blocks/contentFaq/contentFaq.twig", "{{ include('components/icon.twig', {}, with_context = false) }}");
        file_put_contents("{$this->theme}/blocks/contentFaq/functions.php", "<?php View::render('components/card.twig', []);\n");
        file_put_contents("{$this->theme}/views/components/icon.twig", '<i class="[&>svg]:size-8"></i>');
        file_put_contents("{$this->theme}/views/components/card.twig", "\n<p class=\"a>b\"></p>");
        file_put_contents("{$this->theme}/views/footer.twig", '<i class="[&>svg]:size-8"></i>'); // not post content
        self::reset(View::class, 'env', null);
        self::reset(Gaffer::class, 'twig', false);

        $findings = $this->findings(new TexturizeCheck());

        self::assertSame([['card.twig', 2], ['icon.twig', 1]], array_map(static fn(array $f): array => [basename((string) $f['file']), $f['line']], $findings));
    }

    public function test_html_in_php_outside_the_shell(): void
    {
        file_put_contents("{$this->theme}/header.php", "<?php ?>\n<html><body>");
        file_put_contents("{$this->theme}/page.php", "<?php\n// a <div> in a comment is fine\n\$x = 1;\necho '<div class=\"a\">';\n\$y = <<<HTML\n    <p>x</p>\nHTML;\n\$z = 'a < b';\n");

        $findings = $this->findings(new MarkupCheck());

        self::assertSame('HTML in PHP', $findings[0]['message']); // stable for doctor-baseline.json
        self::assertSame(4, $findings[0]['line']);
        self::assertStringStartsWith('On line 4, 6.', $findings[0]['hint']);
    }

    public function test_raw_and_new_markup_need_a_trust_comment(): void
    {
        mkdir("{$this->theme}/views", 0755, true);
        mkdir("{$this->theme}/inc", 0755, true);
        file_put_contents("{$this->theme}/views/a.twig", implode("\n", [
            '{{ a|raw }}',
            '{{ b }}',
            '{{ c|raw }}{# trusted: WordPress content #}',
            '{# trusted: a Gravity Forms confirmation #}',
            '{{ d|raw }}',
            '{# trusted: #}{{ e|raw }}',
        ]) . "\n");
        file_put_contents("{$this->theme}/inc/x.php", "<?php\n\$c = new \\Twig\\Markup(\$html, 'UTF-8');\n\$d = new MarkupThing();\n// trusted: rendered by Theme\\Search\n\$e = new Markup(\$card, 'UTF-8');\n");

        $found = array_map(static fn(array $f): string => basename((string) $f['file']) . ":{$f['line']} {$f['message']}", $this->findings(new SourceCheck()));
        sort($found);
        self::assertSame(['a.twig:1 |raw without a trust comment', 'a.twig:6 |raw without a trust comment', 'x.php:2 new Markup without a trust comment'], $found);
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
        file_put_contents("{$this->theme}/page.php", "<?php Vite::tags([\n    \"assets/js/app.js\",\n    'assets/js/gone.js',\n]);\n");
        touch("{$this->theme}/assets/js/app.js", time() - 120);
        mkdir("{$this->theme}/assets/img", 0755, true);
        file_put_contents("{$this->theme}/assets/img/new.png", 'x'); // served directly, not built
        self::reset(Vite::class, 'manifest', null);

        $findings = $this->findings(new AssetsCheck());
        self::assertSame(["assets/js/gone.js is not a Vite entry (not in the manifest)"], array_column($findings, 'message'));
        self::assertSame(1, $findings[0]['line']); // where the multi-line call starts

        touch("{$this->theme}/assets/js/app.js");
        $findings = $this->findings(new AssetsCheck());
        self::assertSame('The build is older than its sources', $findings[0]['message']);
        self::assertStringStartsWith('assets/js/app.js changed since.', (string) $findings[0]['hint']);

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
            [['post', 3], ['page', 4], ['wp_query', 5], ['posts', 6], ['product', 7]],
            TemplateGlobalsCheck::assignments($code, ['post', 'posts', 'wp_query', 'page']),
        );
    }

    public function test_wordpress_declares_the_template_globals(): void
    {
        $code = <<<'PHP'
            <?php
            class WP_Query {
                public function the_post() { global $post; }
                public function setup_postdata($post) {
                    global $id, $authordata, $page, $pages;
                    if ($x) { $page = 1; }
                    $unrelated = 2;
                }
            }
            PHP;

        self::assertSame(['id', 'authordata', 'page', 'pages'], TemplateGlobalsCheck::declared_globals($code, 'setup_postdata'));
        self::assertSame([], TemplateGlobalsCheck::declared_globals($code, 'missing'));
    }

    public function test_only_template_files_are_checked(): void
    {
        mkdir("{$this->theme}/woocommerce", 0755, true);
        file_put_contents("{$this->theme}/page.php", "<?php\n\$post = 1;\n");
        file_put_contents("{$this->theme}/functions.php", "<?php\n\$post = 1;\n");
        file_put_contents("{$this->theme}/woocommerce/single-product.php", "<?php\nglobal \$product;\n\$product = 1;\n");
        // A WordPress around the theme: its root has wp-load.php and the files declaring the globals.
        mkdir("{$this->theme}/wp-includes");
        file_put_contents("{$this->theme}/wp-load.php", '<?php');
        file_put_contents("{$this->theme}/wp-includes/template.php", "<?php\nfunction load_template() { global \$posts, \$post; }\n");
        file_put_contents("{$this->theme}/wp-includes/class-wp-query.php", "<?php\nclass WP_Query { function setup_postdata() { global \$page; } }\n");
        Paths::set_base($this->theme);

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

    public function test_search_archive_keys(): void
    {
        file_put_contents("{$this->theme}/config/search.php", <<<'PHP'
            <?php return [
                'indexes' => ['products' => ['type' => 'products', 'document' => 'nope', 'cards' => ['List' => 'x.twig', 'grid' => 'missing.twig']]],
                'archive' => ['index' => 'products', 'per_page' => 24, 'limits' => [12, 48], 'facet_values' => ['size' => '400'], 'max_docs' => 5000, 'bounds' => 'price',
                    'index_pages' => true, 'seo' => ['index_pages' => 'yes', 'indexable_filters' => ['on_sale' => 'yes'], 'canonical' => true],
                    'urls' => ['base_params' => ['show', 'offset'], 'redirect_params' => 'nope']],
            ];
            PHP);
        Config::load("{$this->theme}/config");

        $messages = $this->messages(new SearchCheck());
        foreach (['document is not callable', 'limits must include per_page (24)', 'facet_values must map', 'seo.index_pages must be', 'seo.indexable_filters must map',
            'urls.base_params can\'t name the archive\'s own param "offset"', 'urls.redirect_params is not callable', 'bounds must be a list', 'cards maps a layout name',
            'cards "grid" "missing.twig" is not a template', 'unknown key "index_pages"', 'unknown key "seo.canonical"'] as $expected) {
            self::assertStringContainsString($expected, $messages);
        }
        self::assertStringNotContainsString('max_docs', $messages);
    }

    public function test_unavailable_archive_only_for_gaffers_search(): void
    {
        mkdir("{$this->theme}/app", 0755, true);
        file_put_contents("{$this->theme}/app/Search.php", "<?php\nnamespace Theme;\nfinal class Search { public static function x() { return Search::archive('products'); } }\n");
        self::assertStringNotContainsString('search.available', $this->messages(new SearchCheck()), 'a theme\'s own Search class isn\'t Gaffer\'s');

        file_put_contents("{$this->theme}/app/Shop.php", "<?php\nnamespace Theme;\nuse Gaffer\\Search\\Search;\nfinal class Shop { public static function x() { return Search::archive(); } }\n");
        self::assertStringContainsString('search.available', $this->messages(new SearchCheck()));
    }

    public function test_card_templates_of_the_search_config_are_used(): void
    {
        mkdir("{$this->theme}/views/product", 0755, true);
        file_put_contents("{$this->theme}/views/product/list-item.twig", '<li>{{ post.title }}</li>');
        self::assertStringContainsString('Nothing renders or includes this template', $this->messages(new TemplatesCheck()));

        file_put_contents("{$this->theme}/config/search.php", "<?php return ['indexes' => ['products' => ['type' => 'products', 'cards' => ['grid' => 'x.twig', 'list' => 'product/list-item.twig']]]];");
        Config::load("{$this->theme}/config");
        self::assertStringNotContainsString('Nothing renders or includes this template', $this->messages(new TemplatesCheck()), 'the index renders it');
    }

    public function test_tags_alt_next_to_attrs_and_classes_in_both_class_and_bind(): void
    {
        $source = <<<'TWIG'
            <img class="h-8" {{ logo.attrs('medium') }} alt="">
            <img class="h-8" {{ logo.attrs('medium', decorative: true) }}>
            <button class="px-3 bg-deep text-white" :class="incl ? 'bg-white text-deep' : 'bg-deep text-white'">x</button>
            <div class="{{ open ? 'a' : 'b' }} rotate-0 block" :class="{ 'rotate-45': open, hidden: !open }"></div>
            <p class="hidden" x-bind:class="{ hidden: !open }"></p>
            <p class="hidden block" :class="open ? { hidden: false } : ['block', 'x']"></p>
            {{ "<img alt='x' class='q'>" }}
            {# <img {{ x.attrs() }} alt=""> #}
            TWIG;
        $problems = TagsCheck::problems($source);
        self::assertSame([[1, 'error'], [3, 'warning'], [6, 'warning']], array_map(static fn(array $p): array => [$p[2], $p[0]], $problems));
        self::assertStringStartsWith('bg-deep text-white stays on', $problems[1][3]);
        self::assertStringStartsWith('block stays on', $problems[2][3], 'object keys are removed while false; arrays are strings');
    }

    public function test_tags_reads_twig_inside_attribute_values(): void
    {
        $tags = TagsCheck::tags("<a href=\"{{ url ~ '\">' }}\" {% if x %}data-x{% endif %} class='a b'>\n<b>");
        self::assertSame(['a', 'b'], array_column($tags, 'name'));
        self::assertSame([['href', "{{ url ~ '\">' }}"], ['data-x', null], ['class', 'a b']], $tags[0]['attrs']);
        self::assertSame(['{% if x %}', '{% endif %}'], $tags[0]['twig']);
        self::assertSame(2, $tags[1]['line']);
    }

    public function test_assets_templates_changed_since_the_build_with_classes_it_lacks(): void
    {
        self::assertSame('md\:grid-cols-\[minmax\(0\,1fr\)_1fr\]', AssetsCheck::escape('md:grid-cols-[minmax(0,1fr)_1fr]'));
        self::assertSame('\32 xl\:p-4', AssetsCheck::escape('2xl:p-4'));
        self::assertSame(['flex', 'md:pt-16', 'js-x'], AssetsCheck::classes('<div class="flex md:pt-16 {{ x }}"><i class="js-x flex"></i></div>'));

        mkdir("{$this->theme}/assets/css", 0755, true);
        mkdir("{$this->theme}/public/.vite", 0755, true);
        mkdir("{$this->theme}/public/assets", 0755, true);
        mkdir("{$this->theme}/views", 0755, true);
        file_put_contents("{$this->theme}/assets/css/app.css", '');
        touch("{$this->theme}/assets/css/app.css", time() - 120);
        file_put_contents("{$this->theme}/public/.vite/manifest.json", '{"assets/css/app.css": {"file": "assets/app-1.css"}}');
        file_put_contents("{$this->theme}/public/assets/app-1.css", '.flex{display:flex}.md\:pt-4{padding-top:1rem}');
        touch("{$this->theme}/public/.vite/manifest.json", time() - 60);
        file_put_contents("{$this->theme}/views/old.twig", '<p class="md:pt-16"></p>');
        touch("{$this->theme}/views/old.twig", time() - 90); // built since: not checked
        file_put_contents("{$this->theme}/views/new.twig", '<p class="flex md:pt-4 md:pt-16"></p>');
        self::reset(Vite::class, 'manifest', null);
        self::reset(Vite::class, 'dev_mode', null);
        self::reset(View::class, 'env', null);
        self::reset(Gaffer::class, 'twig', false);

        $findings = $this->findings(new AssetsCheck());
        self::assertSame(['Changed since the build, with classes the built CSS doesn\'t have'], array_column($findings, 'message'));
        self::assertStringEndsWith('views/new.twig', (string) $findings[0]['file']);
        self::assertStringStartsWith('md:pt-16. Tailwind', (string) $findings[0]['hint']);
    }

    public function test_search_filters_on_fields_that_are_no_facets(): void
    {
        self::assertSame(['expected' => 2, 'price' => 2, 'brand' => 3], SearchCheck::filtered_fields("<?php\nSearch::cards('p', 4, filter: ['expected' => 'yes', 'price' => ['min' => 1]]);\n\$r = ['filter' => ['brand' => ['a']]];", true));
        self::assertSame(['expected' => 1, 'fuel' => 1], SearchCheck::filtered_fields('<a href="?filter[expected]=yes&amp;filter%5Bfuel%5D=x">', false));
        self::assertSame(['tags' => 2, 'on_sale' => 2, 'brand' => 2, 'price' => 2, 'color' => 3], SearchCheck::filtered_fields("<?php\nSearch::cards('p', 4, filter: ['tags' => ['all' => ['a', 'b']], 'any' => [['on_sale' => 'yes'], ['brand' => 'x', 'price' => ['max' => 2]]],\n'not' => ['color' => 'rood']]);", true), 'fields inside groups, not the groups or a range\'s min/max');
        self::assertSame(['on_sale' => 1, 'color' => 1, 'size' => 1], SearchCheck::filtered_fields('<input name="filter[any][0][on_sale]"><input name="filter[not][color]"><input name="filter[all][1][any][0][size]">', false));

        mkdir("{$this->theme}/views", 0755, true);
        file_put_contents("{$this->theme}/config/search.php", "<?php return ['indexes' => ['products' => ['type' => 'products', 'facets' => ['fuel'], 'meta' => ['_fuel' => 'fuel', '_x' => ['expected', 'string']], 'fields' => ['per_m2' => 'float']]]];");
        Config::load("{$this->theme}/config");
        file_put_contents("{$this->theme}/views/chips.twig", '<a href="?filter[fuel]=ev">EV</a> <a href="?filter[expected]=yes">Soon</a>');
        self::reset(View::class, 'env', null);
        self::reset(Gaffer::class, 'twig', false);

        $findings = array_values(array_filter($this->findings(new SearchCheck()), static fn(array $f): bool => str_starts_with($f['message'], 'Filters on')));
        self::assertSame(['Filters on "expected", which index "products" stores but doesn\'t filter on'], array_column($findings, 'message'));
    }

    private function messages(Check $check): string
    {
        return implode("\n", array_column($this->findings($check), 'message'));
    }

    /** @return list<array{level: string, check: string, message: string, file: ?string, line: ?int, hint: ?string}> */
    private function findings(Check $check): array
    {
        $report = new Report();
        $check->run($report);
        $output = new BufferedOutput();
        $report->render($output, true);

        return (array) json_decode($output->fetch(), true);
    }
}
