<?php

declare(strict_types=1);

namespace Gaffer\Console\Checks;

use Gaffer\Console\Report;
use Gaffer\Console\ThemeFiles;
use Gaffer\Paths;
use PhpToken;

/**
 * HTML in the theme's PHP (inline HTML, strings, heredocs) outside the
 * header.php/footer.php document shell: markup belongs in Twig.
 */
final class MarkupCheck implements Check
{
    private const array SHELL = ['header.php', 'footer.php'];

    private const string TAG = '/<\/?[a-z][a-z0-9-]*(?:\s|>|\/>)/i';

    #[\Override]
    public function run(Report $report): void
    {
        foreach (ThemeFiles::find(['php']) as $file) {
            $relative = substr($file, strlen(Paths::base()) + 1);
            if (in_array($relative, self::SHELL, true)) {
                continue;
            }

            $lines = [];
            foreach (PhpToken::tokenize((string) file_get_contents($file)) as $token) {
                if ($token->is([T_INLINE_HTML, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE]) && preg_match(self::TAG, $token->text)) {
                    $lines[$token->line] = true;
                }
            }
            if ($lines !== []) {
                $report->warning('views', 'HTML in PHP (line ' . implode(', ', array_keys($lines)) . ')', $file, array_key_first($lines),
                    'Move the markup to a Twig template and render it with View::render() / View::fetch().');
            }
        }
    }
}
