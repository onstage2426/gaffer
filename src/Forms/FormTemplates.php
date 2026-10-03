<?php

declare(strict_types=1);

namespace Gaffer\Forms;

use Gaffer\Paths;

/**
 * The theme's form templates (views/forms/{name}.twig) and the field names they send.
 */
final class FormTemplates
{
    /** @return array<string, string> name => file */
    public static function all(): array
    {
        $templates = [];
        foreach (glob(Paths::views() . '/forms/*.twig') ?: [] as $file) {
            $templates[basename($file, '.twig')] = $file;
        }

        return $templates;
    }

    /**
     * Field names a template sends: `name: 'x'` passed to a field component, or a
     * literal `fields[x]` input.
     *
     * @return list<string>
     */
    public static function field_names(string $file): array
    {
        $code = (string) file_get_contents($file);
        preg_match_all('/\bname:\s*[\'"]([A-Za-z0-9_-]+)[\'"]|\bfields\[([A-Za-z0-9_-]+)\]/', $code, $m);
        $names = array_filter([...$m[1], ...$m[2]], static fn(string $n): bool => $n !== '');

        return array_values(array_unique($names));
    }
}
