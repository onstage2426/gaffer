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

    /** @var array<string, true> the engine's warnings of this request ("index: warning") */
    private static array $warnings = [];

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
        self::$failed = null;
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

    /**
     * pending/pending_since (feature pending_updates): content changes the index hasn't taken in
     * yet, and when the oldest was logged (Unix time); drift/drift_checked/served (feature
     * health_checks): how many documents of the engine's last daily sample differed from the
     * database and when it ran, and the plugin folders a visitor could fetch files from. Null
     * when the engine doesn't say.
     *
     * @return array<string, array{built: bool, documents: int, version: ?string, building: bool, error: ?string, pending: ?int, pending_since: ?int, drift: ?int, drift_checked: ?int, served: ?list<string>}>
     */
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
                'pending' => is_int($state['pending'] ?? null) ? $state['pending'] : null,
                'pending_since' => is_int($state['pending_since'] ?? null) ? $state['pending_since'] : null,
                'drift' => is_int($state['drift'] ?? null) ? $state['drift'] : null,
                'drift_checked' => is_int($state['drift_checked'] ?? null) ? $state['drift_checked'] : null,
                'served' => is_array($state['served'] ?? null) ? self::strings($state['served']) : null,
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
     * @return array{query: string, total: int, offset: int, limit: int, results: list<array<string, mixed>>, facets: array<string, mixed>, facetStats: array<string, mixed>, labels: array<string, mixed>, warnings: list<string>, code?: string}
     */
    public function search(string $index, array $request): array
    {
        $this->check_filter($request);
        $response = self::response($this->call('search', $index, $request));
        foreach ($response['warnings'] as $warning) {
            self::$warnings["{$index}: {$warning}"] = true;
        }
        if (is_string($response['code'] ?? null)) {
            self::failed($response['code']);
        }

        return $response;
    }

    /** @var ?string the engine's code for the first search of this request that couldn't run */
    private static ?string $failed = null;

    /**
     * A search that couldn't run (feature search_codes: the index isn't built, or the search
     * failed) gave an empty answer that isn't "nothing found": the page showing it stays out of
     * page caches (`DONOTCACHEPAGE`, no-cache headers while they can still be sent), so a cache
     * doesn't keep an empty shop or slider after the index is back.
     */
    private static function failed(string $code): void
    {
        self::$failed ??= $code;
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        if (function_exists('nocache_headers') && !headers_sent()) {
            \nocache_headers();
        }
    }

    /**
     * @internal The plugin isn't there (inactive, or its folder missing during an update) while
     * config/search.php defines indexes: what this request shows from search is empty for now,
     * not for good, so the page stays out of page caches like a search that couldn't run.
     */
    public static function missing(): void
    {
        self::failed(self::MISSING);
    }

    /** The code for a request whose engine is missing (Engine::missing()), as an engine's `code`. */
    public const string MISSING = 'engine_missing';

    /** @internal The engine's code for a search of this request that couldn't run, null when every one ran. */
    public static function failure(): ?string
    {
        return self::$failed;
    }

    /**
     * What the engine warned about in this request's searches (a filter on a field it can't
     * filter on: ignored): a misconfiguration nothing else shows. render:page reports them.
     *
     * @return list<string>
     */
    public static function warnings(): array
    {
        return array_keys(self::$warnings);
    }

    /** @param array<string, mixed> $request */
    public function token(string $index, array $request): string
    {
        $this->check_filter($request);

        return (string) $this->call('token', $index, $request);
    }

    /**
     * A filter with groups or `all` values needs the engine's `filter_groups`: an older engine
     * reads `any`/`all`/`not` as field names and drops what it can't parse, so the search would
     * find more than the filter allows.
     *
     * @param array<string, mixed> $request
     */
    private function check_filter(array $request): void
    {
        if (is_array($request['filter'] ?? null) && ArchiveUrl::uses_groups($request['filter']) && !$this->supports('filter_groups')) {
            throw new \LogicException('This filter uses any, all or not, which this gaffer-search doesn\'t support (feature filter_groups): update the plugin.');
        }
    }

    private function call(string $method, mixed ...$arguments): mixed
    {
        $callable = [$this->engine, $method];

        return is_callable($callable) ? $callable(...$arguments) : null;
    }

    /**
     * A SearchResponse with the keys Gaffer reads, whatever came back; `code` only when the
     * search couldn't run (feature search_codes).
     *
     * @return array{query: string, total: int, offset: int, limit: int, results: list<array<string, mixed>>, facets: array<string, mixed>, facetStats: array<string, mixed>, labels: array<string, mixed>, warnings: list<string>, code?: string}
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

        // code (feature search_codes): why an empty answer is empty; only as the engine's string.
        if (!is_string($response['code'] ?? null)) {
            unset($response['code']);
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
