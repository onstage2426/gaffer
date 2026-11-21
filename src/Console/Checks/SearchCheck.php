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
 * config/search.php as far as Gaffer reads it (the cards and their variants, the archive; the
 * engine's own keys are WordPressCheck::search()'s, the engine checks them), and an archive no
 * template says is unavailable.
 */
final class SearchCheck implements Check
{
    /** The archive's keys: what it searches, then its groups (key => the group's keys). */
    private const array ARCHIVE_KEYS = ['index', 'per_page', 'limits', 'sorts', 'default_sort', 'facets', 'facet_values', 'max_docs', 'bounds', 'eager'];
    private const array ARCHIVE_GROUPS = ['seo' => ['index_pages', 'indexable_filters'], 'urls' => ['base_params', 'redirect_params', 'redirect_search']];

    #[\Override]
    public function run(Report $report): void
    {
        $file = Paths::base('config/search.php');
        $templates = Templates::all();

        foreach (Indexes::all() as $name => $definition) {
            foreach (['card_context', 'card_variant', 'document', 'labels'] as $key) {
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
            if (Indexes::templates($definition) !== [] && in_array($definition['rebuild'] ?? '', ['', null], true)) {
                $report->warning('search', "Index \"{$name}\": stored cards without a rebuild schedule", $file, null,
                    'A card shows more than its product (a brand\'s logo, an option, a price rule in a theme class): when that changes, nothing rebuilds the cards, and they stay as they were. '
                    . "'rebuild' => 'daily' (or 'twicedaily') renders them again each night; the old index answers while it builds.");
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
            if (is_string($index) && ($definition = Indexes::get($index)) !== null) {
                foreach (self::unsortable($archive, $definition) as $field => $hint) {
                    $report->warning('search', "archive: sorts on \"{$field}\", which index \"{$index}\" doesn't sort on (the engine ignores that sort)", $file, null, $hint);
                }
            }
        }

        $this->unavailable($report);
        $this->unfiltered($report);
        $this->by_hand($report, $templates);
        $this->unshown_errors($report, $templates);
    }

    /**
     * A search form Gaffer's JavaScript runs (a template with x-data printing a search object's
     * attrs()) whose template, with what it includes by fixed name, never mentions `error`: when the
     * endpoint fails (an index rebuilding, a stale page's token) the component's `error` is set
     * and nothing shows it, so the visitor sees "nothing found" or the last results.
     *
     * @param array<string, string> $templates
     */
    private function unshown_errors(Report $report, array $templates): void
    {
        $code_of = static fn(string $file): string => (string) preg_replace('/\{#.*?#\}/s', '', (string) file_get_contents($file));
        foreach ($templates as $file) {
            $code = $code_of($file);
            if (!str_contains($code, 'x-data') || !preg_match('/\{\{\s*[\w.]*search[\w.]*\.attrs\b|data-gaffer-search-endpoint=/i', $code, $m, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            // The template and what it includes by fixed name (read as files: no Twig environment here).
            $seen = [];
            $queue = [$code];
            while (($part = array_shift($queue)) !== null) {
                if (preg_match('/\berror\b/', $part)) {
                    continue 2;
                }
                preg_match_all('/\b(?:include|embed|extends)\b\s*\(?\s*[\'"]([^\'"]+)[\'"]/', $part, $names);
                foreach ($names[1] as $included) {
                    if (isset($templates[$included]) && !isset($seen[$included])) {
                        $seen[$included] = true;
                        $queue[] = $code_of($templates[$included]);
                    }
                }
            }
            $report->warning('search', 'A search form whose component never shows its error: a failed search looks like "nothing found"', $file, substr_count($code, "\n", 0, $m[0][1]) + 1,
                'Show the component\'s error, e.g. <p x-show="error" x-cloak><span x-show="error?.stale">This page is out of date: <a href="">refresh it</a>.</span>'
                . '<span x-show="!error?.stale">Products can\'t be loaded right now. Try again later.</span></p> (error.stale: the page\'s token no longer works).');
        }
    }

    /**
     * Templates writing the search form's endpoint or token themselves: {{ search.attrs() }}
     * writes them, so Gaffer's JavaScript and the attributes change together.
     *
     * @param array<string, string> $templates
     */
    private function by_hand(Report $report, array $templates): void
    {
        foreach ($templates as $file) {
            $code = (string) file_get_contents($file);
            if (preg_match('/data-gaffer-search-endpoint=|\baction=["\']\{\{\s*[\w.]+\.endpoint\s*\}\}|<input\b[^>]*\bname=["\']token["\'][^>]*\.token\b/', $code, $m, PREG_OFFSET_CAPTURE)) {
                $report->warning('search', 'Writes the search form\'s endpoint or token by hand', $file, substr_count($code, "\n", 0, $m[0][1]) + 1,
                    'Gaffer writes them: <form method="get" action="…" {{ search.attrs() }}> (Searchbar and Archive), then drop data-gaffer-search-endpoint and the hidden token input; a searchbar\'s action is search.results_url (the archive, for a submit without JavaScript). Rebuild (npm run build) with it.');
            }
        }
    }

    /**
     * The fields archive.sorts and default_sort name that the index can't sort on (field =>
     * hint): the engine drops such a sort with a warning, and the order is quietly another.
     *
     * @param array<mixed> $archive
     * @param array<string, mixed> $definition
     * @return array<string, string>
     */
    public static function unsortable(array $archive, array $definition): array
    {
        $sortable = ArchiveUrl::fields($definition)['sort'];
        $text = array_map(strval(...), (array) ($definition['sortable'] ?? []));
        $found = [];
        foreach ([...(array) ($archive['sorts'] ?? []), ...(array) ($archive['default_sort'] ?? [])] as $sort) {
            $field = is_string($sort) ? explode(':', $sort, 2)[0] : '';
            if ($field === '' || in_array($field, $sortable, true)) {
                continue;
            }
            $found[$field] = in_array($field, $text, true)
                ? "sortable gives a text field a sort key: sort on \"{$field}_sort\". A number sorts once it's in the index's facets."
                : "Sort on one of: " . implode(', ', $sortable) . '. A number sorts once it\'s in the index\'s facets, text with sortable (as {field}_sort).';
        }

        return $found;
    }

    /**
     * Filters on a field an index stores but can't filter on: the engine only filters on its
     * facets (and the fields its type makes filterable), and ignores any other filter with a
     * warning nobody sees, so the page lists everything (or, with the filter in the archive's
     * URL, not what the link promised).
     */
    private function unfiltered(Report $report): void
    {
        $stored = [];
        foreach (Indexes::all() as $name => $definition) {
            $facets = array_map(strval(...), (array) ($definition['facets'] ?? []));
            $fields = array_map(strval(...), array_keys((array) ($definition['fields'] ?? [])));
            foreach ((array) ($definition['meta'] ?? []) as $field) {
                $fields[] = (string) (is_array($field) ? ($field[0] ?? '') : $field);
            }
            foreach (array_diff($fields, $facets) as $field) {
                $stored[$field][] = $name;
            }
        }
        if ($stored === []) {
            return;
        }

        $files = [...array_values(Templates::all()), ...ThemeFiles::find(['php'])];
        foreach ($files as $file) {
            foreach (self::filtered_fields((string) file_get_contents($file), str_ends_with($file, '.php')) as $field => $line) {
                if (isset($stored[$field])) {
                    $report->warning('search', "Filters on \"{$field}\", which index \"" . implode('", "', $stored[$field]) . '" stores but doesn\'t filter on', $file, $line,
                        "The engine filters only on the index's facets and ignores this filter. Add \"{$field}\" to the index's facets (config/search.php).");
                }
            }
        }
    }

    /**
     * The fields a file filters on, with the first line: filter[field] in a URL or form field
     * name, and in PHP the keys of a filter array ('filter' => ['on_sale' => 'yes'], filter: [...]).
     *
     * @return array<string, int>
     */
    public static function filtered_fields(string $code, bool $php): array
    {
        $found = [];
        $line = static fn(int $offset): int => substr_count($code, "\n", 0, $offset) + 1;
        // filter[field], also inside groups: filter[any][0][field], filter[not][field].
        preg_match_all('/filter(?:(?:\[|%5B)(?:any|all|not)(?:\]|%5D)(?:(?:\[|%5B)\d*(?:\]|%5D))?)*(?:\[|%5B)([a-z0-9_]+)(?:\]|%5D)/i', $code, $m, PREG_OFFSET_CAPTURE);
        foreach ($m[1] as [$field, $offset]) {
            if (!in_array(strtolower($field), ArchiveUrl::GROUPS, true)) {
                $found[$field] ??= $line($offset);
            }
        }
        if ($php) {
            preg_match_all('/[\'"]?filter[\'"]?\s*(?:=>|:)\s*(\[(?:[^\[\]]++|(?1))*\])/', $code, $m, PREG_OFFSET_CAPTURE);
            foreach ($m[1] as [$array, $offset]) {
                // Keys at any depth: fields, groups (any/all/not) and a condition's own (min/max, not, all).
                preg_match_all('/[\'"]([a-z0-9_]+)[\'"]\s*=>/i', $array, $keys, PREG_OFFSET_CAPTURE);
                foreach ($keys[1] as [$field, $at]) {
                    if (!in_array(strtolower($field), [...ArchiveUrl::GROUPS, 'min', 'max'], true)) {
                        $found[$field] ??= $line($offset + $at);
                    }
                }
            }
        }

        return $found;
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
        if (isset($archive['sorts']) && (!is_array($archive['sorts']) || !array_is_list($archive['sorts']) || array_filter($archive['sorts'], is_string(...)) !== $archive['sorts'])) {
            $problems[] = 'sorts must be a list of sort values, e.g. [\'\', \'price:asc\'] (\'\' = the default); the template labels them';
        }
        if (isset($archive['facet_values']) && (!is_array($archive['facet_values']) || array_filter($archive['facet_values'], is_int(...)) !== $archive['facet_values'] || array_is_list($archive['facet_values']))) {
            $problems[] = 'facet_values must map a field to a number of values';
        }
        $eager = $archive['eager'] ?? 0;
        if (!is_int($eager) && !(is_array($eager) && !array_is_list($eager) && array_filter($eager, is_int(...)) === $eager)) {
            $problems[] = 'eager must be the number of cards at the top of a page (an int), or one per layout of cards ([\'grid\' => 4, \'list\' => 2])';
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
     * Keys the archive and its groups (seo, urls) don't have.
     *
     * @param array<mixed> $archive
     * @return list<string>
     */
    private static function unknown_keys(array $archive): array
    {
        $problems = [];
        foreach (array_diff(array_keys($archive), [...self::ARCHIVE_KEYS, ...array_keys(self::ARCHIVE_GROUPS)]) as $key) {
            $suggestion = ConfigStubs::did_you_mean((string) $key, [...self::ARCHIVE_KEYS, ...array_keys(self::ARCHIVE_GROUPS)]);
            $problems[] = "unknown key \"{$key}\"" . ($suggestion !== null ? " (did you mean \"{$suggestion}\"?)" : '');
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
        // Gaffer's Search::archive(), not a theme's own class of that name.
        $callers = array_values(array_filter(ThemeFiles::find(['php']), static function (string $file): bool {
            $code = (string) file_get_contents($file);

            return str_contains($code, 'Search::archive(') && preg_match('/Gaffer\\\\Search\\\\Search\b/', $code) === 1;
        }));
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
}
