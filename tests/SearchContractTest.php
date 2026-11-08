<?php

declare(strict_types=1);

namespace Gaffer\Tests;

use Gaffer\Config;
use Gaffer\Search\Engine;
use Gaffer\Search\Search;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Gaffer against the gaffer-search plugin's contract fixtures (tests/fixtures/search-contract/,
 * copied from the plugin's tests/contract/: refresh with `cp
 * ~/workspace/gaffer-search/tests/contract/{*.json,MANIFEST} tests/fixtures/search-contract/`).
 * The plugin's CI holds the engine to them; here Gaffer reads every recorded answer, and every
 * request it builds uses only keys the contract defines.
 */
final class SearchContractTest extends TestCase
{
    private const string DIR = __DIR__ . '/fixtures/search-contract';

    /** The request keys of contract 1 (docs/contract.md, "Request"). */
    private const array REQUEST_KEYS = ['query', 'limit', 'offset', 'sort', 'default_sort', 'filter', 'visibility', 'term', 'facets', 'attributes', 'ttl', 'limits', 'facet_values', 'max_docs'];

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        Engine::reset();
    }

    #[\Override]
    protected function tearDown(): void
    {
        Engine::reset();
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function answers(): iterable
    {
        foreach (glob(self::DIR . '/*.json') ?: [] as $file) {
            $case = (array) json_decode((string) file_get_contents($file), true);
            $answer = match ($case['call'] ?? null) {
                'search' => $case['expect'] ?? null,
                'endpoint' => ($case['expect']['status'] ?? 0) === 200 ? $case['expect']['body'] ?? null : null,
                default => null,
            };
            if (is_array($answer)) {
                yield basename($file, '.json') => [$answer];
            }
        }
    }

    /** @param array<string, mixed> $answer */
    #[DataProvider('answers')]
    public function test_gaffer_reads_every_recorded_answer(array $answer): void
    {
        $read = Engine::response($answer);

        self::assertSame($answer['total'], $read['total']);
        self::assertSame(array_column($answer['results'], 'id'), array_column($read['results'], 'id'));
        foreach (['facets', 'facetStats', 'labels'] as $key) {
            self::assertSame($answer[$key] ?? [], $read[$key]);
        }
        foreach ($read['results'] as $i => $hit) {
            self::assertSame($answer['results'][$i], $hit, 'hits as recorded: Gaffer adds or drops nothing');
        }
    }

    public function test_the_recorded_requests_use_only_contract_keys(): void
    {
        foreach (glob(self::DIR . '/*.json') ?: [] as $file) {
            $case = (array) json_decode((string) file_get_contents($file), true);
            $request = match ($case['call'] ?? null) {
                'search', 'token' => $case['args'][1] ?? [],
                'endpoint' => $case['token'][1] ?? [],
                default => [],
            };
            self::assertSame([], array_values(array_diff(array_keys((array) $request), self::REQUEST_KEYS)), basename($file));
        }
    }

    public function test_the_requests_gaffer_builds_use_only_contract_keys(): void
    {
        new \ReflectionProperty(Config::class, 'config')->setValue(null, ['search' => ['indexes' => [
            'products' => ['type' => 'products', 'cards' => ['grid' => 'card.twig']],
            'news' => ['type' => 'posts'],
        ]]]);
        $engine = new RecordingEngine();
        Engine::reset(new Engine($engine));

        Search::cards('products', 4, ['total_sales:desc']);
        Search::searchbar('products', ['id', 'url'], 8, ['price']);
        Search::searchbar('news', ['id', 'url']);

        self::assertCount(3, $engine->requests);
        foreach ($engine->requests as [$method, $request]) {
            self::assertSame([], array_values(array_diff(array_keys($request), self::REQUEST_KEYS)), $method);
        }
        self::assertSame('search', $engine->requests[1][1]['visibility'], 'a products searchbar hides what search hides');
        self::assertArrayNotHasKey('visibility', $engine->requests[2][1], 'posts have no catalog visibility');
    }

    public function test_every_fixture_is_the_plugins_recorded_case(): void
    {
        $manifest = [];
        foreach (file(self::DIR . '/MANIFEST', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            [$hash, $name] = explode('  ', $line, 2);
            $manifest[$name] = $hash;
        }
        self::assertNotEmpty($manifest);
        foreach (glob(self::DIR . '/*.json') ?: [] as $file) {
            self::assertSame($manifest[basename($file)] ?? null, hash_file('sha256', $file), basename($file) . ' differs from the plugin\'s recorded case');
        }
    }
}

/** Remembers the requests Gaffer sends and answers with empty results. */
final class RecordingEngine
{
    /** @var list<array{string, array<string, mixed>}> */
    public array $requests = [];

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function search(string $index, array $request): array
    {
        $this->requests[] = ['search', $request];

        return ['total' => 0, 'results' => []];
    }

    /** @param array<string, mixed> $request */
    public function token(string $index, array $request): string
    {
        $this->requests[] = ['token', $request];

        return 'token';
    }

    public function endpoint(): string
    {
        return 'https://shop.test/search.php';
    }
}
