<?php

declare(strict_types=1);

namespace Gaffer\Console\Checks;

use Gaffer\Console\Report;
use Gaffer\Console\Templates;
use Gaffer\Console\ThemeFiles;
use Gaffer\Forms\FormTemplates;
use Gaffer\Gaffer;
use Gaffer\Paths;

/**
 * Forms send what Gaffer checks through Gaffer's own partial (@gaffer/forms/meta.twig:
 * the form key, the honeypot, the Turnstile widget), and Gaffer loads Turnstile's script:
 * a theme that writes either half by hand breaks when Gaffer changes the other half, and a
 * form without the widget has every real submission answered as spam.
 */
final class FormsCheck implements Check
{
    public const string PARTIAL = '@gaffer/forms/meta.twig';

    /** What only Gaffer's partial writes, as a pattern => what it is. */
    private const array BY_HAND = [
        '/\bname=["\'](?:website|form|form_name|turnstile)["\']/' => 'a form key Gaffer reads (form_name, website, turnstile)',
        '/\bcf-turnstile\b|data-response-field-name/' => 'the Turnstile widget',
    ];

    #[\Override]
    public function run(Report $report): void
    {
        Gaffer::twig();
        foreach (FormTemplates::all() as $name => $file) {
            if (!in_array(self::PARTIAL, Templates::brought_in("forms/{$name}.twig"), true)) {
                $report->error('forms', 'Doesn\'t include ' . self::PARTIAL . ': Gaffer refuses the form, or answers every submission as spam', $file, null,
                    "Inside the <form>: {{ include('" . self::PARTIAL . "', { form: form }, with_context = false) }}");
            }
        }

        foreach (Templates::all() as $file) {
            $code = (string) preg_replace('/\{#.*?#\}/s', '', (string) file_get_contents($file));
            foreach (self::BY_HAND as $pattern => $what) {
                if (preg_match($pattern, $code, $m, PREG_OFFSET_CAPTURE)) {
                    $report->error('forms', "Writes {$what} by hand", $file, substr_count($code, "\n", 0, $m[0][1]) + 1,
                        'Gaffer writes it: ' . self::PARTIAL . ' (options: turnstile: { appearance, language, theme, size, class }).');
                }
            }
        }

        foreach (ThemeFiles::find(['php'], Paths::ajax()) as $file) {
            $code = (string) file_get_contents($file);
            if (preg_match('/\$(?:website|turnstile)\b|\$_(?:POST|REQUEST)\[\s*[\'"](?:website|turnstile|form_name)[\'"]/', $code, $m, PREG_OFFSET_CAPTURE)) {
                $report->warning('forms', 'Reads Gaffer\'s honeypot or Turnstile field itself', $file, substr_count($code, "\n", 0, $m[0][1]) + 1,
                    'Their names may change in any release: drop those parameters and call Forms\\Spam::check(\'form\') (null, or the reason it logged; answer spam as if it worked).');
            }
        }

        // An action that stores or mails what visitors send, by e-mail address, without a spam
        // check: bots fill it (a list that grows without end, mails to strangers' addresses).
        foreach (glob(Paths::ajax() . '/*/*.php') ?: [] as $file) {
            $action = basename(dirname($file));
            if (basename($file, '.php') !== $action) {
                continue;
            }
            $code = (string) file_get_contents($file);
            if (preg_match('/function\s+run\s*\(([^)]*)\)/', $code, $run, PREG_OFFSET_CAPTURE) !== 1
                || preg_match('/\b(?:string|array)\s+\$\w*e_?mail\w*\b/i', $run[1][0]) !== 1
                || preg_match('/METHOD\s*=\s*[\'"]GET[\'"]/', $code) === 1
                || preg_match('/extends\s+\\\\?(?:Gaffer\\\\Forms\\\\)?FormAction\b/', $code) === 1) {
                continue;
            }
            $folder = implode('', array_map(static fn(string $f): string => (string) file_get_contents($f), glob(dirname($file) . '/*.php') ?: []));
            if (!str_contains($folder, 'Spam::check(')) {
                $report->warning('forms', 'Takes an e-mail address without a spam check', $file, substr_count($code, "\n", 0, $run[0][1]) + 1,
                    'Bots fill such forms. Its <form> includes ' . self::PARTIAL . " with FormState::blank('{$action}'), and run() starts with `if (Forms\\Spam::check('{$action}') !== null)`: answer as if it worked, without storing or sending anything.");
            }
        }

        foreach (ThemeFiles::find(['php', 'js', 'mjs', 'ts']) as $file) {
            if (str_contains((string) file_get_contents($file), 'challenges.cloudflare.com/turnstile')) {
                $report->error('forms', 'Loads or renders Turnstile itself', $file, null,
                    'Gaffer loads Cloudflare\'s script and renders the widgets (also after htmx swaps) when Turnstile is on: remove this.');
            }
        }
    }
}
