<?php

declare(strict_types=1);

namespace Gaffer\Console\Checks;

use Gaffer\Console\Report;
use Gaffer\Console\ThemeFiles;
use Gaffer\Paths;
use PhpToken;

/**
 * Method names in app/ are snake_case, except #[\Override] implementations of
 * a library's interface and PHP's magic methods. Twig functions and filters
 * only shape values: one that looks content up (WordPress, ACF, plugins,
 * Gaffer's type factories) should be data PHP passes in.
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
            $code = (string) file_get_contents($file);
            foreach (self::camel_case_methods($code) as [$name, $line]) {
                $report->warning('app', "Method {$name}() isn't snake_case", $file, $line,
                    'Rename it (and its callers); library interface methods keep their name with #[\\Override].');
            }
            foreach (self::twig_lookups($code) as [$method, $call, $line]) {
                $report->warning('app', "Twig function/filter {$method}() calls {$call}(): it looks content up", $file, $line,
                    'Twig extensions only shape values: get the data in PHP (a type method, the template file, View::share()) and pass it in.');
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

    /** Calls that look content up, by function name. */
    private const string LOOKUP_FUNCTION = '/^(get_(field|fields|sub_field|option|post|posts|post_meta|post_thumbnail_id|the_\w+|term|terms|term_meta|term_by|page_by_path|permalink|nav_menu_locations|queried_object)|wp_get_\w+|have_posts|do_shortcode|wc_get_\w+|WC|fuzor_\w+|acf_\w+)$/';

    /** Classes whose static calls look content up. */
    private const array LOOKUP_CLASS = ['Post', 'Term', 'Image', 'Attachment', 'Menu', 'Pagination', 'Acf', 'GFAPI', 'WC_Product_Factory'];

    /**
     * Twig functions/filters/tests (#[AsTwig...] methods) that look content up:
     * the method, the first lookup call and its line.
     *
     * @return list<array{string, string, int}>
     */
    public static function twig_lookups(string $code): array
    {
        $tokens = array_values(array_filter(PhpToken::tokenize($code), static fn(PhpToken $t): bool => !$t->isIgnorable()));
        $found = [];

        foreach ($tokens as $i => $token) {
            $name = $tokens[$i + 1] ?? null;
            if (!$token->is(T_FUNCTION) || $name === null || !$name->is(T_STRING) || !self::twig_callable($tokens, $i)) {
                continue;
            }
            // The body: from the first { after the signature to its matching }.
            for ($start = $i; isset($tokens[$start]) && $tokens[$start]->text !== '{'; $start++);
            for ($depth = 0, $j = $start; isset($tokens[$j]); $j++) {
                $depth += match ($tokens[$j]->text) { '{' => 1, '}' => -1, default => 0 };
                $call = self::lookup_call($tokens, $j);
                if ($call !== null) {
                    $found[] = [$name->text, $call, $tokens[$j]->line];
                    break;
                }
                if ($depth === 0 && $j > $start) {
                    break;
                }
            }
        }

        return $found;
    }

    /**
     * The lookup called at $index (a function name, a factory's Class::method,
     * new WP_Query), or null.
     *
     * @param list<PhpToken> $tokens
     */
    private static function lookup_call(array $tokens, int $index): ?string
    {
        $token = $tokens[$index];
        $next = $tokens[$index + 1]->text ?? '';
        $before = $tokens[$index - 1] ?? null;
        $name = ltrim($token->text, '\\');

        return match (true) {
            !$token->is([T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED]) => null,
            $next === '(' && !($before?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW]) ?? false)
                && preg_match(self::LOOKUP_FUNCTION, $name) === 1 => $name,
            $next === '::' && !in_array(strtolower($name), ['self', 'static', 'parent'], true) && (
                in_array(substr((string) strrchr('\\' . $name, '\\'), 1), self::LOOKUP_CLASS, true)
                // A type factory on any class, theme types included (Product::from()).
                || in_array($tokens[$index + 2]->text ?? '', ['from', 'query', 'current', 'main_query', 'location'], true)
            ) => substr((string) strrchr('\\' . $name, '\\'), 1) . '::' . ($tokens[$index + 2]->text ?? ''),
            ($before?->is(T_NEW) ?? false) && in_array($name, ['WP_Query', 'WP_Term_Query'], true) => "new {$name}",
            default => null,
        };
    }

    /**
     * Whether the declaration before $index has an #[AsTwigFunction], #[AsTwigFilter] or #[AsTwigTest] attribute.
     *
     * @param list<PhpToken> $tokens
     */
    private static function twig_callable(array $tokens, int $index): bool
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
                if (preg_match('/(^|\\\\)AsTwig(Function|Filter|Test)$/', $tokens[$j]->text) === 1) {
                    return true;
                }
            }
            $i = $j;
        }

        return false;
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
