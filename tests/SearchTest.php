<?php

declare(strict_types=1);

namespace Gaffer\Tests;

use Gaffer\Config;
use Gaffer\Paths;
use Gaffer\Search\Cards;
use Gaffer\Search\Engine;
use Gaffer\Search\Indexes;
use Gaffer\Search\Search;
use Gaffer\View;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class SearchTest extends TestCase
{
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        Engine::reset();
        Search::reset();
    }

    #[\Override]
    protected function tearDown(): void
    {
        Engine::reset();
    }

    public function test_definitions_leave_gaffer_keys_out_and_add_version_and_document(): void
    {
        $definitions = Indexes::definitions([
            'news' => ['type' => 'posts', 'post_types' => ['post']],
            'products' => ['type' => 'products', 'card_variants' => ['business' => [self::class, 'excl']], 'card_variant' => [self::class, 'business'], 'card_context' => [self::class, 'context']],
        ]);

        self::assertSame(['type', 'post_types', 'version'], array_keys($definitions['news']));
        self::assertSame(['type', 'version'], array_keys($definitions['products']), 'no card: no document callable');
        self::assertNotSame($definitions['news']['version'], Indexes::definitions(['news' => ['type' => 'posts', 'post_types' => ['page']]])['news']['version']);
    }

    public function test_the_card_field_follows_the_visitors_variant(): void
    {
        self::config(['indexes' => [
            'products' => ['type' => 'products', 'card' => 'card.twig', 'card_variants' => ['business' => [self::class, 'excl']], 'card_variant' => [self::class, 'business']],
            'plain' => ['type' => 'products', 'card' => 'card.twig'],
            'unknown' => ['type' => 'products', 'card' => 'card.twig', 'card_variant' => [self::class, 'business']],
            'none' => ['type' => 'posts'],
        ]]);

        self::assertSame('card_business', Indexes::card_field('products'));
        self::assertSame('card', Indexes::card_field('plain'));
        self::assertSame('card', Indexes::card_field('unknown'), 'a variant the index has no card for');
        self::assertNull(Indexes::card_field('none'));
    }

    public function test_a_cards_files_are_it_and_everything_it_includes(): void
    {
        $theme = sys_get_temp_dir() . '/gaffer-search-' . bin2hex(random_bytes(4));
        mkdir("{$theme}/views/product/icons", 0777, true);
        file_put_contents("{$theme}/views/product/card.twig", "{{ include('product/badges.twig') }}{{ source('product/icons/' ~ icon ~ '.svg') }}{% include 'product/badges.twig' %}");
        file_put_contents("{$theme}/views/product/badges.twig", "{{ include('product/price.twig', with_context = false) }}");
        file_put_contents("{$theme}/views/product/price.twig", 'price');
        file_put_contents("{$theme}/views/product/icons/a.svg", '<svg/>');
        file_put_contents("{$theme}/views/product/icons/b.svg", '<svg/>');
        file_put_contents("{$theme}/views/product/other.twig", 'not included');
        Paths::set_base($theme);
        View::set_env(new Environment(new FilesystemLoader("{$theme}/views")));

        self::assertSame(
            ['badges.twig', 'card.twig', 'icons/a.svg', 'icons/b.svg', 'price.twig'],
            array_map(static fn(string $file): string => substr($file, strlen("{$theme}/views/product/")), array_keys(Cards::files('product/card.twig'))),
        );
        $before = Cards::version('product/card.twig');
        file_put_contents("{$theme}/views/product/price.twig", 'price, changed');
        self::assertNotSame($before, Cards::version('product/card.twig'), 'a change two includes deep');

        exec('rm -rf ' . escapeshellarg($theme));
    }

    public function test_answers_in_an_unexpected_shape_become_empty_results(): void
    {
        $response = Engine::response(['total' => '3', 'results' => [['id' => 1], 'junk'], 'warnings' => ['x', ['y']], 'time' => '1ms']);

        self::assertSame(3, $response['total']);
        self::assertSame([['id' => 1]], $response['results']);
        self::assertSame(['x'], $response['warnings']);
        self::assertStringContainsString('"time":"1ms"', (string) json_encode($response), 'keys Gaffer doesn\'t read stay');
        self::assertSame([], Engine::response(null)['results']);
    }

    public function test_cards_search_the_index_and_return_the_visitors_cards(): void
    {
        self::config(['indexes' => ['products' => ['type' => 'products', 'card' => 'card.twig']]]);
        $engine = new FakeEngine([['id' => 1, 'card' => '<article>1</article>'], ['id' => 2]]);
        Engine::reset(new Engine($engine));

        $cards = Search::cards('products', 4, ['total_sales:desc']);

        self::assertSame(['<article>1</article>'], array_map(strval(...), $cards), 'a hit without its card is left out');
        self::assertSame(['products', ['visibility' => 'catalog', 'limit' => 4, 'sort' => ['total_sales:desc'], 'attributes' => ['id', 'card']]], $engine->calls[0]);
    }

    public function test_without_the_plugin_templates_get_empty_states(): void
    {
        self::config(['indexes' => ['products' => ['type' => 'products', 'card' => 'card.twig']]]);

        self::assertFalse(Search::available());
        self::assertSame([], Search::cards('products', 4));
        self::assertFalse(Search::searchbar('products')->available);
    }

    /** @param array<string, mixed> $search */
    private static function config(array $search): void
    {
        new \ReflectionProperty(Config::class, 'config')->setValue(null, ['search' => $search]);
    }

    public static function business(): string
    {
        return 'business';
    }

    public static function excl(\Closure $render): string
    {
        return $render();
    }

    /** @return array<string, mixed> */
    public static function context(mixed $post): array
    {
        return ['post' => $post];
    }
}

/** Answers searches with fixed hits and remembers what it was asked. */
final class FakeEngine
{
    /** @var list<array{string, array<string, mixed>}> */
    public array $calls = [];

    /** @param list<mixed> $hits */
    public function __construct(private readonly array $hits) {}

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function search(string $index, array $request): array
    {
        $this->calls[] = [$index, $request];

        return ['query' => '', 'total' => count($this->hits), 'offset' => 0, 'limit' => 4, 'results' => $this->hits];
    }
}
