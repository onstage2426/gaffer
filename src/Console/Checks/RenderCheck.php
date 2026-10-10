<?php

declare(strict_types=1);

namespace Gaffer\Console\Checks;

use Gaffer\Console\Render\Renderer;
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
        $results = new Renderer($this->url, $this->progress)->render(Renderer::sample());

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

}
