<?php

declare(strict_types=1);

namespace Gaffer\Tests;

use Gaffer\Config;
use Gaffer\Console\Upgrade\Change;
use Gaffer\Console\Upgrade\Theme;
use Gaffer\Console\Upgrade\Upgrader;
use Gaffer\Paths;
use PHPUnit\Framework\TestCase as BaseTestCase;

final class UpgradeTest extends BaseTestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/gaffer-upgrade-' . bin2hex(random_bytes(4));
        mkdir("{$this->root}/config", 0755, true);
        Paths::set_base($this->root);
        Config::load("{$this->root}/config");
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    private function write(string $path, string $content): void
    {
        if (!is_dir(dirname("{$this->root}/{$path}"))) {
            mkdir(dirname("{$this->root}/{$path}"), 0755, true);
        }
        file_put_contents("{$this->root}/{$path}", $content);
    }

    private function read(string $path): ?string
    {
        return is_file("{$this->root}/{$path}") ? (string) file_get_contents("{$this->root}/{$path}") : null;
    }

    /** Applies every rule, and checks a second plan finds nothing to change. */
    private function upgrade(): void
    {
        $theme = new Theme($this->root);
        $changes = array_merge(...array_values(array_map(static fn(array $item): array => $item['changes'], Upgrader::plan($theme))));
        Upgrader::apply($theme, $changes, "{$this->root}/storage/upgrades/test-" . bin2hex(random_bytes(3)));
        $again = array_filter(Upgrader::plan($theme), static fn(array $item): bool => $item['changes'] !== []);
        self::assertSame([], array_keys($again), 'rules must be idempotent');
    }

    public function test_block_boilerplate(): void
    {
        $this->write('blocks/heroContent/block.json', json_encode(['name' => 'acf/hero-content', 'title' => 'Hero', 'acf' => ['blockVersion' => 3, 'renderTemplate' => 'functions.php']], JSON_PRETTY_PRINT) . "\n");
        $this->write('blocks/heroContent/functions.php', "<?php\nif (is_admin()) {\n    return; // the editor\n}\n\nView::render('x', []);\n");
        $this->write('blocks/other/block.json', json_encode(['name' => 'acf/legacy-name', 'title' => 'Other', 'acf' => ['mode' => 'preview', 'blockVersion' => 3]]));

        $this->upgrade();

        self::assertSame("{\n    \"title\": \"Hero\"\n}\n", $this->read('blocks/heroContent/block.json'));
        self::assertSame("<?php\nView::render('x', []);\n", $this->read('blocks/heroContent/functions.php'));
        self::assertSame(['name' => 'acf/legacy-name', 'title' => 'Other', 'acf' => ['mode' => 'preview']], json_decode((string) $this->read('blocks/other/block.json'), true), 'a name other than the folder\'s stays (stored in content)');
    }

    public function test_form_action_folder_only_when_it_repeats_gaffers(): void
    {
        $this->write('app/Ajax/Form/Form.php', "<?php\n\nnamespace Theme\\Ajax\\Form;\n\nuse Gaffer\\Forms\\FormAction;\n\nfinal class Form extends FormAction {}\n");
        $this->upgrade();
        self::assertNull($this->read('app/Ajax/Form/Form.php'));
        self::assertDirectoryDoesNotExist("{$this->root}/app/Ajax/Form");

        $this->write('app/Ajax/Form/Form.php', "<?php\nnamespace Theme\\Ajax\\Form;\nfinal class Form extends \\Gaffer\\Forms\\FormAction\n{\n    public function run(string \$form_name): void {}\n}\n");
        $this->upgrade();
        self::assertNotNull($this->read('app/Ajax/Form/Form.php'), 'a Form action with its own code stays');
    }

    public function test_file_inputs_only_in_gaffers_forms(): void
    {
        $this->write('views/forms/quote.twig', "<input type=\"file\" name=\"files[fotos][]\" multiple>\n<input type=\"hidden\" name=\"kept_files[fotos][]\" value=\"x\">\n{{ include('components/upload.twig', { name: 'cv' }) }}\n");
        $this->write('views/components/upload.twig', "<input type=\"file\" name=\"files[{{ name }}]\">\n");
        $this->write('views/page/own.twig', "<form hx-post=\"{{ ajax_url('Form') }}\"><input name='files[cv]'></form>\n");
        $this->write('views/offer/document.twig', "<form hx-post=\"{{ ajax_url('OfferUpload') }}\"><input type=\"file\" name=\"files[]\"><input type=\"file\" name=\"files[scan]\"></form>\n");

        $this->upgrade();

        self::assertSame("<input type=\"file\" name=\"fields[fotos][]\" multiple>\n<input type=\"hidden\" name=\"kept_files[fotos][]\" value=\"x\">\n{{ include('components/upload.twig', { name: 'cv' }) }}\n", $this->read('views/forms/quote.twig'));
        self::assertSame("<input type=\"file\" name=\"fields[{{ name }}]\">\n", $this->read('views/components/upload.twig'), 'included by a form template');
        self::assertSame("<form hx-post=\"{{ ajax_url('Form') }}\"><input name='fields[cv]'></form>\n", $this->read('views/page/own.twig'));
        self::assertSame("<form hx-post=\"{{ ajax_url('OfferUpload') }}\"><input type=\"file\" name=\"files[]\"><input type=\"file\" name=\"files[scan]\"></form>\n", $this->read('views/offer/document.twig'), 'a theme\'s own action keeps its names');
    }

    public function test_acf_json_and_vite(): void
    {
        $this->write('storage/acf-json/group_a.json', '{"a": 1}');
        $this->write('storage/acf-json/group_b.json', '{"b": 1}');
        $this->write('acf-json/group_b.json', '{"b": 2}');
        $this->write('package.json', '{"devDependencies": {"vite": "^8.0.3"}}');
        $this->write('vite.config.js', "export default { build: { rollupOptions: { input: [\n    \"assets/css/app.css\",\n    \"assets/css/editor.css\",\n    \"assets/js/app.js\",\n] } } };\n");

        $plan = Upgrader::plan(new Theme($this->root));
        self::assertSame(['storage/acf-json/group_b.json and acf-json/group_b.json differ: keep the newer one in acf-json/ and delete the other.'], $plan['2026-11-22-acf-json']['by_hand']);
        $this->upgrade();

        self::assertSame('{"a": 1}', $this->read('acf-json/group_a.json'));
        self::assertNull($this->read('storage/acf-json/group_a.json'));
        self::assertSame('{"b": 2}', $this->read('acf-json/group_b.json'), 'differing files are left to the theme');
        self::assertSame("export default { build: { rolldownOptions: { input: [\n    \"assets/css/app.css\",\n    \"assets/js/app.js\",\n] } } };\n", $this->read('vite.config.js'));
    }

    public function test_vite_7_keeps_rollup_options(): void
    {
        $this->write('package.json', '{"devDependencies": {"vite": "^7.1.0"}}');
        $this->write('vite.config.js', 'export default { build: { rollupOptions: {} } };');
        self::assertArrayNotHasKey('2026-11-22-vite-rolldown', Upgrader::plan(new Theme($this->root)));
    }

    public function test_editor_stylesheet_config_key(): void
    {
        $this->write('config/theme.php', "<?php\n\nreturn [\n    // the theme's types\n    'types' => [],\n    'block_previews' => true,\n    'cache' => true,\n];\n");
        $this->write('assets/css/editor.css', '/* editor */');
        $plan = Upgrader::plan(new Theme($this->root));
        self::assertStringStartsWith('assets/css/editor.css isn\'t loaded any more', $plan['2026-11-25-editor-stylesheet']['by_hand'][0]);

        $this->upgrade();

        self::assertSame("<?php\n\nreturn [\n    // the theme's types\n    'types' => [],\n    'cache' => true,\n];\n", $this->read('config/theme.php'));
    }

    public function test_product_type(): void
    {
        $this->write('app/Types/UpgradeProduct.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Theme\\Types;\n\nuse Gaffer\\Types\\Post;\n\nclass UpgradeProduct extends Post\n{\n    public function label(): string\n    {\n        return '';\n    }\n}\n");
        require_once "{$this->root}/app/Types/UpgradeProduct.php";
        $this->write('config/theme.php', "<?php return ['types' => ['product' => 'Theme\\\\Types\\\\UpgradeProduct']];");
        Config::load("{$this->root}/config");

        $plan = Upgrader::plan(new Theme($this->root));
        self::assertCount(1, $plan['2026-11-23-product-type']['changes']);
        self::assertStringContainsString('class UpgradeProduct extends \\Gaffer\\Types\\Product', (string) $plan['2026-11-23-product-type']['changes'][0]->after);
        self::assertStringContainsString("    public function label(): string\n    {\n        return '';\n    }", (string) $plan['2026-11-23-product-type']['changes'][0]->after, 'the rest keeps its formatting');
    }

    public function test_product_type_with_final_methods_is_by_hand(): void
    {
        $this->write('app/Types/UpgradeProductOwn.php', "<?php\nnamespace Theme\\Types;\nclass UpgradeProductOwn extends \\Gaffer\\Types\\Post\n{\n    public function gallery(): array { return []; }\n}\n");
        require_once "{$this->root}/app/Types/UpgradeProductOwn.php";
        $this->write('config/theme.php', "<?php return ['types' => ['product' => 'Theme\\\\Types\\\\UpgradeProductOwn']];");
        Config::load("{$this->root}/config");

        $plan = Upgrader::plan(new Theme($this->root));
        self::assertSame([], $plan['2026-11-23-product-type']['changes']);
        self::assertStringContainsString('declares gallery(), which Gaffer\\Types\\Product has (final)', $plan['2026-11-23-product-type']['by_hand'][0]);
    }

    public function test_cart_add(): void
    {
        $starter = <<<'PHP'
            <?php
            class CartAdd extends AjaxAction
            {
                public function run(Product $product, int $quantity = 1, array $attributes = []): void
                {
                    $cart = WC()->cart;
                    $product = $product->with_variation($attributes);
                    $product_id = (int) apply_filters("woocommerce_add_to_cart_product_id", $product->id());
                    $variation_id = $product->variation()?->get_id() ?? 0;
                    $quantity = wc_stock_amount($quantity);

                    // WooCommerce's validation adds a notice (shown in the drawer) when, say, no variation is chosen
                    $added = apply_filters("woocommerce_add_to_cart_validation", true, $product_id, $quantity, $variation_id, $attributes)
                        && $cart->add_to_cart($product_id, $quantity, $variation_id, $variation_id ? $attributes : []) !== false;

                    $this->render_drawer(added: $added);
                }
            }
            PHP;
        $this->write('app/Ajax/CartAdd/CartAdd.php', $starter);
        $this->write('app/Ajax/Own/Own.php', "<?php\nif (apply_filters('woocommerce_add_to_cart_validation', true, 1, 1)) { WC()->cart->add_to_cart(1, 1); }\n");

        $plan = Upgrader::plan(new Theme($this->root));
        self::assertSame(['app/Ajax/Own/Own.php'], array_map(static fn(string $t): string => explode(' ', $t)[0], $plan['2026-11-27-cart-add']['by_hand']));
        $this->upgrade();

        $after = (string) $this->read('app/Ajax/CartAdd/CartAdd.php');
        self::assertStringContainsString("        \$product = \$product->with_variation(\$attributes);\n        // WooCommerce's own add-to-cart steps; a refusal leaves its notice for the answer\n        \$added = \$product->add_to_cart(\$quantity) !== null;\n\n        \$this->render_drawer(added: \$added);", $after);
        self::assertStringNotContainsString('woocommerce_add_to_cart_validation', $after);
    }

    public function test_cart_badge_turns_the_starter_into_its_converted_self(): void
    {
        $fixtures = dirname(__DIR__) . '/tests/fixtures/upgrade/cart-badge';
        exec('cp -r ' . escapeshellarg("{$fixtures}/before") . '/. ' . escapeshellarg($this->root));
        $this->upgrade();

        $after = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("{$fixtures}/after", \FilesystemIterator::SKIP_DOTS)) as $file) {
            $after[] = substr($file->getPathname(), strlen("{$fixtures}/after") + 1);
        }
        sort($after);
        foreach ($after as $path) {
            self::assertSame((string) file_get_contents("{$fixtures}/after/{$path}"), $this->read($path), $path);
        }
        self::assertNull($this->read('app/Ajax/CartCount/CartCount.php'), 'the starter\'s count action goes');
    }

    public function test_restore_puts_everything_back(): void
    {
        $this->write('views/a.twig', 'before');
        $this->write('storage/acf-json/x.json', '{}');
        $theme = new Theme($this->root);
        $changes = [new Change('views/a.twig', 'before', 'after'), new Change('acf-json/x.json', null, '{}'), new Change('storage/acf-json/x.json', '{}', null)];

        Upgrader::apply($theme, $changes, "{$this->root}/backup");
        self::assertSame('after', $this->read('views/a.twig'));
        Upgrader::restore($theme, "{$this->root}/backup");

        self::assertSame('before', $this->read('views/a.twig'));
        self::assertNull($this->read('acf-json/x.json'));
        self::assertSame('{}', $this->read('storage/acf-json/x.json'));
    }
}
