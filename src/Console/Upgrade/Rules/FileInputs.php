<?php

declare(strict_types=1);

namespace Gaffer\Console\Upgrade\Rules;

use Gaffer\Console\Upgrade\Change;
use Gaffer\Console\Upgrade\Rule;
use Gaffer\Console\Upgrade\Theme;

/**
 * CHANGELOG 2026-11-23: file inputs of Gaffer's forms are named fields[x] like every other input
 * (files[x] still read). Only in Gaffer's forms: the templates in views/forms/, those posting to
 * the Form action, and what they include (literal names); a theme's own action that reads
 * files[...] keeps its names.
 *
 * @internal
 */
final class FileInputs extends Rule
{
    private const string NAME = '/(\bname\s*=\s*["\'])files\[(?=[^\]])/';

    private const string INCLUDE = '/(?:include|source)\(\s*["\']([^"\'@]+\.twig)["\']|\{%-?\s*(?:include|embed)\s+["\']([^"\'@]+\.twig)["\']/';

    #[\Override]
    public function id(): string
    {
        return '2026-11-23-file-inputs';
    }

    #[\Override]
    public function describe(): string
    {
        return 'templates: name="files[x]" → name="fields[x]"';
    }

    #[\Override]
    public function changes(Theme $theme): array
    {
        $changes = [];
        foreach (self::form_templates($theme) as $path) {
            $code = (string) $theme->read($path);
            $after = (string) preg_replace(self::NAME, '$1fields[', $code);
            if ($after !== $code) {
                $changes[] = new Change($path, $code, $after);
            }
        }

        return $changes;
    }

    /**
     * Gaffer's form templates and the templates they include, transitively.
     *
     * @return list<string>
     */
    private static function form_templates(Theme $theme): array
    {
        $all = $theme->files(['views', 'blocks'], ['twig']);
        $queue = array_values(array_filter($all, static fn(string $path): bool => str_starts_with($path, 'views/forms/')
            || preg_match('/ajax_url\(\s*["\']Form["\']\s*\)/', (string) $theme->read($path)) === 1));
        $found = [];
        while ($queue !== []) {
            $path = array_shift($queue);
            if (isset($found[$path])) {
                continue;
            }
            $found[$path] = true;
            preg_match_all(self::INCLUDE, (string) $theme->read($path), $m);
            foreach (array_filter([...$m[1], ...$m[2]]) as $name) {
                $included = "views/{$name}";
                if (in_array($included, $all, true) && !isset($found[$included])) {
                    $queue[] = $included;
                }
            }
        }

        return array_keys($found);
    }
}
