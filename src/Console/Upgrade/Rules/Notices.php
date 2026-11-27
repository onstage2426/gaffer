<?php

declare(strict_types=1);

namespace Gaffer\Console\Upgrade\Rules;

use Gaffer\Console\Upgrade\Change;
use Gaffer\Console\Upgrade\Rule;
use Gaffer\Console\Upgrade\Theme;

/**
 * CHANGELOG 2026-11-27: WooCommerce's notices reach templates as `Types\Notice` (`message`,
 * `attrs`). The starter's notice overrides (and actions copied from them) built the same by hand:
 * that exact `array_map()` becomes `Notice::list(…)` over the same notices, and the template it
 * fills prints `notice.message` and `notice.attrs` (no `|raw`). When other code still fills that
 * template with notice data of its own, nothing changes. Other hand-written notice data (a theme's
 * own helper, an older copy without the attributes) is by hand.
 *
 * @internal
 */
final class Notices extends Rule
{
    /** The starter's closure mapped over any list of WooCommerce notices (one level of parentheses in it). */
    private const string MAP = '/array_map\(\s*fn\(array \$notice\): array => \[\s*["\']text["\'] => wc_kses_notice\(\$notice\[["\']notice["\']\]\),\s*["\']attrs["\'] => wc_get_notice_data_attr\(\$notice\)\s*\],\s*([^()]*(?:\([^()]*\)[^()]*)*?)\s*\)/';

    /** The data attributes' comment line above the starter's map: Notice::attrs() says it now. */
    private const string COMMENT = '/^[ \t]*\/\/ The data attributes WooCommerce\'s checkout script reads[^\n]*\R(?=[ \t]*["\']notices["\'] => (?:\\\\Gaffer\\\\Types\\\\)?Notice::list)/m';

    /** A render whose data has Notice::list(): the template it fills. */
    private const string RENDER = '/View::(?:render|fetch)\(\s*["\']([^"\']+\.twig)["\']\s*,\s*\[[^;]*?Notice::list\(/';

    /** @var array<string, string> the starter's print expressions → Notice's (a trusted comment after |raw goes too) */
    private const array PRINTS = [
        '/\{\{ notice\.attrs\|raw \}\}((?:(?!\{\{ notice\.)[^\n])*?)\{# trusted: wc_get_notice_data_attr\(\) #\}/' => '{{ notice.attrs }}$1',
        '/\{\{ notice\.text\|raw \}\}\{# trusted: [^#]*#\}/' => '{{ notice.message }}',
        '/\{\{ notice\.attrs\|raw \}\}/' => '{{ notice.attrs }}',
        '/\{\{ notice\.text\|raw \}\}/' => '{{ notice.message }}',
    ];

    #[\Override]
    public function id(): string
    {
        return '2026-11-27-notices';
    }

    #[\Override]
    public function describe(): string
    {
        return 'WooCommerce notices: the starter\'s array_map → Notice::list(…), notice.message/notice.attrs in the template it fills';
    }

    #[\Override]
    public function changes(Theme $theme): array
    {
        $changes = [];
        $templates = [];
        foreach ($theme->files(['woocommerce/notices', 'app', 'inc'], ['php']) as $path) {
            $code = (string) $theme->read($path);
            $uses = preg_match('/^use [^\n]+;$/m', $code) === 1;
            $after = (string) preg_replace(self::MAP, ($uses ? '' : '\\\\Gaffer\\\\Types\\\\') . 'Notice::list($1)', $code);
            if ($after === $code) {
                continue;
            }
            $after = (string) preg_replace(self::COMMENT, '', $after);
            if ($uses && preg_match('/^use Gaffer\\\\Types\\\\Notice;$/m', $after) !== 1) {
                $after = preg_match('/^use Gaffer\\\\View;$/m', $after) === 1
                    ? (string) preg_replace('/^use Gaffer\\\\View;$/m', "use Gaffer\\Types\\Notice;\nuse Gaffer\\View;", $after, 1)
                    : (string) preg_replace('/^(use [^\n]+;)$/m', "use Gaffer\\Types\\Notice;\n\$1", $after, 1);
            }
            $changes[] = new Change($path, $code, $after);
            preg_match_all(self::RENDER, $after, $m);
            foreach ($m[1] as $template) {
                $templates["views/{$template}"] = $template;
            }
        }
        $upgraded = $theme->with($changes);
        foreach ($templates as $path => $template) {
            // Code still filling the template with notice data of its own would break with it: all by hand then.
            foreach ($upgraded->files(['woocommerce', 'app', 'inc', 'views', 'blocks'], ['php', 'twig']) as $other) {
                $code = (string) $upgraded->read($other);
                if (str_contains($code, $template) && preg_match('/\bwc_kses_notice\(|\bwc_get_notice_data_attr\(/', $code) === 1) {
                    return [];
                }
            }
            $code = $theme->read($path);
            if ($code === null) {
                continue;
            }
            $after = (string) preg_replace(array_keys(self::PRINTS), array_values(self::PRINTS), $code);
            if ($after !== $code) {
                $changes[] = new Change($path, $code, $after);
            }
        }

        return $changes;
    }

    #[\Override]
    public function by_hand(Theme $theme): array
    {
        $todo = [];
        foreach ($theme->files(['woocommerce/notices', 'app', 'inc'], ['php']) as $path) {
            $code = (string) $theme->read($path);
            if (preg_match('/\bwc_kses_notice\(|\bwc_get_notice_data_attr\(/', $code) === 1) {
                $todo[] = "{$path} builds notice data by hand: Notice::list(\$notices) gives each notice's message and data attributes; print {{ notice.message }} and {{ notice.attrs }} in the template (CHANGELOG 2026-11-27).";
            }
        }
        foreach ($theme->files(['views'], ['twig']) as $path) {
            if (preg_match('/notice\.text\b|notice\.attrs\|raw/', (string) $theme->read($path)) === 1 && $this->rendered_by_notice_list($theme, $path)) {
                $todo[] = "{$path} prints notice.text or notice.attrs|raw: with Notice::list() it's {{ notice.message }} and {{ notice.attrs }} (CHANGELOG 2026-11-27).";
            }
        }

        return $todo;
    }

    private function rendered_by_notice_list(Theme $theme, string $template): bool
    {
        foreach ($theme->files(['woocommerce/notices', 'app', 'inc'], ['php']) as $path) {
            $code = (string) $theme->read($path);
            preg_match_all(self::RENDER, $code, $m);
            if (in_array($template, array_map(static fn(string $t): string => "views/{$t}", $m[1]), true)) {
                return true;
            }
        }

        return false;
    }
}
