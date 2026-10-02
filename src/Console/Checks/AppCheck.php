<?php

declare(strict_types=1);

namespace Gaffer\Console\Checks;

use Gaffer\Console\Report;
use Gaffer\Console\ThemeFiles;
use Gaffer\Paths;
use PhpToken;

/**
 * Method names in app/ are snake_case, except #[\Override] implementations of
 * a library's interface and PHP's magic methods.
 */
final class AppCheck implements Check
{
    #[\Override]
    public function run(Report $report): void
    {
        $dir = Paths::base('app');
        if (!is_dir($dir)) {
            return;
        }

        foreach (ThemeFiles::find(['php'], $dir) as $file) {
            foreach (self::camel_case_methods((string) file_get_contents($file)) as [$name, $line]) {
                $report->warning('app', "Method {$name}() isn't snake_case", $file, $line,
                    'Rename it (and its callers); library interface methods keep their name with #[\\Override].');
            }
        }
    }

    /**
     * @return list<array{string, int}> name and line of each method with an uppercase letter
     */
    public static function camel_case_methods(string $code): array
    {
        $tokens = array_values(array_filter(PhpToken::tokenize($code), static fn(PhpToken $t): bool => !$t->isIgnorable()));
        $found = [];

        foreach ($tokens as $i => $token) {
            $name = $tokens[$i + 1] ?? null;
            if (!$token->is(T_FUNCTION) || $name === null || !$name->is(T_STRING)
                || str_starts_with($name->text, '__') || !preg_match('/[A-Z]/', $name->text)
                || self::overrides($tokens, $i)) {
                continue;
            }
            $found[] = [$name->text, $name->line];
        }

        return $found;
    }

    /**
     * Whether the declaration before $index carries #[\Override] (walking back over
     * modifiers and attributes).
     *
     * @param list<PhpToken> $tokens
     */
    private static function overrides(array $tokens, int $index): bool
    {
        for ($i = $index - 1; $i >= 0; $i--) {
            $token = $tokens[$i];
            if ($token->is([T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_FINAL, T_ABSTRACT])) {
                continue;
            }
            if ($token->text !== ']') {
                return false;
            }
            for ($j = $i; $j >= 0 && !$tokens[$j]->is(T_ATTRIBUTE); $j--) {
                if (in_array(ltrim($tokens[$j]->text, '\\'), ['Override'], true)) {
                    return true;
                }
            }
            $i = $j; // continue before this attribute
        }

        return false;
    }
}
