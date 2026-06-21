<?php

declare(strict_types=1);

namespace Gaffer\Types;

use WP_Query;

class Pagination
{
    public int $page;
    public int $total_pages;
    public int $items;
    public int $total_items;
    public int $per_page;
    public int $results_start;
    public int $results_end;

    public static function build(WP_Query $query): static
    {
        $pagination = new static();
        $pagination->page = max(1, $query->get('paged'));
        $pagination->total_pages = $query->max_num_pages;
        $pagination->items = $query->post_count;
        $pagination->total_items = $query->found_posts;
        $pagination->per_page = $query->get('posts_per_page');
        $pagination->results_start = ($pagination->page - 1) * $pagination->per_page + 1;
        $pagination->results_end = min($pagination->results_start + $pagination->per_page - 1, $pagination->total_items);

        return $pagination;
    }

    public function current(): int
    {
        return $this->page;
    }

    public function previous(): ?int
    {
        $current = $this->current();
        return $current > 1 ? $current - 1 : null;
    }

    public function previous_link(): ?string
    {
        $previous = $this->previous();
        return $previous !== null ? \get_pagenum_link($previous) : null;
    }

    public function next(): ?int
    {
        $current = $this->current();
        return $current < $this->total_pages ? $current + 1 : null;
    }

    public function next_link(): ?string
    {
        $next = $this->next();
        return $next !== null ? \get_pagenum_link($next) : null;
    }

    public function pages(int $padding = 2): array
    {
        $totalVisiblePages = 2 * $padding + 1;

        $total = $this->total_pages;
        $current = $this->current();

        $startPage = $current - $padding;
        $endPage = $current + $padding;

        if ($startPage < 1) {
            $startPage = 1;
            $endPage = min($total, $startPage + $totalVisiblePages - 1);
        }

        if ($endPage > $total) {
            $endPage = $total;
            $startPage = max(1, $endPage - $totalVisiblePages + 1);
        }

        $range = range($startPage, $endPage);

        $pages = array_combine(
            $range,
            array_map(fn($page) => \get_pagenum_link($page), $range),
        );

        if ($startPage > 1) {
            $pages[1] = \get_pagenum_link(1);
            if ($startPage > 2) {
                $pages[2] = null;
            }
        }

        if ($endPage < $total) {
            if ($endPage < $total - 1) {
                $pages[$total - 1] = null;
            }
            $pages[$total] = \get_pagenum_link($total);
        }

        ksort($pages);

        return $pages;
    }
}
