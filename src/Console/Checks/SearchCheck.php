<?php

declare(strict_types=1);

namespace Gaffer\Console\Checks;

use Gaffer\Config;
use Gaffer\Console\ConfigStubs;
use Gaffer\Console\Report;
use Gaffer\Console\Templates;
use Gaffer\Console\ThemeFiles;
use Gaffer\Paths;
use Gaffer\Search\Indexes;

/**
 * config/search.php as far as Gaffer reads it (the card and its variants, the archive; the
 * engine's own keys are WordPressCheck::search()'s, the engine checks them), and theme code still
 * calling a search plugin's own theme API: gaffer-search's from before Gaffer\Search, or fuzor-wp's.
 */
final class SearchCheck implements Check
{
    private const array ARCHIVE_KEYS = ['index', 'per_page', 'sorts', 'default_sort', 'facets', 'redirect_search'];

    #[\Override]
    public function run(Report $report): void
    {
        $file = Paths::base('config/search.php');
        $templates = Templates::all();

        foreach (Indexes::all() as $name => $definition) {
            $card = $definition['card'] ?? null;
            if ($card !== null && (!is_string($card) || !isset($templates[$card]))) {
                $report->error('search', "Index \"{$name}\": card " . (is_string($card) ? "\"{$card}\" is not a template in views/" : 'must be a template name'), $file);
            }
            foreach (['card_context', 'card_variant'] as $key) {
                if (isset($definition[$key]) && !is_callable($definition[$key])) {
                    $report->error('search', "Index \"{$name}\": {$key} is not callable", $file, null, 'Use [Theme\\Class::class, \'method\'] with a public static method.');
                }
            }
            foreach ((array) ($definition['card_variants'] ?? []) as $variant => $wrap) {
                if (!is_callable($wrap)) {
                    $report->error('search', "Index \"{$name}\": card variant \"{$variant}\" is not callable", $file);
                }
            }
            if ((isset($definition['card_variants']) || isset($definition['card_context'])) && $card === null) {
                $report->warning('search', "Index \"{$name}\": card_variants and card_context do nothing without a card", $file);
            }
        }

        $archive = Config::get('search.archive');
        if (is_array($archive)) {
            $index = $archive['index'] ?? null;
            if (!is_string($index) || Indexes::get($index) === null) {
                $report->error('search', 'archive: index must name one of the indexes' . (is_string($index) ? " (\"{$index}\" isn't one)" : ''), $file);
            } elseif ((Indexes::get($index)['type'] ?? null) !== 'products') {
                $report->error('search', "archive: index \"{$index}\" isn't of type products (the archive is WooCommerce's shop)", $file);
            }
            foreach (array_diff(array_keys($archive), self::ARCHIVE_KEYS) as $key) {
                $suggestion = ConfigStubs::did_you_mean((string) $key, self::ARCHIVE_KEYS);
                $report->error('search', "archive: unknown key \"{$key}\"" . ($suggestion !== null ? " (did you mean \"{$suggestion}\"?)" : ''), $file);
            }
        }

        $this->old_api($report);
        $this->unavailable($report);
    }

    /**
     * Without the plugin, Search::archive() is an unavailable state: no products, and nothing
     * says why unless a template reads `available`.
     */
    private function unavailable(Report $report): void
    {
        $callers = array_values(array_filter(ThemeFiles::find(['php']), static fn(string $file): bool => str_contains((string) file_get_contents($file), 'Search::archive(')));
        if ($callers === []) {
            return;
        }
        foreach (Templates::all() as $file) {
            if (preg_match('/\.available\b/', (string) file_get_contents($file))) {
                return;
            }
        }
        $report->warning('search', 'No template reads search.available: without the gaffer-search plugin the shop shows no products and nothing says why', $callers[0], null,
            '{% if not search.available %}…{% endif %} in the archive template, e.g. "Products can\'t be shown right now".');
    }

    /**
     * The plugins' own theme APIs, replaced by config/search.php and Gaffer\Search: gaffer-search's
     * (gaffer_search_*() and its hooks, window.GafferSearch) and fuzor-wp's (fuzor_*() and its
     * hooks, window.Fuzor, data-fuzor-* attributes, FuzorSearch\ classes).
     */
    private function old_api(Report $report): void
    {
        $plugins = [
            'gaffer-search' => '/\bgaffer_search_\w+|window\.GafferSearch\b|\bGafferSearch\.\w+\(/',
            'fuzor-wp' => '/\bfuzor_\w+|window\.Fuzor\b|\bFuzor\.\w+\(|\bdata-fuzor-[\w-]+|\bFuzorSearch\\\\/',
        ];
        foreach (ThemeFiles::find(['php', 'twig', 'js']) as $file) {
            $code = (string) file_get_contents($file);
            foreach ($plugins as $plugin => $pattern) {
                if (preg_match_all($pattern, $code, $matches, PREG_OFFSET_CAPTURE) > 0) {
                    $first = $matches[0][0];
                    $report->warning('search', "Uses the {$plugin} plugin's own theme API (replaced by Gaffer\\Search)", $file, substr_count($code, "\n", 0, $first[1]) + 1,
                        "{$first[0]}" . (count($matches[0]) > 1 ? ' and ' . (count($matches[0]) - 1) . ' more' : '') . ': move to config/search.php, Gaffer\\Search\\Search and resources/js/search.js (see the gaffer-search skill and CHANGELOG.md).');
                }
            }
        }
    }
}
