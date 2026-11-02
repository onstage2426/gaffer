<?php

declare(strict_types=1);

namespace Gaffer\Console\Checks;

use Gaffer\Config;
use Gaffer\Console\ConfigStubs;
use Gaffer\Console\Report;
use Gaffer\Console\Templates;
use Gaffer\Console\ThemeFiles;
use Gaffer\Paths;
use Gaffer\Search\Indexes;

/**
 * config/search.php as far as Gaffer reads it (the card and its variants, the archive; the
 * engine's own keys are WordPressCheck::search()'s, the engine checks them), and theme code still
 * calling the gaffer-search plugin's theme API from before Gaffer\Search.
 */
final class SearchCheck implements Check
{
    private const array ARCHIVE_KEYS = ['index', 'per_page', 'sorts', 'default_sort', 'facets', 'redirect_search'];

    #[\Override]
    public function run(Report $report): void
    {
        $file = Paths::base('config/search.php');
        $templates = Templates::all();

        foreach (Indexes::all() as $name => $definition) {
            $card = $definition['card'] ?? null;
            if ($card !== null && (!is_string($card) || !isset($templates[$card]))) {
                $report->error('search', "Index \"{$name}\": card " . (is_string($card) ? "\"{$card}\" is not a template in views/" : 'must be a template name'), $file);
            }
            foreach (['card_context', 'card_variant'] as $key) {
                if (isset($definition[$key]) && !is_callable($definition[$key])) {
                    $report->error('search', "Index \"{$name}\": {$key} is not callable", $file, null, 'Use [Theme\\Class::class, \'method\'] with a public static method.');
                }
            }
            foreach ((array) ($definition['card_variants'] ?? []) as $variant => $wrap) {
                if (!is_callable($wrap)) {
                    $report->error('search', "Index \"{$name}\": card variant \"{$variant}\" is not callable", $file);
                }
            }
            if ((isset($definition['card_variants']) || isset($definition['card_context'])) && $card === null) {
                $report->warning('search', "Index \"{$name}\": card_variants and card_context do nothing without a card", $file);
            }
        }

        $archive = Config::get('search.archive');
        if (is_array($archive)) {
            $index = $archive['index'] ?? null;
            if (!is_string($index) || Indexes::get($index) === null) {
                $report->error('search', 'archive: index must name one of the indexes' . (is_string($index) ? " (\"{$index}\" isn't one)" : ''), $file);
            } elseif ((Indexes::get($index)['type'] ?? null) !== 'products') {
                $report->error('search', "archive: index \"{$index}\" isn't of type products (the archive is WooCommerce's shop)", $file);
            }
            foreach (array_diff(array_keys($archive), self::ARCHIVE_KEYS) as $key) {
                $suggestion = ConfigStubs::did_you_mean((string) $key, self::ARCHIVE_KEYS);
                $report->error('search', "archive: unknown key \"{$key}\"" . ($suggestion !== null ? " (did you mean \"{$suggestion}\"?)" : ''), $file);
            }
        }

        $this->old_api($report);
    }

    /** gaffer_search_*() calls, its hooks and window.GafferSearch: replaced by config/search.php and Gaffer\Search. */
    private function old_api(Report $report): void
    {
        foreach (ThemeFiles::find(['php', 'twig', 'js']) as $file) {
            $code = (string) file_get_contents($file);
            if (preg_match('/\bgaffer_search_\w+|window\.GafferSearch\b|\bGafferSearch\.\w+\(/', $code, $match, PREG_OFFSET_CAPTURE)) {
                $report->warning('search', 'Uses the gaffer-search plugin\'s theme API from before Gaffer\\Search', $file, substr_count($code, "\n", 0, $match[0][1]) + 1,
                    "{$match[0][0]}: move to config/search.php, Gaffer\\Search\\Search and resources/js/search.js (see the gaffer-search skill and CHANGELOG.md).");
            }
        }
    }
}
