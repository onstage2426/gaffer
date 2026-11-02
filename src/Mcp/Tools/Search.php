<?php

declare(strict_types=1);

namespace Gaffer\Mcp\Tools;

use Gaffer\Mcp\Tool;
use Gaffer\Search\Engine;
use Gaffer\Search\Indexes;
use WP_Error;

final class Search implements Tool
{
    #[\Override]
    public function name(): string
    {
        return 'search';
    }

    #[\Override]
    public function label(): string
    {
        return 'Search an index';
    }

    #[\Override]
    public function description(): string
    {
        return 'Searches one of the theme\'s gaffer-search indexes (config/search.php) the way the site does and returns the index\'s status (built, documents, '
            . 'whether it is current, the last build error), the total, the hits (id, title, url and the fields asked for; rendered cards left out), facet counts with '
            . 'their labels, and warnings (an undeclared field). Use to check a synonym, a facet, a filter or why something isn\'t found, without a browser. '
            . 'Filters: {"field": "value"}, {"field": ["a", "b"]}, {"field": {"not": "a"}}, {"field": {"min": 10, "max": 50}}; taxonomy values are slugs.';
    }

    #[\Override]
    public function input_schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'index' => ['type' => 'string', 'description' => 'The index name from config/search.php, e.g. "products"'],
                'query' => ['type' => 'string', 'description' => 'The search words; empty searches everything'],
                'filter' => ['type' => 'object', 'description' => 'Filter by field (see the description)'],
                'sort' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'e.g. ["price:asc"]'],
                'facets' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Fields to count values of'],
                'fields' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Extra stored fields per hit, e.g. ["price", "category"]'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'description' => 'Hits, default 10'],
            ],
            'required' => ['index'],
        ];
    }

    #[\Override]
    public function run(array $input): array|WP_Error
    {
        $engine = Engine::find();
        $index = (string) $input['index'];
        if ($engine === null) {
            return new WP_Error('gaffer_search', 'The gaffer-search plugin isn\'t active.');
        }
        $definition = Indexes::get($index);
        if ($definition === null) {
            return new WP_Error('gaffer_search', "No index \"{$index}\" in config/search.php (indexes: " . implode(', ', array_keys(Indexes::all())) . ').');
        }

        $fields = array_values(array_unique(['id', 'title', 'url', ...array_map(strval(...), (array) ($input['fields'] ?? []))]));
        $results = $engine->search($index, array_filter([
            'query' => (string) ($input['query'] ?? ''),
            'filter' => is_array($input['filter'] ?? null) ? $input['filter'] : null,
            'sort' => is_array($input['sort'] ?? null) ? array_values(array_map(strval(...), $input['sort'])) : null,
            'facets' => is_array($input['facets'] ?? null) ? array_values(array_map(strval(...), $input['facets'])) : null,
            'attributes' => $fields,
            'limit' => (int) ($input['limit'] ?? 10),
        ], static fn(mixed $value): bool => $value !== null));
        $status = $engine->status()[$index] ?? null;
        $version = Indexes::definitions([$index => $definition])[$index]['version'];

        return [
            'index' => $status !== null ? [...$status, 'current' => $status['version'] === $version] : null,
            'total' => $results['total'],
            'hits' => array_map(static fn(array $hit): array => array_intersect_key($hit, array_flip([...$fields, 'labels'])), $results['results']),
            'facets' => $results['facets'],
            'facetStats' => $results['facetStats'],
            'labels' => $results['labels'],
            'warnings' => $results['warnings'],
        ];
    }

    #[\Override]
    public function available(): bool
    {
        return Engine::find() !== null && Indexes::all() !== [];
    }
}
