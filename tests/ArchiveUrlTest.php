<?php

declare(strict_types=1);

namespace Gaffer\Tests;

use Gaffer\Search\ArchiveUrl;

final class ArchiveUrlTest extends TestCase
{
    /** An index as config/search.php defines it: colors from pa_kleur, the price filterable. */
    private const array PRODUCTS = [
        'type' => 'products',
        'facets' => ['price'],
        'meta' => ['_price' => ['price', 'float']],
        'taxonomies' => ['product_cat' => 'category', 'pa_kleur' => 'color'],
        'sortable' => ['title'],
    ];

    public function test_one_url_per_page_with_lists_as_the_form_sends_them(): void
    {
        $url = ArchiveUrl::url('https://shop.test/winkel/', [
            'token' => 'abc',
            'utm_source' => 'mail',
            'q' => ' jurk ',
            'offset' => '0',
            'sort' => ['price:desc', 'title_sort:asc', ''],
            'filter' => ['merk' => ['stella', 'mbi', ''], 'price' => ['min' => '10', 'max' => ''], 'color' => '', 'visibility' => ['not' => ['b', 'a']]],
            'limit' => '24',
            'view' => 'list',
        ]);

        self::assertSame('https://shop.test/winkel/?q=jurk&filter%5Bmerk%5D%5B%5D=mbi&filter%5Bmerk%5D%5B%5D=stella&filter%5Bprice%5D%5Bmin%5D=10'
            . '&filter%5Bvisibility%5D%5Bnot%5D%5B%5D=a&filter%5Bvisibility%5D%5Bnot%5D%5B%5D=b&sort%5B%5D=price%3Adesc&sort%5B%5D=title_sort%3Aasc&limit=24', $url);
        self::assertSame('https://shop.test/winkel/', ArchiveUrl::url('https://shop.test/winkel/', ['token' => 'abc', 'offset' => '0']));
        self::assertSame('https://shop.test/winkel/?lang=en&offset=24', ArchiveUrl::url('https://shop.test/winkel/?lang=en', ['offset' => '24']), 'the base\'s own query stays');
        self::assertSame('/afwatering/?show=all&offset=24', ArchiveUrl::url('/afwatering/?show=all', ['show' => 'all', 'offset' => '24']), 'a base param once');
        self::assertSame(
            ArchiveUrl::url('/', ['filter' => ['b' => ['y', 'x'], 'a' => 'z']]),
            ArchiveUrl::url('/', ['filter' => ['a' => 'z', 'b' => ['x', 'y']]]),
            'one page, one URL',
        );
    }

    public function test_indexable_pages(): void
    {
        self::assertTrue(ArchiveUrl::indexable([]), 'the archive itself');
        self::assertFalse(ArchiveUrl::indexable(['filter' => ['brand' => 'rosa-clara']]), 'a filter besides the term');
        self::assertTrue(ArchiveUrl::indexable(['filter' => ['category' => 'jurken']], ['category']), 'the term\'s own field');
        self::assertFalse(ArchiveUrl::indexable(['sort' => ['price:asc']]));
        self::assertFalse(ArchiveUrl::indexable(['q' => 'jurk']), 'a search');
        self::assertFalse(ArchiveUrl::indexable(['limit' => '48']), 'the same products, more per page');
        self::assertTrue(ArchiveUrl::indexable(['q' => ' ', 'limit' => '', 'sort' => ['']]), 'empty params count as absent');

        self::assertFalse(ArchiveUrl::indexable(['offset' => '24']));
        self::assertTrue(ArchiveUrl::indexable(['offset' => '24'], [], 24), 'later pages with a page size');
        self::assertFalse(ArchiveUrl::indexable(['offset' => '25'], [], 24), 'an offset between pages isn\'t a page');

        $sale = ['on_sale' => ['yes']];
        self::assertTrue(ArchiveUrl::indexable(['filter' => ['on_sale' => 'yes']], [], 0, $sale), 'a listed value');
        self::assertTrue(ArchiveUrl::indexable(['filter' => ['on_sale' => ['yes']]], [], 0, $sale), 'as a list too');
        self::assertFalse(ArchiveUrl::indexable(['filter' => ['on_sale' => 'no']], [], 0, $sale), 'another value');
        self::assertFalse(ArchiveUrl::indexable(['filter' => ['on_sale' => 'yes', 'brand' => 'x']], [], 0, $sale), 'with a field not listed');
        self::assertFalse(ArchiveUrl::indexable(['filter' => ['on_sale' => ['not' => 'yes']]], [], 0, $sale), 'an exclusion');
    }

    public function test_woocommerces_params_become_the_archives_own(): void
    {
        self::assertSame(['q' => 'x'], ArchiveUrl::translate(self::PRODUCTS, ['q' => 'x']), 'nothing to translate');
        self::assertSame([
            'q' => 'x',
            'filter' => ['category' => 'jurken', 'color' => ['rood', 'wit'], 'price' => ['min' => '10', 'max' => '50']],
            'sort' => ['price:desc'],
        ], ArchiveUrl::translate(self::PRODUCTS, [
            'q' => 'x',
            'filter' => ['category' => 'jurken'],
            'filter_kleur' => 'rood,wit',
            'query_type_kleur' => 'or',
            'filter_maat' => 'm',
            'min_price' => '10',
            'max_price' => '50',
            'orderby' => 'price-desc',
            'rating_filter' => '4',
        ]), 'pa_kleur → color; no field for pa_maat or a rating');
        self::assertSame(['q' => 'x', 'sort' => ['total_sales:desc']], ArchiveUrl::translate(self::PRODUCTS, ['q' => 'x', 'orderby' => 'popularity']));
        self::assertSame(['q' => 'x'], ArchiveUrl::translate(['type' => 'products'], ['q' => 'x', 'min_price' => '10', 'orderby' => 'price']), 'no price field: dropped');
        self::assertSame(['onsale_filter' => '1'], ArchiveUrl::translate(self::PRODUCTS, ['onsale_filter' => '1']), 'other plugins\' params are left alone');
    }

    public function test_redirects_of_old_urls(): void
    {
        self::assertNull(ArchiveUrl::redirect(self::PRODUCTS, ['q' => 'x'], 0, 24, '/winkel/'));
        self::assertSame('/winkel/?q=x&offset=48', ArchiveUrl::redirect(self::PRODUCTS, ['q' => 'x'], 3, 24, '/winkel/'), '/page/3/');
        self::assertSame(
            '/winkel/?filter%5Bcolor%5D%5B%5D=rood&filter%5Bcolor%5D%5B%5D=wit&filter%5Bprice%5D%5Bmin%5D=50&sort%5B%5D=price%3Adesc',
            ArchiveUrl::redirect(self::PRODUCTS, ['orderby' => 'price-desc', 'min_price' => '50', 'filter_kleur' => 'rood,wit', 'query_type_kleur' => 'or'], 0, 24, '/winkel/'),
        );
        self::assertSame('/afwatering/?show=all&offset=24', ArchiveUrl::redirect(self::PRODUCTS, ['show' => 'all'], 2, 24, '/afwatering/?show=all'), 'base params ride in the query');
    }

    public function test_the_themes_old_params_after_woocommerces(): void
    {
        // Like Bestratingsmarkt: YITH's params and a price filter over mixed units go.
        $theme = static function (array $query): array {
            unset($query['yith_wcan'], $query['top'], $query['filter']['price']);
            if (($query['filter'] ?? null) === []) {
                unset($query['filter']);
            }
            return $query;
        };

        self::assertSame('/assortiment/', ArchiveUrl::redirect(self::PRODUCTS, ['yith_wcan' => '1'], 0, 24, '/assortiment/', $theme), 'a dropped param is a 301');
        self::assertSame('/afwatering/?utm_source=mail', ArchiveUrl::redirect(self::PRODUCTS, ['top' => '10', 'utm_source' => 'mail'], 0, 24, '/afwatering/', $theme), 'what it keeps stays');
        self::assertSame('/assortiment/', ArchiveUrl::redirect(self::PRODUCTS, ['min_price' => '10', 'max_price' => '50'], 0, 24, '/assortiment/', $theme), 'it sees what WooCommerce\'s params became');
        self::assertNull(ArchiveUrl::redirect(self::PRODUCTS, ['utm_source' => 'mail', 'q' => 'tegel'], 0, 24, '/assortiment/', $theme), 'nothing changed: no redirect');
        self::assertNull(ArchiveUrl::redirect(self::PRODUCTS, ['b' => '1', 'a' => '2'], 0, 24, '/', static fn(array $query): array => ['a' => '2', 'b' => '1']), 'the same params in another order are the same page');
    }

    public function test_a_form_submitted_without_javascript_redirects_to_its_normalized_url(): void
    {
        self::assertSame(
            '/afwatering/?show=all&filter%5Bbrand%5D%5B%5D=x&view=list',
            ArchiveUrl::redirect(self::PRODUCTS, ['token' => 'abc', 'show' => 'all', 'q' => '', 'offset' => '0', 'sort' => [''], 'filter' => ['brand' => ['x'], 'price' => ['min' => '', 'max' => '']], 'view' => 'list'], 0, 24, '/afwatering/?show=all'),
        );
    }

    public function test_the_urls_params_as_the_search_request(): void
    {
        self::assertSame(
            ['query' => 'tegel', 'filter' => ['color' => ['rood', 'wit'], 'price' => ['min' => '10']], 'sort' => ['price:asc'], 'offset' => 24],
            ArchiveUrl::request(['q' => ' tegel ', 'filter' => ['color' => ['wit', 'rood'], 'price' => ['min' => '10', 'max' => '']], 'sort' => ['price:asc'], 'offset' => '24', 'view' => 'list']),
        );
        self::assertSame(10000, ArchiveUrl::request(['offset' => '99999'])['offset']);
    }
}
