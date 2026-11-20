<?php

declare(strict_types=1);

namespace Gaffer\Types;

/**
 * Pagination numbers for the main query, plus the page list with ellipses.
 */
final class Pagination
{
    /**
     * @param int $page the current page, 1 for the first
     * @param int $total_items posts across all pages
     * @param int $results_start the first post shown, counted from 1 (0 without posts): "showing 25–48 of 120"
     * @param int $results_end the last post shown (0 without posts)
     */
    private function __construct(
        public readonly int $page,
        public readonly int $total_pages,
        public readonly int $total_items,
        public readonly int $per_page,
        public readonly int $results_start,
        public readonly int $results_end,
    ) {}

    /** The main query's pagination (archives, search results). */
    public static function current(): Pagination
    {
        global $wp_query;

        return self::from_counts(
            page: (int) $wp_query->get('paged'),
            total_pages: (int) $wp_query->max_num_pages,
            total_items: (int) $wp_query->found_posts,
            per_page: (int) $wp_query->get('posts_per_page'),
        );
    }

    /**
     * @internal The math, separate from WP_Query so it can be tested.
     * per_page < 1 means "all on one page" (posts_per_page = -1).
     */
    public static function from_counts(int $page, int $total_pages, int $total_items, int $per_page): Pagination
    {
        $page = max(1, $page);
        $per_page = $per_page > 0 ? $per_page : max(1, $total_items);

        $start = $total_items > 0 ? min(($page - 1) * $per_page + 1, $total_items) : 0;
        $end = $total_items > 0 ? min($page * $per_page, $total_items) : 0;

        return new self($page, max(0, $total_pages), $total_items, $per_page, $start, $end);
    }

    /** The previous page's number, null on the first. */
    public function previous(): ?int
    {
        return $this->page > 1 ? $this->page - 1 : null;
    }

    /** The next page's number, null on the last. */
    public function next(): ?int
    {
        return $this->page < $this->total_pages ? $this->page + 1 : null;
    }

    /** The previous page's URL, null on the first. */
    public function previous_link(): ?string
    {
        $previous = $this->previous();

        return $previous !== null ? self::link($previous) : null;
    }

    /** The next page's URL, null on the last. */
    public function next_link(): ?string
    {
        $next = $this->next();

        return $next !== null ? self::link($next) : null;
    }

    /**
     * The page list: `padding` pages on each side of the current one, plus the
     * first and last page, with a gap (`gap`, no number or link) where pages are
     * left out.
     *
     * @return list<PaginationPage>
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

        // Page number => shown (true) or a gap in its place (false).
        $shown = array_fill($start, $end - $start + 1, true);
        if ($start > 1) {
            $shown[1] = true;
            if ($start > 2) {
                $shown[2] = false;
            }
        }
        if ($end < $total) {
            if ($end < $total - 1) {
                $shown[$total - 1] = false;
            }
            $shown[$total] = true;
        }
        ksort($shown);

        $pages = [];
        foreach ($shown as $page => $is_page) {
            $pages[] = $is_page
                ? new PaginationPage($page, self::link($page), $page === $this->page, false)
                : new PaginationPage(null, null, false, true);
        }

        return $pages;
    }

    /**
     * Unescaped, like every URL a type returns: Twig escapes it. get_pagenum_link()
     * escapes by default ("&" → "&#038;"), and escaped twice the rest of the query
     * string turns into a fragment (?a=1&amp;#038;b=2 drops b).
     */
    private static function link(int $page): string
    {
        return \get_pagenum_link($page, false);
    }
}
