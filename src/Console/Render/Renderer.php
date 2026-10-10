<?php

declare(strict_types=1);

namespace Gaffer\Console\Render;

use Gaffer\Console\Commands\DoctorRender;
use RuntimeException;
use WP_Post_Type;
use WP_Taxonomy;
use WP_Term;

/**
 * Renders URL paths of this site the way doctor does: each in its own child process
 * (`doctor:render`, a fresh WordPress with strict variables), several in parallel.
 * Used by doctor's render check and render:snapshot/render:diff.
 *
 * @phpstan-type Result array{path: string, status: string, error: ?string, file: ?string, line: ?int, redirect: ?string, http: int, notices: list<string>, bytes: int, html?: string}
 */
final class Renderer
{
    private const int PARALLEL = 4;
    private const int MAX_PAGES = 40;

    /** @param \Closure(string): void $progress called with each path as it starts */
    public function __construct(
        private readonly ?string $url,
        private readonly \Closure $progress,
    ) {}

    /**
     * doctor's sample: the front page, a search, a 404, up to MAX_PAGES pages, and one of
     * each public post type, archive and taxonomy.
     *
     * @return list<string>
     */
    public static function sample(): array
    {
        $urls = [home_url('/'), home_url('/?s=gaffer'), home_url('/gaffer-doctor-404/')];

        foreach (get_posts(['post_type' => 'page', 'post_status' => 'publish', 'numberposts' => self::MAX_PAGES, 'orderby' => 'menu_order']) as $page) {
            $urls[] = get_permalink($page);
        }

        foreach (get_post_types(['public' => true], 'objects') as $type) {
            /** @var WP_Post_Type $type */
            if (in_array($type->name, ['page', 'attachment'], true)) {
                continue;
            }
            $first = get_posts(['post_type' => $type->name, 'post_status' => 'publish', 'numberposts' => 1]);
            if ($first) {
                $urls[] = get_permalink($first[0]);
            }
            if ($type->has_archive || $type->name === 'post') {
                $urls[] = $type->name === 'post'
                    ? (get_option('page_for_posts') ? get_permalink((int) get_option('page_for_posts')) : null)
                    : get_post_type_archive_link($type->name);
            }
        }

        foreach (get_taxonomies(['public' => true], 'objects') as $taxonomy) {
            /** @var WP_Taxonomy $taxonomy */
            // Not $terms[0]: WooCommerce's term ordering keeps other keys.
            $terms = get_terms(['taxonomy' => $taxonomy->name, 'number' => 1, 'hide_empty' => true]);
            $term = is_array($terms) ? reset($terms) : null;
            if ($term instanceof WP_Term) {
                $link = get_term_link($term);
                $urls[] = is_string($link) ? $link : null;
            }
        }

        return self::paths($urls);
    }

    /**
     * Every published post of a public type whose content has one of these blocks (an ACF block when none given).
     *
     * @param list<string> $blocks
     * @return list<string>
     */
    public static function using_blocks(array $blocks): array
    {
        global $wpdb;

        $likes = array_map(static fn(string $b): string => $wpdb->prepare('post_content LIKE %s', '%' . $wpdb->esc_like("<!-- wp:{$b} ") . '%'), $blocks === [] ? ['acf/'] : $blocks);
        $types = array_values(get_post_types(['public' => true]));
        $ids = $wpdb->get_col(
            "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ('" . implode("','", array_map('esc_sql', $types)) . "')"
            . ' AND (' . implode(' OR ', $likes) . ') ORDER BY ID',
        );

        return self::paths(array_map(static fn(string $id): string|false => get_permalink((int) $id), $ids));
    }

    /**
     * @param list<string> $paths
     * @return array<string, Result|string> by path: the result, or why there is none
     */
    public function render(array $paths, bool $html = false): array
    {
        $entry = (string) realpath((string) $_SERVER['SCRIPT_FILENAME']);
        $queue = $paths;
        $running = [];
        $results = [];

        while ($queue !== [] || $running !== []) {
            while ($queue !== [] && count($running) < self::PARALLEL) {
                $path = array_shift($queue);
                ($this->progress)($path);
                $command = [PHP_BINARY, $entry, 'doctor:render', $path, '--no-ansi', ...($html ? ['--html'] : [])];
                if ($this->url !== null) {
                    $command[] = "--url={$this->url}";
                }
                $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                if ($process === false) {
                    $results[$path] = 'could not start the render process';
                    continue;
                }
                stream_set_blocking($pipes[1], false);
                stream_set_blocking($pipes[2], false);
                $running[$path] = [$process, $pipes, ''];
            }

            foreach ($running as $path => [$process, $pipes, $stdout]) {
                $stdout .= (string) stream_get_contents($pipes[1]);
                stream_get_contents($pipes[2]);
                $running[$path][2] = $stdout;

                if (!proc_get_status($process)['running']) {
                    $stdout .= (string) stream_get_contents($pipes[1]);
                    fclose($pipes[1]);
                    fclose($pipes[2]);
                    proc_close($process);
                    unset($running[$path]);
                    try {
                        $results[$path] = DoctorRender::parse($stdout);
                    } catch (RuntimeException $e) {
                        $results[$path] = $e->getMessage();
                    }
                }
            }

            usleep(20_000);
        }

        return array_replace(array_fill_keys($paths, 'not rendered'), $results);
    }

    /**
     * @param array<mixed> $urls
     * @return list<string>
     */
    private static function paths(array $urls): array
    {
        $paths = [];
        foreach (array_filter($urls, 'is_string') as $url) {
            $parts = parse_url($url);
            $paths[] = ($parts['path'] ?? '/') . (isset($parts['query']) ? "?{$parts['query']}" : '');
        }

        return array_values(array_unique($paths));
    }
}
