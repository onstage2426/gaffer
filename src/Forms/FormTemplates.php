<?php

declare(strict_types=1);

namespace Gaffer\Forms;

use Gaffer\Console\Templates;
use Gaffer\Paths;

/**
 * The theme's form templates (views/forms/{name}.twig) and the field names they send.
 *
 * @internal
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
     * literal `fields[x]` or `files[x]` input.
     *
     * @return list<string>
     */
    public static function field_names(string $file): array
    {
        $code = (string) file_get_contents($file);
        preg_match_all('/\bname:\s*[\'"]([A-Za-z0-9_-]+)[\'"]|\b(?:fields|files)\[([A-Za-z0-9_-]+)\]/', $code, $m);
        $names = array_filter([...$m[1], ...$m[2]], static fn(string $n): bool => $n !== '');

        return array_values(array_unique($names));
    }

    /**
     * Names a template sends literally under one request key: `fields[x]`, or
     * `files[x]` (with `$list`: only `files[x][]`, several files).
     *
     * @param 'fields'|'files' $key
     * @return list<string>
     */
    public static function literal_names(string $file, string $key, bool $list = false): array
    {
        preg_match_all('/\b' . $key . '\[([A-Za-z0-9_-]+)\]' . ($list ? '\[\]' : '') . '/', (string) file_get_contents($file), $m);

        return array_values(array_unique($m[1]));
    }

    /**
     * Whether the form template sends multipart (files): htmx's `hx-encoding="multipart/form-data"`
     * or the form's `enctype`, in the template or a partial it includes (one that writes the
     * <form> tag for several forms). $name: the Twig name, `forms/{name}.twig`.
     */
    public static function multipart(string $name): bool
    {
        foreach (Templates::brought_in($name) as $template) {
            if (preg_match('/\b(?:hx-encoding|enctype)=["\']multipart\/form-data["\']/', (string) Templates::code($template))) {
                return true;
            }
        }

        return false;
    }
}
