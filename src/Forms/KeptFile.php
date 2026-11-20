<?php

declare(strict_types=1);

namespace Gaffer\Forms;

/**
 * A file Gravity Forms kept from a submission that had errors (a browser can't
 * refill a file input). The template lists it and sends `value` back in a hidden
 * `kept_files[field][]` input; leaving that input out drops the file.
 * `value` is Gravity Forms' own record of the file, signed by Gravity Forms.
 */
final readonly class KeptFile
{
    /** @internal */
    public function __construct(
        /** The visitor's file name. */
        public string $name,
        /** What the hidden kept_files[field][] input sends back. */
        public string $value,
    ) {}

    /**
     * From an entry of Gravity Forms' kept list (GFFormsModel::$uploaded_files), null for an odd one.
     *
     * @internal
     */
    public static function from_gravity(mixed $entry): ?self
    {
        $details = self::details($entry);

        return $details === null ? null : new self($details['uploaded_filename'], (string) json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * A `value` sent back, as Gravity Forms' entry again; null when it isn't one.
     *
     * @return array{temp_filename: string, uploaded_filename: string, hash: string}|null
     *
     * @internal
     */
    public static function parse(mixed $value): ?array
    {
        return is_string($value) ? self::details(json_decode($value, true)) : null;
    }

    /** @return array{temp_filename: string, uploaded_filename: string, hash: string}|null */
    private static function details(mixed $entry): ?array
    {
        if (!is_array($entry)) {
            return null;
        }
        $details = [];
        foreach (['temp_filename', 'uploaded_filename', 'hash'] as $key) {
            if (!is_string($entry[$key] ?? null) || $entry[$key] === '') {
                return null;
            }
            $details[$key] = $entry[$key];
        }

        return $details;
    }
}
