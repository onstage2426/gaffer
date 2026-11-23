<?php

declare(strict_types=1);

namespace Gaffer\Forms;

/**
 * The files a form sent (`fields[field]` or `fields[field][]`, multipart; `files[…]` before
 * 2026-11-23), turned
 * from PHP's $_FILES['files'] shape (each property keyed by field) into one
 * $_FILES-style entry per field, as Gravity Forms reads `$_FILES['input_{id}']`.
 *
 * @internal
 */
final class Uploads
{
    private const array KEYS = ['name', 'type', 'tmp_name', 'error', 'size', 'full_path'];

    /**
     * @param mixed $files $_FILES['files']
     * @return array<string, array<string, mixed>> field name => {name, type, tmp_name, error, size, full_path}
     */
    public static function by_field(mixed $files): array
    {
        if (!is_array($files) || !is_array($files['name'] ?? null)) {
            return [];
        }
        $fields = [];
        foreach ($files['name'] as $field => $name) {
            if (!is_string($field) || !preg_match('/^[A-Za-z0-9_-]+$/', $field) || !self::shape_ok($name)) {
                continue; // files[x][y][z] or a numeric key: not a field's files
            }
            $entry = [];
            foreach (self::KEYS as $key) {
                if (isset($files[$key][$field])) {
                    $entry[$key] = $files[$key][$field];
                }
            }
            $fields[$field] = $entry;
        }

        return $fields;
    }

    /** One file (a name) or a list of them. */
    private static function shape_ok(mixed $name): bool
    {
        if (is_string($name)) {
            return true;
        }

        return is_array($name) && array_is_list($name) && array_all($name, static fn(mixed $n): bool => is_string($n));
    }
}
