<?php

declare(strict_types=1);

namespace Gaffer\Console\Checks;

use Gaffer\Console\Commands\DoctorRender;
use Gaffer\Console\Report;
use RuntimeException;
use WP_Post_Type;
use WP_Taxonomy;
use WP_Term;

/**
 * Renders a sample of real URLs (every page, plus one of each public post type,
 * archive and taxonomy, search and a 404) with strict variables, in parallel
 * child processes, and reports errors and PHP notices from theme files.
 */
final class RenderCheck implements Check
{
    private const int PARALLEL = 4;
    private const int MAX_PAGES = 40;

    /** @param \Closure(string): void $progress */
    public function __construct(
        private readonly string $entry,
        private readonly ?string $url,
        private readonly \Closure $progress,
    ) {}

    #[\Override]
    public function run(Report $report): void
    {
        $paths = self::paths();
        $results = $this->render_all($paths);

        // One shared template error shows up on every URL; report it once, with the URLs.
        $errors = [];
        $notices = [];
        foreach ($results as $path => $result) {
            if (is_string($result)) {
                $report->error('render', "{$path}: {$result}");
                continue;
            }
            if ($result['status'] === 'error') {
                $key = "{$result['error']}|{$result['file']}|{$result['line']}";
                $errors[$key] ??= ['result' => $result, 'paths' => []];
                $errors[$key]['paths'][] = $path;
            }
            foreach (array_unique($result['notices']) as $notice) {
                $notices[$notice][] = $path;
            }
        }

        foreach ($errors as ['result' => $result, 'paths' => $paths]) {
            $report->error('render', $result['error'] . self::on($paths), $result['file'], $result['line']);
        }
        foreach ($notices as $notice => $paths) {
            $report->warning('render', $notice . self::on($paths));
        }

        $ok = count(array_filter($results, static fn(array|string $r): bool => is_array($r) && $r['status'] !== 'error'));
        $report->info('render', "Rendered {$ok}/" . count($results) . ' URLs with strict variables');
    }

    /** @param list<string> $paths */
    private static function on(array $paths): string
    {
        $shown = array_slice($paths, 0, 3);
        $more = count($paths) - count($shown);

        return ' (on ' . implode(', ', $shown) . ($more > 0 ? " and {$more} more" : '') . ')';
    }

    /**
     * @return list<string> URL paths to render
     */
    private static function paths(): array
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

        $paths = [];
        foreach (array_filter($urls) as $url) {
            $parts = parse_url((string) $url);
            $paths[] = ($parts['path'] ?? '/') . (isset($parts['query']) ? "?{$parts['query']}" : '');
        }

        return array_values(array_unique($paths));
    }

    /**
     * @param list<string> $paths
     * @return array<string, array{path: string, status: string, error: ?string, file: ?string, line: ?int, redirect: ?string, http: int, notices: list<string>, bytes: int, html?: string}|string> a result, or why there is none
     */
    private function render_all(array $paths): array
    {
        $queue = $paths;
        $running = [];
        $results = [];

        while ($queue !== [] || $running !== []) {
            while ($queue !== [] && count($running) < self::PARALLEL) {
                $path = array_shift($queue);
                ($this->progress)($path);
                $command = [PHP_BINARY, $this->entry, 'doctor:render', $path, '--no-ansi'];
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
}
