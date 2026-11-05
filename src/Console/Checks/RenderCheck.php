<?php

declare(strict_types=1);

namespace Gaffer\Console\Checks;

use Gaffer\Console\Render\HtmlProblems;
use Gaffer\Console\Render\Renderer;
use Gaffer\Paths;
use Gaffer\Console\Report;

/**
 * Renders a sample of real URLs (every page, plus one of each public post type,
 * archive and taxonomy, search and a 404) with strict variables, in parallel
 * child processes, and reports errors and PHP notices from theme files.
 */
final class RenderCheck implements Check
{
    /** @param \Closure(string): void $progress */
    public function __construct(
        private readonly ?string $url,
        private readonly \Closure $progress,
    ) {}

    #[\Override]
    public function run(Report $report): void
    {
        $results = new Renderer($this->url, $this->progress)->render(Renderer::sample(), html: true);

        // One shared template error shows up on every URL; report it once, with the URLs.
        $errors = [];
        $notices = [];
        $alpine = [];
        $sidebar = [];
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
            $html = $result['html'] ?? '';
            foreach (HtmlProblems::alpine_outside_scope($html) as $element) {
                $alpine[$element][] = $path;
            }
            if (HtmlProblems::theme_compat_sidebar($html)) {
                $sidebar[] = $path;
            }
        }

        foreach ($alpine as $element => $paths) {
            $report->warning('render', "Alpine directive outside any x-data, so Alpine ignores it: {$element}", null, null,
                'Seen' . self::on($paths) . '. Put x-data on the element or an ancestor (x-data on its own is enough for $store).');
        }
        if ($sidebar !== [] && !is_file(Paths::base('sidebar.php'))) {
            $report->warning('render', "WordPress's deprecated theme-compat sidebar.php is on the page (a search form, a list of every page, empty archive headings)", null, null,
                'Seen' . self::on($sidebar) . ". Something calls get_sidebar() and the theme has no sidebar.php: on WooCommerce pages it's the woocommerce_sidebar hook (remove_action('woocommerce_sidebar', 'woocommerce_get_sidebar') in inc/).");
        }

        foreach ($errors as ['result' => $result, 'paths' => $paths]) {
            $report->error('render', $result['error'] . self::on($paths), $result['file'], $result['line']);
        }
        foreach ($notices as $notice => $paths) {
            // The pages in the hint: doctor-baseline.json matches messages, and the sample changes.
            $report->warning('render', $notice, null, null, 'Seen' . self::on($paths) . '.');
        }

        $blind = Renderer::without_engine($results);
        if ($blind !== []) {
            $report->error('render', count($blind) . ' of ' . count($results) . ' renders didn\'t see the gaffer-search plugin this process sees: their archives show "unavailable"', null, null,
                'It is active only for this process (a prepend, wp --require), not for the site. Activate it for the site (network-wide on a multisite): it does nothing for a theme without config/search.php.');
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

}
