<?php

declare(strict_types=1);

namespace Gaffer\Search;

/**
 * The gaffer-search plugin's engine, contract 1 (the plugin's docs/contract.md): the one place
 * Gaffer talks to it. Everything that comes back is checked here, so an engine that answers in
 * an unexpected shape degrades to empty results instead of breaking a template; keys this Gaffer
 * doesn't know are ignored (the contract only grows).
 *
 * @internal
 */
final class Engine
{
    public const int CONTRACT = 1;

    private static ?self $found = null;
    private static bool $looked = false;

    public function __construct(private readonly object $engine) {}

    /** The plugin's engine for this request, null when the plugin is inactive or doesn't speak contract 1. */
    public static function find(): ?self
    {
        if (!self::$looked && function_exists('apply_filters')) {
            self::$looked = true;
            $engine = \apply_filters('gaffer_search_engine', null, self::CONTRACT);
            self::$found = is_object($engine) ? new self($engine) : null;
        }

        return self::$found;
    }

    /** Forget the engine found (tests). */
    public static function reset(?self $engine = null): void
    {
        self::$found = $engine;
        self::$looked = $engine !== null;
    }

    public function supports(string $feature): bool
    {
        return $this->call('supports', $feature) === true;
    }

    /** @param array<string, array<string, mixed>> $indexes */
    public function define(array $indexes): void
    {
        $this->call('define', $indexes);
    }

    /**
     * @param array<string, array<string, mixed>> $indexes
     * @return list<string>
     */
    public function problems(array $indexes): array
    {
        return self::strings($this->call('problems', $indexes));
    }

    /** @return array<string, array{built: bool, documents: int, version: ?string, building: bool, error: ?string}> */
    public function status(): array
    {
        $status = [];
        foreach ((array) $this->call('status') as $index => $state) {
            $state = (array) $state;
            $status[(string) $index] = [
                'built' => ($state['built'] ?? false) === true,
                'documents' => (int) ($state['documents'] ?? 0),
                'version' => is_string($state['version'] ?? null) ? $state['version'] : null,
                'building' => ($state['building'] ?? false) === true,
                'error' => is_string($state['error'] ?? null) ? $state['error'] : null,
            ];
        }

        return $status;
    }

    public function endpoint(): string
    {
        return (string) $this->call('endpoint');
    }

    /**
     * @param array<string, mixed> $request
     * @return array{query: string, total: int, offset: int, limit: int, results: list<array<string, mixed>>, facets: array<string, mixed>, facetStats: array<string, mixed>, labels: array<string, mixed>, warnings: list<string>}
     */
    public function search(string $index, array $request): array
    {
        return self::response($this->call('search', $index, $request));
    }

    /** @param array<string, mixed> $request */
    public function token(string $index, array $request): string
    {
        return (string) $this->call('token', $index, $request);
    }

    /**
     * @param array<string, mixed> $request
     * @param array<string, mixed> $query
     * @return array{endpoint: string, token: string, results: array{query: string, total: int, offset: int, limit: int, results: list<array<string, mixed>>, facets: array<string, mixed>, facetStats: array<string, mixed>, labels: array<string, mixed>, warnings: list<string>}, url: string, indexable: bool}
     */
    public function archive(string $index, array $request, array $query, string $base): array
    {
        $archive = (array) $this->call('archive', $index, $request, $query, $base);

        return [
            'endpoint' => (string) ($archive['endpoint'] ?? ''),
            'token' => (string) ($archive['token'] ?? ''),
            'results' => self::response($archive['results'] ?? null),
            'url' => (string) ($archive['url'] ?? $base),
            'indexable' => ($archive['indexable'] ?? false) === true,
        ];
    }

    /** @param array<string, mixed> $query */
    public function url(string $base, array $query): string
    {
        return (string) $this->call('url', $base, $query);
    }

    /** @param array<string, mixed> $query */
    public function redirect(string $index, array $query, int $paged, int $per_page, string $base): ?string
    {
        $url = $this->call('redirect', $index, $query, $paged, $per_page, $base);

        return is_string($url) && $url !== '' ? $url : null;
    }

    private function call(string $method, mixed ...$arguments): mixed
    {
        $callable = [$this->engine, $method];

        return is_callable($callable) ? $callable(...$arguments) : null;
    }

    /**
     * A SearchResponse with the keys Gaffer reads, whatever came back.
     *
     * @return array{query: string, total: int, offset: int, limit: int, results: list<array<string, mixed>>, facets: array<string, mixed>, facetStats: array<string, mixed>, labels: array<string, mixed>, warnings: list<string>}
     */
    public static function response(mixed $response): array
    {
        $response = is_array($response) ? $response : [];
        $results = [];
        foreach (is_array($response['results'] ?? null) ? $response['results'] : [] as $hit) {
            if (is_array($hit)) {
                $results[] = $hit;
            }
        }

        return [
            ...$response,
            'query' => (string) ($response['query'] ?? ''),
            'total' => (int) ($response['total'] ?? 0),
            'offset' => (int) ($response['offset'] ?? 0),
            'limit' => (int) ($response['limit'] ?? 0),
            'results' => $results,
            'facets' => is_array($response['facets'] ?? null) ? $response['facets'] : [],
            'facetStats' => is_array($response['facetStats'] ?? null) ? $response['facetStats'] : [],
            'labels' => is_array($response['labels'] ?? null) ? $response['labels'] : [],
            'warnings' => self::strings($response['warnings'] ?? []),
        ];
    }

    /** @return list<string> */
    private static function strings(mixed $value): array
    {
        return is_array($value) ? array_values(array_map(strval(...), array_filter($value, is_scalar(...)))) : [];
    }
}
