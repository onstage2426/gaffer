<?php

declare(strict_types=1);

namespace Gaffer\Console\Checks;

use Gaffer\Ajax;
use Gaffer\Config;
use Gaffer\Console\Report;
use Gaffer\Editor;
use Gaffer\Forms\FormTemplates;
use Gaffer\Forms\Spam;
use Gaffer\Mcp\GafferCli;
use Gaffer\Storage;
use Gaffer\Turnstile;
use Gaffer\Types\Post;
use Gaffer\Types\Term;
use Gaffer\Vite;

/**
 * What a site's running code relies on outside Gaffer, tried for real so that a plugin
 * update or a server change shows up here instead of as lost form entries: Gravity Forms
 * still takes Gaffer's submissions (forms:probe on a throwaway form, not on production),
 * ajax.php answers over HTTP as Gaffer's dispatcher, the GET actions that need no arguments (or
 * only posts and terms) answer without a server error, the theme's private files aren't served,
 * WooCommerce's Store API doesn't list hidden products, the editor's iframe gets the theme's
 * editor.css without the admin's styles, and Turnstile answers arrive. Needs WordPress.
 */
final class ContractsCheck implements Check
{
    /** Days of spam.log read. */
    private const int DAYS = 14;

    /** turnstile-missing lines of one form in those days before it's a warning. */
    private const int MISSING = 5;

    #[\Override]
    public function run(Report $report): void
    {
        $this->private_files($report);
        $this->store_api($report);
        $this->editor_canvas($report);
        if ($this->ajax($report)) {
            $this->get_actions($report);
        }
        if (FormTemplates::all() === [] || !class_exists('GFAPI')) {
            return; // WordPressCheck::forms reports forms without Gravity Forms
        }
        $this->gravity_forms($report);
        $this->turnstile($report);
    }

    /**
     * With an editor.css entry, the editor's iframe as WordPress assembles it for the post editor
     * (the settings Gaffer's Editor filters): the theme's stylesheet in it, and no admin
     * stylesheet (wp-admin/css/, the editor reset) left. Both rest on what WordPress doesn't
     * promise (`__unstableResolvedAssets`, the handles): after a WordPress update they fail
     * quietly, as previews whose spacing no longer matches the site. Never breaks the editor.
     */
    private function editor_canvas(Report $report): void
    {
        if (Vite::url(Editor::ENTRY) === null || !function_exists('get_block_editor_settings')) {
            return;
        }
        require_once ABSPATH . 'wp-admin/includes/admin.php';
        $screen = $GLOBALS['current_screen'] ?? null;
        try {
            \set_current_screen('post'); // is_admin(), as on the post editor
            $settings = \get_block_editor_settings([], new \WP_Block_Editor_Context(['name' => 'core/edit-post']));
        } catch (\Throwable $e) {
            $report->warning('contracts', "The block editor's settings couldn't be read: {$e->getMessage()}");
            return;
        } finally {
            $GLOBALS['current_screen'] = $screen;
        }

        $hint = 'Block previews still render, but WordPress changed how it builds the editor\'s iframe: a Gaffer update adapts Editor.';
        $styles = $settings['__unstableResolvedAssets']['styles'] ?? null;
        if (!is_string($styles)) {
            $report->warning('contracts', "WordPress no longer gives the editor's iframe styles as __unstableResolvedAssets: the admin's styles stay in block previews", null, null, $hint);
            return;
        }
        if (!str_contains($styles, 'gaffer-editor-css')) {
            $report->warning('contracts', 'The editor\'s iframe doesn\'t get ' . Editor::ENTRY . ': block previews are unstyled', null, null, $hint);
        }
        preg_match_all('~<link\\b[^>]*\\bhref=([\'"])([^\'"]*(?:/wp-admin/css/|/reset[.-])[^\'"]*)\\1~', $styles, $m);
        if ($m[2] !== []) {
            $report->warning('contracts', 'The editor\'s iframe still loads admin styles: block previews\' spacing and type differ from the site', null, null,
                implode(', ', array_map(static fn(string $href): string => basename((string) parse_url($href, PHP_URL_PATH)), $m[2])) . '. ' . $hint);
        } else {
            $report->info('contracts', 'The editor\'s iframe loads ' . Editor::ENTRY . ' without the admin\'s styles');
        }
    }

    /**
     * Theme files a visitor must not get, fetched over HTTP like a visitor: the theme's
     * .htaccess (or the server's own rule) is what keeps them private, and a missing or emptied
     * one, or a server that doesn't read .htaccess (nginx), serves them as they are. A file
     * counts as served when the answer is its content (a "not found" page answered with 200
     * isn't). Secrets and visitors' files are errors; files that only reveal versions warnings.
     */
    private function private_files(Report $report): void
    {
        $theme = \get_template_directory();
        $uri = \get_template_directory_uri();
        $checks = []; // [file, url, secret, label]
        foreach (['.env' => true, '.git/HEAD' => true, 'composer.json' => false, 'composer.lock' => false, 'vendor/composer/installed.json' => false] as $relative => $secret) {
            $checks[] = ["{$theme}/{$relative}", "{$uri}/{$relative}", $secret, $relative];
        }
        // A file in a private folder (visitors' uploads, logs), without a leading dot: many servers
        // deny dotfiles on their own, which would hide a folder that's open otherwise.
        $probe = Storage::private_dir('logs') . '/doctor-probe.txt';
        $uploads = \wp_upload_dir(null, false);
        $probe_url = match (true) {
            str_starts_with($probe, $theme . '/') => $uri . substr($probe, strlen($theme)),
            str_starts_with($probe, rtrim((string) $uploads['basedir'], '/') . '/') => rtrim((string) $uploads['baseurl'], '/') . substr($probe, strlen(rtrim((string) $uploads['basedir'], '/'))),
            default => null,
        };
        if ($probe_url !== null && file_put_contents($probe, 'gaffer doctor ' . \wp_generate_password(20, false)) !== false) {
            $checks[] = [$probe, $probe_url, true, 'files in ' . dirname($probe, 2) . '/ (logs, visitors\' uploads)'];
        }
        $served = ['error' => [], 'warning' => []];
        foreach ($checks as [$file, $url, $secret, $label]) {
            if (!is_file($file) || filesize($file) > 5_000_000) {
                continue;
            }
            $response = \wp_remote_get($url, ['timeout' => 20, 'redirection' => 0, 'sslverify' => false, 'cookies' => []]);
            if (\is_wp_error($response)) {
                $report->warning('contracts', 'The site\'s private files weren\'t fetched over HTTP (' . $response->get_error_message() . '), so they weren\'t checked', null, null,
                    'doctor fetches them from this server (a loopback request): allow it, or check by hand that the theme\'s .env and uploads/gaffer/logs/ answer 403 or 404 to a browser.');
                $served = ['error' => [], 'warning' => []];
                break;
            }
            if ((int) \wp_remote_retrieve_response_code($response) === 200 && \wp_remote_retrieve_body($response) === file_get_contents($file)) {
                $served[$secret ? 'error' : 'warning'][] = $label;
            }
        }
        if (is_file($probe)) {
            unlink($probe);
        }
        $hint = 'Anyone can download them. On Apache the theme\'s .htaccess denies everything but public/, ajax.php and screenshot.png (the starter has one), and each of Gaffer\'s private folders has a deny .htaccess; other servers (nginx) need the same rules in their own configuration (the theme folder but those three, and /wp-content/uploads/gaffer/ or uploads/sites/*/gaffer/).';
        if ($served['error'] !== []) {
            $report->error('contracts', 'The web server serves private files: ' . implode(', ', $served['error']), null, null, $hint);
        }
        if ($served['warning'] !== []) {
            $report->warning('contracts', 'The web server serves theme files that show its versions: ' . implode(', ', $served['warning']), null, null, $hint);
        }
    }

    /**
     * WooCommerce's Store API lists products to anyone, hidden ones too when asked
     * (`catalog_visibility=hidden`): their names, links and prices, whatever the theme's
     * templates hide. Asked like a visitor without cookies; a warning, since a hidden product
     * may be meant to be reachable by its link.
     */
    private function store_api(Report $report): void
    {
        if (!class_exists('WooCommerce')) {
            return;
        }
        $url = \add_query_arg(['catalog_visibility' => 'hidden', 'per_page' => 1], \rest_url('wc/store/v1/products'));
        $response = \wp_remote_get($url, ['timeout' => 20, 'redirection' => 0, 'sslverify' => false, 'cookies' => []]);
        if (\is_wp_error($response) || (int) \wp_remote_retrieve_response_code($response) !== 200) {
            return; // the route is off or blocked: nothing listed
        }
        $hidden = (int) \wp_remote_retrieve_header($response, 'x-wp-total');
        if ($hidden > 0) {
            $report->warning('contracts', "WooCommerce's Store API lists {$hidden} " . ($hidden === 1 ? 'product' : 'products') . " the catalog hides, to anyone: names, links and prices", null, null,
                "{$url} answers without logging in (X-WP-Total). Products that must stay private must not be published (a draft or private post isn't listed); products meant for whoever has the link are listed with that link. Or block the route for visitors (rest_pre_dispatch) when nothing on the site uses it (the cart and checkout blocks do).");
        }
    }

    /**
     * ajax.php?action= (no action) is Gaffer's 400: anything else means the web server, a
     * security plugin or a host rule answers instead, and every ajax action and form fails.
     */
    private function ajax(Report $report): bool
    {
        if (!is_file(\get_template_directory() . '/ajax.php')) {
            return false;
        }
        $url = Ajax::url('');
        $response = \wp_remote_get($url, ['timeout' => 20, 'redirection' => 0, 'sslverify' => false, 'cookies' => []]);
        if (\is_wp_error($response)) {
            $report->warning('contracts', 'ajax.php wasn\'t fetched over HTTP (' . $response->get_error_message() . '), so it wasn\'t checked', null, null,
                'doctor fetches it from this server (a loopback request): allow it, or open ajax.php in a browser (a 400 answer is right; a 403 or 404 means the host blocks it and every action and form fails).');
            return false;
        }
        $status = (int) \wp_remote_retrieve_response_code($response);
        if ($status !== 400) {
            $report->error('contracts', 'ajax.php doesn\'t answer as Gaffer\'s dispatcher: ajax actions and forms fail', null, null,
                "{$url} answered HTTP {$status}, expected Gaffer's 400. A host rule or security plugin that blocks PHP files in wp-content/themes, a redirect, or a missing ajax.php.");
            return false;
        }

        return true;
    }

    /**
     * Calls every GET action whose run() needs no argument, or only posts and terms (route model
     * binding: the newest published post, or a used term, of the types mapped to that class), as
     * a visitor without cookies would (a GET changes nothing, so this is safe on any site): a
     * fatal after a Gaffer, plugin or WordPress update shows here instead of in a visitor's
     * browser. Actions with other required arguments, or POST, are left to the browser
     * (bin/dev/site-checks) and ajax:call.
     */
    private function get_actions(Report $report): void
    {
        $called = [];
        $skipped = [];
        foreach (self::callable_actions() as $name => $models) {
            $args = [];
            foreach ($models as $parameter => $class) {
                $id = self::example_id($class);
                if ($id === null) {
                    $skipped[] = $name;
                    continue 2;
                }
                $args[$parameter] = $id;
            }
            $url = \add_query_arg($args, Ajax::url($name));
            $response = \wp_remote_get($url, ['timeout' => 30, 'redirection' => 0, 'sslverify' => false, 'cookies' => [], 'headers' => ['HX-Request' => 'true']]);
            $called[] = $name . ($args === [] ? '' : ' (' . http_build_query($args, '', ', ') . ')');
            $status = \is_wp_error($response) ? 0 : (int) \wp_remote_retrieve_response_code($response);
            if ($status === 0 || $status >= 500) {
                $report->error('contracts', "The ajax action {$name} fails" . ($args === [] ? ' without arguments' : ''), \Gaffer\Paths::ajax() . "/{$name}/{$name}.php", null,
                    ($status === 0 ? 'No answer (' . (\is_wp_error($response) ? $response->get_error_message() : '') . ')' : "HTTP {$status}")
                    . ": a GET a visitor's browser makes ({$url}). Try `php gaffer ajax:call {$name}" . ($args === [] ? '' : ' ' . http_build_query($args, '', ' ')) . '` and read the PHP error log.');
            }
        }
        if ($called !== []) {
            $report->info('contracts', 'Called ' . count($called) . ' GET ajax action' . (count($called) === 1 ? '' : 's') . ' over HTTP: ' . implode(', ', $called)
                . ($skipped === [] ? '' : '; not ' . implode(', ', $skipped) . ' (no published post or term to pass)'));
        }
    }

    /**
     * The GET actions whose run() needs no argument, or only posts and terms: action name =>
     * its required parameters' classes (parameter name => Post or Term class).
     *
     * @return array<string, array<string, class-string<Post>|class-string<Term>>>
     */
    public static function callable_actions(): array
    {
        $actions = [];
        foreach (Ajax::actions() as $name) {
            $class = Ajax::action_class($name);
            if ($class === null || $class::METHOD !== 'GET' || !method_exists($class, 'run')) {
                continue;
            }
            $models = [];
            foreach ((new \ReflectionMethod($class, 'run'))->getParameters() as $parameter) {
                if ($parameter->isOptional()) {
                    continue;
                }
                $type = $parameter->getType();
                $type_name = $type instanceof \ReflectionNamedType ? $type->getName() : '';
                if (!is_a($type_name, Post::class, true) && !is_a($type_name, Term::class, true)) {
                    continue 2;
                }
                $models[$parameter->getName()] = $type_name;
            }
            $actions[$name] = $models;
        }

        return $actions;
    }

    /**
     * A public example of this class for a call: the newest published post of the post types
     * mapped to it (config theme.types), or a used term of its taxonomies (theme.terms). Post
     * and Term themselves stand for posts and categories.
     *
     * @param class-string<Post>|class-string<Term> $class
     */
    private static function example_id(string $class): ?int
    {
        $post = is_a($class, Post::class, true);
        $map = (array) (Config::get($post ? 'theme.types' : 'theme.terms') ?? []);
        $names = array_keys(array_filter($map, static fn(mixed $mapped): bool => is_string($mapped) && is_a($mapped, $class, true)));
        if ($class === Post::class || $class === Term::class) {
            $names[] = $post ? 'post' : 'category';
        }
        if ($names === []) {
            return null;
        }
        if ($post) {
            $ids = \get_posts(['post_type' => $names, 'post_status' => 'publish', 'has_password' => false, 'numberposts' => 1, 'orderby' => 'date', 'order' => 'DESC', 'fields' => 'ids', 'suppress_filters' => false]);
            $id = $ids[0] ?? null;
        } else {
            $ids = \get_terms(['taxonomy' => $names, 'hide_empty' => true, 'number' => 1, 'fields' => 'ids']);
            $id = is_array($ids) ? ($ids[0] ?? null) : null;
        }

        return is_int($id) && $class::from($id) !== null ? $id : null;
    }

    /** Gravity Forms takes Gaffer's submissions as Gaffer expects (one process per case). */
    private function gravity_forms(Report $report): void
    {
        if (\wp_get_environment_type() === 'production') {
            $report->info('contracts', 'Gravity Forms\' handling of Gaffer\'s submissions isn\'t probed on production (it writes and deletes a test form): run doctor --wp on a copy after plugin updates');
            return;
        }
        $entry = realpath((string) ($_SERVER['argv'][0] ?? '')) ?: null; // this Gaffer, also a dev clone's entry
        foreach (['valid', 'invalid'] as $case) {
            try {
                $stdout = GafferCli::run(['forms:probe', $case], $entry);
            } catch (\RuntimeException $e) {
                $report->warning('contracts', "Gravity Forms probe ({$case}) didn't run", null, null, $e->getMessage());
                continue;
            }
            $result = GafferCli::json_object($stdout);
            if (!is_array($result['problems'] ?? null)) {
                $report->warning('contracts', "Gravity Forms probe ({$case}) gave no answer", null, null, trim(substr($stdout, -400)));
                continue;
            }
            foreach ($result['problems'] as $problem) {
                $report->error('contracts', 'Gravity Forms no longer takes Gaffer\'s submissions as expected: ' . (is_string($problem) ? $problem : json_encode($problem)), null, null,
                    'Likely a Gravity Forms update: forms on this site lose or garble entries until Gaffer adapts. Report it to Gaffer (php gaffer forms:probe valid reproduces it).');
            }
        }
    }

    /**
     * Submissions without a Turnstile answer, per form: a widget that doesn't render (a form
     * shown in a way Gaffer's script doesn't see) makes every real visitor "spam".
     */
    private function turnstile(Report $report): void
    {
        $file = Storage::private_dir('logs') . '/spam.log';
        if (!Turnstile::enabled() || !is_file($file)) {
            return;
        }
        $since = (int) strtotime(\wp_date('Y-m-d H:i:s', time() - self::DAYS * 86400) . ' UTC');
        foreach (Spam::counts((string) file_get_contents($file), $since) as $form => $reasons) {
            $missing = $reasons['turnstile-missing'] ?? 0;
            if ($missing >= self::MISSING) {
                $report->warning('contracts', "Many submissions of \"{$form}\" came without a Turnstile answer", null, null,
                    "{$missing} in the last " . self::DAYS . " days (storage/logs/spam.log), answered as spam. Check that the widget renders on every page with the form (php gaffer render:screenshot <path>); bots without JavaScript send none too: baseline it when the widget works.");
            }
        }
    }
}
