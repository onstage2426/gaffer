<?php

declare(strict_types=1);

namespace Gaffer\Console\Checks;

use Gaffer\Console\Report;
use Gaffer\Console\ThemeFiles;
use Gaffer\Paths;
use PhpToken;

/**
 * inc/ only registers hooks: functions and classes declared there are global,
 * need guards and depend on load order. They belong in app/.
 */
final class IncCheck implements Check
{
    #[\Override]
    public function run(Report $report): void
    {
        if (!is_dir(Paths::includes())) {
            return;
        }

        foreach (ThemeFiles::find(['php'], Paths::includes()) as $file) {
            foreach (self::declarations((string) file_get_contents($file)) as [$kind, $name, $line]) {
                $report->warning('inc', "Declares {$kind} {$name}", $file, $line,
                    'inc/ only registers hooks: move it to a class in app/ (Theme\\..., autoloaded) and call that from the hook.');
            }
        }
    }

    /**
     * Named functions and classes/interfaces/traits/enums (not closures, not `new class`).
     *
     * @return list<array{string, string, int}>
     */
    public static function declarations(string $code): array
    {
        $tokens = array_values(array_filter(PhpToken::tokenize($code), static fn(PhpToken $t): bool => !$t->isIgnorable()));
        $found = [];

        foreach ($tokens as $i => $token) {
            $next = $tokens[$i + 1] ?? null;
            $previous = $tokens[$i - 1] ?? null;
            if ($next === null || !$next->is(T_STRING)) {
                continue;
            }
            if ($token->is(T_FUNCTION) && !($previous?->is([T_FN, T_USE]) ?? false)) {
                $found[] = ['function', $next->text . '()', $token->line];
            } elseif ($token->is([T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM]) && !($previous?->is([T_NEW, T_DOUBLE_COLON]) ?? false)) {
                $found[] = [strtolower(substr($token->getTokenName() ?? 'T_CLASS', 2)), $next->text, $token->line];
            }
        }

        return $found;
    }
}
