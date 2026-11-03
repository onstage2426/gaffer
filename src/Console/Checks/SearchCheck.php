<?php

declare(strict_types=1);

namespace Gaffer\Console\Checks;

use Gaffer\Config;
use Gaffer\Console\ConfigStubs;
use Gaffer\Console\Report;
use Gaffer\Console\Templates;
use Gaffer\Console\ThemeFiles;
use Gaffer\Paths;
use Gaffer\Search\ArchiveUrl;
use Gaffer\Search\Indexes;

/**
 * config/search.php as far as Gaffer reads it (the card and its variants, the archive; the
 * engine's own keys are WordPressCheck::search()'s, the engine checks them), and theme code still
 * calling a search plugin's own theme API: gaffer-search's from before Gaffer\Search, or fuzor-wp's.
 */
final class SearchCheck implements Check
{
    /** The archive's keys: what it searches, then its groups (key => the group's keys). */
    private const array ARCHIVE_KEYS = ['index', 'per_page', 'limits', 'sorts', 'default_sort', 'facets', 'facet_values', 'max_docs', 'bounds'];
    private const array ARCHIVE_GROUPS = ['seo' => ['index_pages', 'indexable_filters'], 'urls' => ['base_params', 'redirect_params', 'redirect_search']];

    #[\Override]
    public function run(Report $report): void
    {
        $file = Paths::base('config/search.php');
        $templates = Templates::all();

        foreach (Indexes::all() as $name => $definition) {
            if (isset($definition['card'])) {
                $report->error('search', "Index \"{$name}\": card is now cards (layout => template, the first the default)", $file, null,
                    "'cards' => ['grid' => " . var_export($definition['card'], true) . ']');
            }
            foreach (['card_context', 'card_variant', 'document'] as $key) {
                if (isset($definition[$key]) && !is_callable($definition[$key])) {
                    $report->error('search', "Index \"{$name}\": {$key} is not callable", $file, null, 'Use [Theme\\Class::class, \'method\'] with a public static method.');
                }
            }
            foreach ((array) ($definition['card_variants'] ?? []) as $variant => $wrap) {
                if (!is_callable($wrap)) {
                    $report->error('search', "Index \"{$name}\": card variant \"{$variant}\" is not callable", $file);
                }
            }
            $cards = $definition['cards'] ?? [];
            foreach (is_array($cards) ? $cards : [0 => $cards] as $view => $template) {
                if ($cards === []) {
                    break;
                }
                if (!is_string($view) || !preg_match('/^[a-z0-9_]+$/', $view)) {
                    $report->error('search', "Index \"{$name}\": cards maps a layout name ([a-z0-9_]+, ?view=) to a template", $file, null, "'cards' => ['grid' => 'product/card.twig', 'list' => 'product/list-item.twig']");
                } elseif (!is_string($template) || !isset($templates[$template])) {
                    $report->error('search', "Index \"{$name}\": cards \"{$view}\" " . (is_string($template) ? "\"{$template}\" is not a template in views/" : 'must be a template name'), $file);
                } elseif (isset(((array) ($definition['card_variants'] ?? []))[$view])) {
                    $report->error('search', "Index \"{$name}\": \"{$view}\" is a view in cards and a card variant: their fields would be the same (card_{$view})", $file);
                }
            }
            if ((isset($definition['card_variants']) || isset($definition['card_context'])) && Indexes::templates($definition) === []) {
                $report->warning('search', "Index \"{$name}\": card_variants and card_context do nothing without cards", $file);
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
            foreach (self::unknown_keys($archive) as $problem) {
                $report->error('search', "archive: {$problem}", $file);
            }
            foreach (self::archive_problems($archive) as $problem) {
                $report->error('search', "archive: {$problem}", $file);
            }
        }

        $this->old_api($report);
        $this->unavailable($report);
    }

    /**
     * The shapes of the archive's optional keys (the engine ignores a request key it can't read,
     * so a wrong shape would silently do nothing).
     *
     * @param array<mixed> $archive
     * @return list<string>
     */
    private static function archive_problems(array $archive): array
    {
        $seo = is_array($archive['seo'] ?? null) ? $archive['seo'] : [];
        $urls = is_array($archive['urls'] ?? null) ? $archive['urls'] : [];
        $problems = [];
        $ints = static fn(mixed $list): bool => is_array($list) && array_is_list($list) && $list !== [] && array_filter($list, is_int(...)) === $list;
        if (isset($archive['limits'])) {
            if (!$ints($archive['limits'])) {
                $problems[] = 'limits must be a list of page sizes (ints)';
            } elseif (!in_array((int) ($archive['per_page'] ?? 24), $archive['limits'], true)) {
                $problems[] = 'limits must include per_page (' . (int) ($archive['per_page'] ?? 24) . '): the first page uses it';
            }
        }
        if (isset($archive['facet_values']) && (!is_array($archive['facet_values']) || array_filter($archive['facet_values'], is_int(...)) !== $archive['facet_values'] || array_is_list($archive['facet_values']))) {
            $problems[] = 'facet_values must map a field to a number of values';
        }
        if (isset($archive['max_docs']) && !is_int($archive['max_docs'])) {
            $problems[] = 'max_docs must be an int';
        }
        if (isset($seo['index_pages']) && !is_bool($seo['index_pages'])) {
            $problems[] = 'seo.index_pages must be true or false';
        }
        if (isset($seo['indexable_filters']) && (!is_array($seo['indexable_filters']) || array_is_list($seo['indexable_filters'])
            || array_filter($seo['indexable_filters'], static fn(mixed $values): bool => is_array($values) && array_is_list($values) && array_filter($values, is_string(...)) === $values) !== $seo['indexable_filters'])) {
            $problems[] = 'seo.indexable_filters must map a field to a list of values (strings), e.g. [\'on_sale\' => [\'yes\']]';
        }
        if (isset($urls['base_params']) && (!is_array($urls['base_params']) || !array_is_list($urls['base_params']) || array_filter($urls['base_params'], is_string(...)) !== $urls['base_params'])) {
            $problems[] = 'urls.base_params must be a list of query param names';
        }
        foreach (array_intersect((array) ($urls['base_params'] ?? []), [...ArchiveUrl::PARAMS, 'view']) as $param) {
            $problems[] = "urls.base_params can't name the archive's own param \"{$param}\"";
        }
        if (isset($archive['bounds']) && (!is_array($archive['bounds']) || !array_is_list($archive['bounds']) || array_filter($archive['bounds'], is_string(...)) !== $archive['bounds'])) {
            $problems[] = 'bounds must be a list of numeric fields, e.g. [\'price\']';
        }
        if (isset($urls['redirect_params']) && !is_callable($urls['redirect_params'])) {
            $problems[] = 'urls.redirect_params is not callable: use [Theme\\Class::class, \'method\'] with a public static method';
        }
        if (isset($urls['redirect_search']) && !is_bool($urls['redirect_search'])) {
            $problems[] = 'urls.redirect_search must be true or false';
        }

        return $problems;
    }

    /**
     * Keys the archive doesn't have, with where a known one belongs now (seo, urls).
     *
     * @param array<mixed> $archive
     * @return list<string>
     */
    private static function unknown_keys(array $archive): array
    {
        $problems = [];
        foreach (array_diff(array_keys($archive), [...self::ARCHIVE_KEYS, ...array_keys(self::ARCHIVE_GROUPS)]) as $key) {
            $group = array_key_first(array_filter(self::ARCHIVE_GROUPS, static fn(array $keys): bool => in_array($key, $keys, true)));
            $suggestion = ConfigStubs::did_you_mean((string) $key, [...self::ARCHIVE_KEYS, ...array_keys(self::ARCHIVE_GROUPS)]);
            $problems[] = $group !== null ? "\"{$key}\" belongs in \"{$group}\" now ('{$group}' => ['{$key}' => …])"
                : "unknown key \"{$key}\"" . ($suggestion !== null ? " (did you mean \"{$suggestion}\"?)" : '');
        }
        foreach (self::ARCHIVE_GROUPS as $group => $keys) {
            if (isset($archive[$group]) && !is_array($archive[$group])) {
                $problems[] = "{$group} must be a map of " . implode(', ', $keys);
                continue;
            }
            foreach (array_diff(array_keys((array) ($archive[$group] ?? [])), $keys) as $key) {
                $suggestion = ConfigStubs::did_you_mean((string) $key, $keys);
                $problems[] = "unknown key \"{$group}.{$key}\"" . ($suggestion !== null ? " (did you mean \"{$suggestion}\"?)" : '');
            }
        }

        return $problems;
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
