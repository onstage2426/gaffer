<?php

declare(strict_types=1);

namespace Gaffer\Search;

/**
 * An archive's URLs as search engines and visitors see them: one normalized URL per page (the
 * canonical, page links), whether a page is indexable, and the redirects of old URLs
 * (/page/N/, WooCommerce's own params) to the archive's. Plain query-string work on the URL's
 * `$_GET`-shaped query: q, filter[…], sort[], offset, limit.
 *
 * @internal
 */
final class ArchiveUrl
{
    /** The archive's own params, in the order a URL has them. */
    public const array PARAMS = ['q', 'filter', 'sort', 'offset', 'limit'];

    /**
     * The normalized URL of an archive page: only the archive's params, without empty values or
     * offset 0, filter fields and values in a fixed order, lists written as a form sends them
     * (filter[x][]=a). $base's own query string (base_params) is kept as it is.
     *
     * @param array<mixed> $query
     */
    public static function url(string $base, array $query): string
    {
        [$path, $base_query] = explode('?', $base, 2) + [1 => ''];
        $own = array_filter(explode('&', $base_query), static fn(string $pair): bool => $pair !== ''
            && !in_array(explode('[', urldecode(explode('=', $pair)[0]))[0], self::PARAMS, true));

        $params = [];
        foreach (self::PARAMS as $key) {
            $value = match ($key) {
                'filter' => self::normalized_filter($query['filter'] ?? null),
                'sort' => self::sort($query),
                'q' => is_scalar($query['q'] ?? null) ? trim((string) $query['q']) : null,
                'offset' => self::offset($query) ?: null,
                'limit' => is_numeric($query['limit'] ?? null) && (int) $query['limit'] > 0 ? (int) $query['limit'] : null,
            };
            if ($value !== null && $value !== '' && $value !== []) {
                $params[$key] = $value;
            }
        }
        $search = implode('&', array_filter([...$own, self::query_string($params)], static fn(string $part): bool => $part !== ''));

        return $search !== '' ? "{$path}?{$search}" : $path;
    }

    /**
     * Whether search engines should index the page: no query, sort or chosen limit; the first
     * page, or with a $page_size (index_pages) a later one whose offset is a multiple of it; no
     * filter besides the archive's own term ($term_fields), or only the values $filters lists
     * (field => values: the shop's sale page).
     *
     * @param array<mixed> $query
     * @param list<string> $term_fields
     * @param array<string, list<string>> $filters
     */
    public static function indexable(array $query, array $term_fields = [], int $page_size = 0, array $filters = []): bool
    {
        $chosen = array_filter([$query['q'] ?? null, $query['limit'] ?? null], static fn(mixed $value): bool => $value !== null && (!is_scalar($value) || trim((string) $value) !== ''));
        $offset = self::offset($query);
        if ($chosen !== [] || self::sort($query) !== [] || ($offset !== 0 && ($page_size < 1 || $offset % $page_size !== 0))) {
            return false;
        }
        $filter = self::normalized_filter($query['filter'] ?? null);
        foreach (is_array($filter) ? array_diff_key($filter, array_flip($term_fields)) : [] as $field => $value) {
            // A range or an exclusion is never a listed value.
            $values = is_string($value) ? [$value] : (is_array($value) && array_is_list($value) ? $value : null);
            if ($values === null || array_diff($values, $filters[(string) $field] ?? []) !== []) {
                return false;
            }
        }

        return true;
    }

    /**
     * Where an archive URL should 301 to, or null: WordPress's /page/N/ ($paged > 1) becomes
     * `offset`; WooCommerce's own params become the archive's (translate()); then the theme's
     * $translate (urls.redirect_params) gets that query and returns it with its own old params
     * changed or dropped. Any change is a redirect, to exactly the params it returned.
     *
     * @param array<string, mixed> $definition the archive's index
     * @param array<mixed> $query
     * @param (callable(array<mixed>): array<mixed>)|null $translate
     */
    public static function redirect(array $definition, array $query, int $paged, int $per_page, string $base, ?callable $translate = null): ?string
    {
        $translated = self::translate($definition, $query);
        if ($translate !== null) {
            $translated = (array) $translate($translated);
        }
        if (array_key_exists('token', $translated)) {
            // The archive form submitted without JavaScript: its hidden token and empty fields
            // in the URL. The normalized URL of the same search instead (base params are in
            // $base, the layout kept).
            $view = is_scalar($translated['view'] ?? null) ? (string) $translated['view'] : '';
            // The form's own page size is the default: without it, the indexable page (a sale page).
            if (is_numeric($translated['limit'] ?? null) && (int) $translated['limit'] === $per_page) {
                unset($translated['limit']);
            }
            $url = self::url($base, $translated);

            return $view !== '' ? $url . (str_contains($url, '?') ? '&' : '?') . 'view=' . rawurlencode($view) : $url;
        }
        if ($paged <= 1 && $translated == $query) {
            return null;
        }
        if ($paged > 1) {
            $translated['offset'] = ($paged - 1) * max(1, $per_page);
        }
        // Every other param of the URL rides along (base_params are in the query too).
        $path = (string) strtok($base, '?');
        $search = self::query_string($translated);

        return $search !== '' ? "{$path}?{$search}" : $path;
    }

    /**
     * $query with WooCommerce's archive params (filter_{attribute}, query_type_*, min_price /
     * max_price, orderby, rating_filter) as the archive's own when the index has the field,
     * dropped when it hasn't; unchanged without any.
     *
     * @param array<string, mixed> $definition
     * @param array<mixed> $query
     * @return array<mixed>
     */
    public static function translate(array $definition, array $query): array
    {
        $keys = array_values(array_filter(array_keys($query), static fn(int|string $key): bool => (bool) preg_match('/^(filter_|query_type_)/', (string) $key)
            || in_array($key, ['min_price', 'max_price', 'orderby', 'rating_filter'], true)));
        if ($keys === []) {
            return $query;
        }

        $out = array_diff_key($query, array_flip($keys));
        $fields = self::fields($definition);
        $taxonomies = array_map(strval(...), (array) ($definition['taxonomies'] ?? []));
        $price = self::meta_field($definition, '_price');
        $filter = is_array($out['filter'] ?? null) ? $out['filter'] : [];
        $sort = is_array($out['sort'] ?? null) ? $out['sort'] : [];

        // filter_{attribute}=a,b: the attribute taxonomy pa_{attribute}'s values, comma-separated.
        foreach ($keys as $key) {
            $field = str_starts_with((string) $key, 'filter_') ? $taxonomies['pa_' . substr((string) $key, 7)] ?? null : null;
            $values = is_string($query[$key]) ? array_values(array_filter(array_map(trim(...), explode(',', $query[$key])), static fn(string $value): bool => $value !== '')) : [];
            if ($field !== null && $values !== []) {
                $filter[$field] = array_values(array_unique([...(array) ($filter[$field] ?? []), ...$values]));
            }
        }
        foreach (['min_price' => 'min', 'max_price' => 'max'] as $key => $bound) {
            if ($price !== null && in_array($price, $fields['filter'], true) && is_numeric($query[$key] ?? null)) {
                $filter[$price] = [...(is_array($filter[$price] ?? null) ? $filter[$price] : []), $bound => (string) $query[$key]];
            }
        }

        $spec = [
            'popularity' => 'total_sales:desc',
            'rating' => 'average_rating:desc',
            'date' => 'date:desc',
            'price' => $price !== null ? "{$price}:asc" : null,
            'price-desc' => $price !== null ? "{$price}:desc" : null,
            'title' => 'title_sort:asc',
        ][is_string($query['orderby'] ?? null) ? $query['orderby'] : ''] ?? null;
        if ($spec !== null && $sort === [] && in_array(explode(':', $spec)[0], $fields['sort'], true)) {
            $sort = [$spec];
        }

        unset($out['filter'], $out['sort']);

        return [...$out, ...($filter !== [] ? ['filter' => $filter] : []), ...($sort !== [] ? ['sort' => $sort] : [])];
    }

    /**
     * The fields an index filters and sorts on, from its definition, as the contract describes
     * them: id, date, its facets and taxonomy fields; sorting also on {field}_sort per sortable
     * text field, and products on menu_order and total_sales.
     *
     * @param array<string, mixed> $definition
     * @return array{filter: list<string>, sort: list<string>}
     */
    public static function fields(array $definition): array
    {
        $filter = array_values(array_unique(['id', 'date', ...array_map(strval(...), (array) ($definition['facets'] ?? [])), ...array_map(strval(...), array_values((array) ($definition['taxonomies'] ?? [])))]));
        $sort = [
            ...$filter,
            ...array_map(static fn(mixed $field): string => "{$field}_sort", (array) ($definition['sortable'] ?? [])),
            ...(($definition['type'] ?? null) === 'products' ? ['menu_order', 'total_sales'] : []),
        ];

        return ['filter' => $filter, 'sort' => array_values(array_unique($sort))];
    }

    /**
     * The archive params of a URL as the search request's: the visitor's filter (normalized),
     * sort, offset and query.
     *
     * @param array<mixed> $query
     * @return array{query: string, filter: array<mixed>, sort: list<string>, offset: int}
     */
    public static function request(array $query): array
    {
        $filter = self::normalized_filter($query['filter'] ?? null);

        return [
            'query' => is_scalar($query['q'] ?? null) ? trim((string) $query['q']) : '',
            'filter' => is_array($filter) ? $filter : [],
            'sort' => self::sort($query),
            'offset' => self::offset($query),
        ];
    }

    /** @param array<mixed> $query */
    private static function offset(array $query): int
    {
        return is_numeric($query['offset'] ?? null) ? max(0, min(10000, (int) $query['offset'])) : 0;
    }

    /**
     * @param array<mixed> $query
     * @return list<string>
     */
    private static function sort(array $query): array
    {
        $sort = $query['sort'] ?? [];

        return array_values(array_filter(is_array($sort) ? $sort : [$sort], static fn(mixed $spec): bool => is_string($spec) && trim($spec) !== ''));
    }

    /** @param array<string, mixed> $definition */
    private static function meta_field(array $definition, string $key): ?string
    {
        $field = ((array) ($definition['meta'] ?? []))[$key] ?? null;
        $field = is_array($field) ? ($field[0] ?? null) : $field;

        return is_string($field) && $field !== '' ? $field : null;
    }

    /** A filter[] value without empty values, fields and value lists sorted. */
    private static function normalized_filter(mixed $filter): mixed
    {
        if (!is_array($filter)) {
            return is_scalar($filter) ? trim((string) $filter) : null;
        }
        $out = [];
        foreach ($filter as $key => $value) {
            $value = self::normalized_filter($value);
            if ($value !== null && $value !== '' && $value !== []) {
                $out[$key] = $value;
            }
        }
        if (array_is_list($out) && array_filter($out, is_array(...)) === []) {
            sort($out, SORT_STRING);

            return array_values(array_unique($out, SORT_REGULAR));
        }
        ksort($out, SORT_STRING);

        return $out;
    }

    /**
     * A query string with lists written as a form sends them (key[]=a), not PHP's numbered keys.
     *
     * @param array<mixed> $params
     */
    private static function query_string(array $params, string $prefix = ''): string
    {
        $pairs = [];
        $list = array_is_list($params);
        foreach ($params as $key => $value) {
            $name = $prefix === '' ? rawurlencode((string) $key) : $prefix . '%5B' . ($list ? '' : rawurlencode((string) $key)) . '%5D';
            if (is_array($value)) {
                $pairs[] = self::query_string($value, $name);
            } elseif (is_scalar($value)) {
                $pairs[] = $name . '=' . rawurlencode(is_bool($value) ? ($value ? '1' : '0') : (string) $value);
            }
        }

        return implode('&', array_filter($pairs, static fn(string $pair): bool => $pair !== ''));
    }
}
