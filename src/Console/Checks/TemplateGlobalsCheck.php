<?php

declare(strict_types=1);

namespace Gaffer\Console\Checks;

use Gaffer\Console\Report;
use Gaffer\Console\ThemeFiles;
use Gaffer\Paths;
use PhpToken;

/**
 * WordPress template files (the theme root, woocommerce/) run with WordPress's
 * globals in scope: load_template() declares $post, $wp_query, ... global, and
 * WooCommerce's templates declare `global $product`. Assigning one there
 * replaces WordPress's own (a fatal error further down the page, or the wrong
 * post for every plugin after it).
 */
final class TemplateGlobalsCheck implements Check
{
    /** The globals load_template() brings into a template file. */
    private const array GLOBALS = ['posts', 'post', 'wp_did_header', 'wp_query', 'wp_rewrite', 'wpdb', 'wp_version', 'wp', 'id', 'comment', 'user_ID'];

    /** Theme root files WordPress doesn't load as templates. */
    private const array NOT_TEMPLATES = ['functions.php', 'ajax.php'];

    #[\Override]
    public function run(Report $report): void
    {
        $files = [
            ...array_filter(glob(Paths::base('*.php')) ?: [], static fn(string $f): bool => !in_array(basename($f), self::NOT_TEMPLATES, true)),
            ...(is_dir(Paths::base('woocommerce')) ? ThemeFiles::find(['php'], Paths::base('woocommerce')) : []),
        ];

        foreach ($files as $file) {
            foreach (self::assignments((string) file_get_contents($file)) as [$name, $line]) {
                $report->warning('globals', "Assigns \${$name}, a WordPress global in template files", $file, $line,
                    "Use another name (e.g. \$page, \$item): this replaces WordPress's own \${$name} for the rest of the request.");
            }
        }
    }

    /**
     * Assignments (`=`, `??=`, `foreach (... as $x)`) to WordPress's template
     * globals or a variable the file declares `global`, outside functions and
     * closures (their variables are local).
     *
     * @return list<array{string, int}> name and line
     */
    public static function assignments(string $code): array
    {
        $tokens = array_values(array_filter(PhpToken::tokenize($code), static fn(PhpToken $t): bool => !$t->isIgnorable()));
        $globals = self::GLOBALS;
        $found = [];
        $depth = 0;
        $function_depths = []; // brace depth at which each enclosing function body started

        foreach ($tokens as $i => $token) {
            if ($token->text === '{' || $token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;
                if (self::opens_function_body($tokens, $i)) {
                    $function_depths[] = $depth;
                }
                continue;
            }
            if ($token->text === '}') {
                if (end($function_depths) === $depth) {
                    array_pop($function_depths);
                }
                $depth--;
                continue;
            }
            if ($function_depths !== [] || !$token->is(T_VARIABLE)) {
                continue;
            }

            $name = substr($token->text, 1);
            $before = $tokens[$i - 1] ?? null;
            if ($before?->is(T_GLOBAL) || ($before?->text === ',' && self::in_global_statement($tokens, $i))) {
                $globals[] = $name;
                continue;
            }

            $next = $tokens[$i + 1] ?? null;
            $assigned = ($next !== null && ($next->text === '=' || $next->is(T_COALESCE_EQUAL)))
                || ($before?->is(T_AS) ?? false)
                || ($before?->is(T_DOUBLE_ARROW) && self::in_foreach_as($tokens, $i));
            if ($assigned && in_array($name, $globals, true)) {
                $found[] = [$name, $token->line];
            }
        }

        return $found;
    }

    /**
     * Whether the { at $index starts a function or closure body: walk back over
     * the signature (return type, `use (...)`, parameters) to `function`.
     *
     * @param list<PhpToken> $tokens
     */
    private static function opens_function_body(array $tokens, int $index): bool
    {
        $parens = 0;
        for ($i = $index - 1; $i >= 0; $i--) {
            $token = $tokens[$i];
            $parens += match ($token->text) { ')' => 1, '(' => -1, default => 0 };
            if ($parens > 0 || $token->text === '(') { // inside the parameters or use (...)
                continue;
            }
            if ($token->is(T_FUNCTION)) {
                return true;
            }
            if ($token->text === ';' || $token->text === '{' || $token->text === '}') {
                return false;
            }
        }

        return false;
    }

    /** @param list<PhpToken> $tokens */
    private static function in_global_statement(array $tokens, int $index): bool
    {
        for ($i = $index - 1; $i >= 0 && ($tokens[$i]->text === ',' || $tokens[$i]->is(T_VARIABLE)); $i--);

        return $i >= 0 && $tokens[$i]->is(T_GLOBAL);
    }

    /**
     * Whether `=> $x` at $index is the value of a `foreach (... as $k => $x)`.
     *
     * @param list<PhpToken> $tokens
     */
    private static function in_foreach_as(array $tokens, int $index): bool
    {
        $key = $tokens[$index - 2] ?? null;
        $as = $tokens[$index - 3] ?? null;

        return ($key?->is(T_VARIABLE) ?? false) && ($as?->is(T_AS) ?? false);
    }
}
