<?php

declare(strict_types=1);

namespace Gaffer\Tests;

use Gaffer\Acf;
use Gaffer\Config;
use Gaffer\Console\Checks\AssetsCheck;
use Gaffer\Console\Checks\Check;
use Gaffer\Console\Checks\DeprecationsCheck;
use Gaffer\Console\Checks\FormsCheck;
use Gaffer\Console\Checks\HtmxCheck;
use Gaffer\Console\Report;
use Gaffer\Forms\FormState;
use Gaffer\Forms\Spam;
use Gaffer\Gaffer;
use Gaffer\Paths;
use Gaffer\View;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Symfony\Component\Console\Output\BufferedOutput;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/** The pairs Gaffer owns on both ends (forms, the Vite build) and what keeps breakage loud. */
final class StabilityTest extends TestCase
{
    private string $theme;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->theme = sys_get_temp_dir() . '/gaffer-stability-' . bin2hex(random_bytes(4));
        mkdir("{$this->theme}/views/forms", 0755, true);
        mkdir("{$this->theme}/config", 0755, true);
        Paths::set_base($this->theme);
        Config::load("{$this->theme}/config");
        self::reset(View::class, 'env', null);
        self::reset(Gaffer::class, 'twig', false);
    }

    #[\Override]
    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->theme));
    }

    /** @param array<string, mixed> $variables */
    private static function meta(FormState $form, array $variables = []): string
    {
        $loader = new FilesystemLoader();
        $loader->addPath(Paths::gaffer_views(), 'gaffer');
        $twig = new Environment($loader, ['strict_variables' => true]);

        return $twig->render('@gaffer/forms/meta.twig', ['form' => $form, ...$variables]);
    }

    public function test_the_forms_partial_writes_what_form_action_reads(): void
    {
        $html = self::meta(FormState::blank('contact'));

        self::assertStringContainsString('<input type="hidden" name="form_name" value="contact">', $html);
        self::assertStringContainsString('name="website"', $html);
        self::assertStringContainsString('aria-hidden="true"', $html);
        self::assertStringNotContainsString('cf-turnstile', $html); // no site key and secret
    }

    #[RunInSeparateProcess]
    public function test_the_turnstile_widget_shows_exactly_when_tokens_are_checked(): void
    {
        file_put_contents("{$this->theme}/config/turnstile.php", "<?php return ['site_key' => '0xKEY'];");
        Config::load("{$this->theme}/config");
        self::assertStringNotContainsString('cf-turnstile', self::meta(FormState::blank('contact'))); // no secret on this server

        define('TURNSTILE_SECRET_KEY', 'secret');
        $html = self::meta(FormState::blank('contact'), ['turnstile' => ['appearance' => 'interaction-only', 'language' => 'nl', 'class' => 'mb-5']]);
        self::assertStringContainsString('class="cf-turnstile mb-5" data-sitekey="0xKEY" data-response-field-name="turnstile" data-appearance="interaction-only" data-language="nl"></div>', $html);
    }

    public function test_form_templates_must_use_gaffers_partial(): void
    {
        file_put_contents("{$this->theme}/views/forms/contact.twig", "<form>{{ include('components/meta.twig', { form: form }, with_context = false) }}</form>");
        mkdir("{$this->theme}/views/components");
        file_put_contents("{$this->theme}/views/components/meta.twig", "<input type=\"hidden\" name=\"form\" value=\"{{ form.name }}\">\n<div class=\"cf-turnstile\"></div>");
        file_put_contents("{$this->theme}/views/forms/news.twig", "<form>{{ include('@gaffer/forms/meta.twig', { form: form }, with_context = false) }}</form>");
        mkdir("{$this->theme}/inc");
        mkdir("{$this->theme}/app/Ajax/QuoteForm", 0755, true);
        file_put_contents("{$this->theme}/app/Ajax/QuoteForm/QuoteForm.php", "<?php\nclass QuoteForm {\n    public function run(string \$naam, string \$website = ''): void {}\n}");
        file_put_contents("{$this->theme}/inc/assets.php", "<?php wp_enqueue_script('t', 'https://challenges.cloudflare.com/turnstile/v0/api.js');");

        Gaffer::twig();
        $found = array_map(static fn(array $f): string => basename((string) $f['file']) . ':' . $f['line'] . ' ' . $f['message'], $this->findings(new FormsCheck()));
        sort($found);

        self::assertSame([
            'QuoteForm.php:3 Reads Gaffer\'s honeypot or Turnstile field itself',
            'assets.php: Loads or renders Turnstile itself',
            'contact.twig: Doesn\'t include @gaffer/forms/meta.twig: Gaffer refuses the form, or answers every submission as spam',
            'meta.twig:1 Writes a form key Gaffer reads (form_name, website, turnstile) by hand',
            'meta.twig:2 Writes the Turnstile widget by hand',
        ], $found);
    }

    public function test_spam_check_reads_what_the_partial_writes(): void
    {
        $_POST = ['website' => 'http://spam', 'turnstile' => ''];
        Paths::set_base($this->theme);
        $log = Spam::check('offerte');
        self::assertSame('honeypot', $log);
        self::assertStringContainsString('offerte honeypot', (string) file_get_contents("{$this->theme}/storage/logs/spam.log"));

        $_POST = ['website' => '', 'turnstile' => ''];
        self::assertNull(Spam::check('offerte')); // Turnstile off: no site key and secret
        $_POST = [];
    }

    public function test_spam_counts_per_form_and_reason(): void
    {
        $log = "[2026-11-01 10:00:00] contact turnstile-missing\n[2026-11-10 10:00:00] contact turnstile-missing\n"
            . "[2026-11-10 11:00:00] contact honeypot\n[2026-11-11 09:00:00] news turnstile-invalid\nnot a line\n";
        $since = (int) strtotime('2026-11-05 00:00:00 UTC');

        self::assertSame(['contact' => ['turnstile-missing' => 1, 'honeypot' => 1], 'news' => ['turnstile-invalid' => 1]], Spam::counts($log, $since));
    }

    public function test_block_array_knows_whether_a_block_renders(): void
    {
        $log = tempnam(sys_get_temp_dir(), 'log');
        $previous = ini_set('error_log', $log);
        try {
            self::assertSame([], Acf::block_array('questions')); // outside, without WP_DEBUG: logged, not thrown
            self::assertStringContainsString("Acf::block_array('questions') is called outside a block render", (string) file_get_contents($log));

            file_put_contents($log, '');
            $render = Acf::rendering(static fn(array $attributes): array => Acf::block_array('questions'));
            self::assertSame([], $render(['data' => []])); // inside: ACF's get_field(), absent here
            self::assertSame('', file_get_contents($log));
            self::assertSame([], Acf::block_array('x')); // the count went back down
            self::assertNotSame('', file_get_contents($log));
        } finally {
            ini_set('error_log', (string) $previous);
            unlink($log);
        }
    }

    public function test_the_build_needs_gaffers_vite_plugin_and_its_javascript(): void
    {
        mkdir("{$this->theme}/assets/js", 0755, true);
        mkdir("{$this->theme}/public/.vite", 0755, true);
        file_put_contents("{$this->theme}/assets/js/app.js", '');
        touch("{$this->theme}/assets/js/app.js", time() - 60);
        file_put_contents("{$this->theme}/public/.vite/manifest.json", '{}');
        file_put_contents("{$this->theme}/vite.config.js", 'export default {};');
        self::assertSame(['vite.config.js doesn\'t use Gaffer\'s Vite plugin'], array_column($this->findings(new AssetsCheck()), 'message'));

        file_put_contents("{$this->theme}/vite.config.js", 'import gaffer from "./vendor/onstage2426/gaffer/resources/vite.js";');
        self::assertSame(['The build has no public/.vite/gaffer.json (built before Gaffer\'s Vite plugin)'], array_column($this->findings(new AssetsCheck()), 'message'));

        file_put_contents("{$this->theme}/public/.vite/gaffer.json", '{"js": "old"}');
        self::assertSame(['The build has older Gaffer JavaScript than the installed Gaffer'], array_column($this->findings(new AssetsCheck()), 'message'));

        file_put_contents("{$this->theme}/public/.vite/gaffer.json", json_encode(['js' => AssetsCheck::gaffer_js_hash(dirname(__DIR__) . '/resources/js')]));
        self::assertSame([], $this->findings(new AssetsCheck()));
    }

    public function test_the_vite_plugin_hashes_like_doctor(): void
    {
        $node = trim((string) shell_exec('command -v node'));
        if ($node === '') {
            self::markTestSkipped('No node');
        }
        $script = 'import(' . json_encode(dirname(__DIR__) . '/resources/vite.js') . ').then((m) => console.log(m.gafferJsHash()))';
        self::assertSame(AssetsCheck::gaffer_js_hash(dirname(__DIR__) . '/resources/js'), trim((string) shell_exec(escapeshellarg($node) . ' -e ' . escapeshellarg($script))));
    }

    public function test_twigs_own_deprecations_are_warnings(): void
    {
        file_put_contents("{$this->theme}/views/old.twig", "{{ 'a'|spaceless }}");

        Gaffer::twig();
        $findings = $this->findings(new DeprecationsCheck());
        self::assertCount(1, $findings);
        self::assertStringContainsString('spaceless', $findings[0]['message']);
        self::assertStringContainsString('since Twig 3.12', $findings[0]['message']);
        self::assertStringStartsWith('Twig 4 removes or changes this', (string) $findings[0]['hint']);
    }

    public function test_htmx_2_names_in_an_htmx_4_theme(): void
    {
        file_put_contents("{$this->theme}/views/cart.twig", "<form hx-post=\"/x\" hx-disabled-elt=\"find button\"\n    hx-on::after-request=\"done()\" hx-on::before:request=\"ok()\">\n<input type=\"hidden\" name=\"form\" value=\"x\">");
        file_put_contents("{$this->theme}/package.json", '{"devDependencies": {"htmx.org": "^2.0.11"}}');
        self::assertSame([], $this->findings(new HtmxCheck())); // htmx 2: its names are right

        file_put_contents("{$this->theme}/package.json", '{"devDependencies": {"htmx.org": "^4.0.2"}}');
        Gaffer::twig();
        $found = array_map(static fn(array $f): string => "{$f['line']} {$f['message']} → {$f['hint']}", $this->findings(new HtmxCheck()));
        self::assertSame([
            '1 hx-disabled-elt is htmx 2 → htmx 4: hx-disable (htmx 2\'s hx-disable is htmx 4\'s hx-ignore: rename that one first).',
            '2 hx-on event "after-request" is htmx 2\'s name → htmx 4: hx-on::after:request.',
            '3 An input named "form" hides the <form>\'s own form property → htmx 4 reads the form through those properties and fails (FormData of an input): rename the input.',
        ], $found);
        self::assertSame(4, HtmxCheck::major());

        file_put_contents("{$this->theme}/views/drawer.twig", "<form hx-post=\"/u\" hx-target=\"#d\">\n<button type=\"button\"\n  hx-post=\"/r\" hx-target=\"#d\">x</button>\n<button hx-post=\"/r\">y</button>");
        self::assertTrue(HtmxCheck::sets_itself("{$this->theme}/views/drawer.twig", 'hx-target needs :inherited suffix (descendant on line 2 has hx-post)'));
        self::assertFalse(HtmxCheck::sets_itself("{$this->theme}/views/drawer.twig", 'hx-target needs :inherited suffix (descendant on line 4 has hx-post)'));
    }

    /** @return list<array{level: string, check: string, message: string, file: ?string, line: ?int, hint: ?string}> */
    private function findings(Check $check): array
    {
        $report = new Report();
        $check->run($report);
        $output = new BufferedOutput();
        $report->render($output, true);

        return array_values((array) json_decode($output->fetch(), true)['findings']);
    }
}
