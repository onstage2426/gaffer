<?php

declare(strict_types=1);

namespace Gaffer\Tests;

use Gaffer\Types\Pagination;

final class PaginationTest extends TestCase
{
    public function test_results_range_on_a_middle_page(): void
    {
        $p = Pagination::from_counts(page: 2, total_pages: 3, items: 10, total_items: 25, per_page: 10);

        self::assertSame([2, 11, 20], [$p->page, $p->results_start, $p->results_end]);
        self::assertSame(1, $p->previous());
        self::assertSame(3, $p->next());
    }

    public function test_last_page_is_capped_at_total(): void
    {
        $p = Pagination::from_counts(page: 3, total_pages: 3, items: 5, total_items: 25, per_page: 10);

        self::assertSame([21, 25], [$p->results_start, $p->results_end]);
        self::assertNull($p->next());
        self::assertNull($p->next_link());
        self::assertSame('/page/2/', $p->previous_link());
    }

    public function test_page_zero_means_first_page(): void
    {
        $p = Pagination::from_counts(page: 0, total_pages: 2, items: 10, total_items: 15, per_page: 10);

        self::assertSame(1, $p->page);
        self::assertNull($p->previous());
    }

    public function test_show_all_does_not_go_negative(): void
    {
        // posts_per_page = -1
        $p = Pagination::from_counts(page: 1, total_pages: 1, items: 25, total_items: 25, per_page: -1);

        self::assertSame([1, 25, 25], [$p->results_start, $p->results_end, $p->per_page]);
    }

    public function test_no_results(): void
    {
        $p = Pagination::from_counts(page: 1, total_pages: 0, items: 0, total_items: 0, per_page: 10);

        self::assertSame([0, 0], [$p->results_start, $p->results_end]);
        self::assertSame([], $p->pages());
        self::assertNull($p->next());
    }

    public function test_pages_near_the_start(): void
    {
        $p = Pagination::from_counts(page: 1, total_pages: 10, items: 10, total_items: 100, per_page: 10);

        self::assertSame([1, 2, 3, 4, 5, 9, 10], array_keys($p->pages()));
        self::assertNull($p->pages()[9]);
        self::assertSame('/page/10/', $p->pages()[10]);
    }

    public function test_pages_in_the_middle(): void
    {
        $p = Pagination::from_counts(page: 5, total_pages: 10, items: 10, total_items: 100, per_page: 10);

        self::assertSame([1, 2, 3, 4, 5, 6, 7, 9, 10], array_keys($p->pages()));
        self::assertNull($p->pages()[2]);
        self::assertSame('/page/3/', $p->pages()[3]);
        self::assertNull($p->pages()[9]);
    }

    public function test_pages_near_the_end(): void
    {
        $p = Pagination::from_counts(page: 10, total_pages: 10, items: 10, total_items: 100, per_page: 10);

        self::assertSame([1, 2, 6, 7, 8, 9, 10], array_keys($p->pages()));
        self::assertNull($p->pages()[2]);
    }

    public function test_few_pages_have_no_ellipsis(): void
    {
        $p = Pagination::from_counts(page: 2, total_pages: 3, items: 10, total_items: 30, per_page: 10);

        self::assertSame(['/page/1/', '/page/2/', '/page/3/'], array_values($p->pages()));
    }
}
