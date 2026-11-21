<?php

declare(strict_types=1);

namespace Gaffer\Console\Checks;

use Gaffer\Ajax;
use Gaffer\Console\Report;
use Gaffer\Forms\FormTemplates;
use Gaffer\Forms\Spam;
use Gaffer\Mcp\GafferCli;
use Gaffer\Storage;
use Gaffer\Turnstile;

/**
 * What a site's running code relies on outside Gaffer, tried for real so that a plugin
 * update or a server change shows up here instead of as lost form entries: Gravity Forms
 * still takes Gaffer's submissions (forms:probe on a throwaway form, not on production),
 * ajax.php answers over HTTP as Gaffer's dispatcher, the GET actions that need no arguments
 * answer without a server error, and Turnstile answers arrive.
 * Needs WordPress.
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
            $report->info('contracts', 'ajax.php wasn\'t fetched over HTTP (' . $response->get_error_message() . '), so it wasn\'t checked');
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
     * Calls every GET action whose run() needs no argument, as a visitor without cookies would
     * (a GET changes nothing, so this is safe on any site): a fatal after a Gaffer, plugin or
     * WordPress update shows here instead of in a visitor's browser. Actions with required
     * arguments or POST are left to the browser (bin/dev/site-checks) and ajax:call.
     */
    private function get_actions(Report $report): void
    {
        $called = 0;
        foreach (self::callable_actions() as $name) {
            $response = \wp_remote_get(Ajax::url($name), ['timeout' => 30, 'redirection' => 0, 'sslverify' => false, 'cookies' => [], 'headers' => ['HX-Request' => 'true']]);
            $called++;
            $status = \is_wp_error($response) ? 0 : (int) \wp_remote_retrieve_response_code($response);
            if ($status === 0 || $status >= 500) {
                $report->error('contracts', "The ajax action {$name} fails without arguments", \Gaffer\Paths::ajax() . "/{$name}/{$name}.php", null,
                    ($status === 0 ? 'No answer (' . (\is_wp_error($response) ? $response->get_error_message() : '') . ')' : "HTTP {$status}")
                    . ": a GET a visitor's browser makes. Try `php gaffer ajax:call {$name}` and read the PHP error log.");
            }
        }
        if ($called > 0) {
            $report->info('contracts', "Called {$called} GET ajax action" . ($called === 1 ? '' : 's') . ' without arguments over HTTP');
        }
    }

    /**
     * The GET actions whose run() has no required parameter.
     *
     * @return list<string>
     */
    public static function callable_actions(): array
    {
        $names = [];
        foreach (Ajax::actions() as $name) {
            $class = Ajax::action_class($name);
            if ($class === null || $class::METHOD !== 'GET' || !method_exists($class, 'run')) {
                continue;
            }
            if ((new \ReflectionMethod($class, 'run'))->getNumberOfRequiredParameters() === 0) {
                $names[] = $name;
            }
        }

        return $names;
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
