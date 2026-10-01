<?php

declare(strict_types=1);

namespace Gaffer\Types;

/**
 * Pagination numbers for the main query, plus the page list with ellipses.
 */
final class Pagination
{
    private function __construct(
        public readonly int $page,
        public readonly int $total_pages,
        public readonly int $items,
        public readonly int $total_items,
        public readonly int $per_page,
        public readonly int $results_start,
        public readonly int $results_end,
    ) {}

    public static function current(): Pagination
    {
        global $wp_query;

        return self::from_counts(
            page: (int) $wp_query->get('paged'),
            total_pages: (int) $wp_query->max_num_pages,
            items: (int) $wp_query->post_count,
            total_items: (int) $wp_query->found_posts,
            per_page: (int) $wp_query->get('posts_per_page'),
        );
    }

    /**
     * @internal The math, separate from WP_Query so it can be tested.
     * per_page < 1 means "all on one page" (posts_per_page = -1).
     */
    public static function from_counts(int $page, int $total_pages, int $items, int $total_items, int $per_page): Pagination
    {
        $page = max(1, $page);
        $per_page = $per_page > 0 ? $per_page : max(1, $total_items);

        $start = $total_items > 0 ? min(($page - 1) * $per_page + 1, $total_items) : 0;
        $end = $total_items > 0 ? min($page * $per_page, $total_items) : 0;

        return new self($page, max(0, $total_pages), $items, $total_items, $per_page, $start, $end);
    }

    public function previous(): ?int
    {
        return $this->page > 1 ? $this->page - 1 : null;
    }

    public function next(): ?int
    {
        return $this->page < $this->total_pages ? $this->page + 1 : null;
    }

    public function previous_link(): ?string
    {
        $previous = $this->previous();

        return $previous !== null ? \get_pagenum_link($previous) : null;
    }

    public function next_link(): ?string
    {
        $next = $this->next();

        return $next !== null ? \get_pagenum_link($next) : null;
    }

    /**
     * Page number => URL, with null for an ellipsis. Shows `padding` pages on
     * each side of the current one, plus the first and last page.
     *
     * @return array<int, string|null>
     */
    public function pages(int $padding = 2): array
    {
        $total = $this->total_pages;
        if ($total < 1) {
            return [];
        }

        $visible = 2 * $padding + 1;
        $start = max(1, $this->page - $padding);
        $end = min($total, max($this->page + $padding, $start + $visible - 1));
        $start = max(1, min($start, $end - $visible + 1));

        $pages = [];
        foreach (range($start, $end) as $page) {
            $pages[$page] = \get_pagenum_link($page);
        }

        if ($start > 1) {
            $pages[1] = \get_pagenum_link(1);
            if ($start > 2) {
                $pages[2] = null;
            }
        }
        if ($end < $total) {
            if ($end < $total - 1) {
                $pages[$total - 1] = null;
            }
            $pages[$total] = \get_pagenum_link($total);
        }

        ksort($pages);

        return $pages;
    }
}
