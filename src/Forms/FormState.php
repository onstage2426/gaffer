<?php

declare(strict_types=1);

namespace Gaffer\Forms;

use Gaffer\Turnstile;

/**
 * What a form template (views/forms/{name}.twig) shows: the values sent, the
 * errors per field, that it was sent (`sent`, Gravity Forms' confirmation in
 * `message`), or that the whole form failed (`failed`, the reason in `message`
 * when Gravity Forms gave one).
 */
final readonly class FormState
{
    /**
     * Gaffer makes it.
     *
     * @param array<string, mixed> $values by field name
     * @param array<string, string> $errors by field name
     * @param array<string, list<KeptFile>> $files by field name
     *
     * @internal
     */
    public function __construct(
        /** the form's name (its template's, views/forms/{name}.twig) */
        public string $name,
        private array $values = [],
        private array $errors = [],
        /** submitted, Gravity Forms' confirmation in `message` */
        public bool $sent = false,
        /**
         * The confirmation when sent (Gravity Forms' HTML: print it with |raw), the reason when
         * failed (plain text: print it escaped), or null.
         */
        public ?string $message = null,
        /** the whole form failed (not one field) */
        public bool $failed = false,
        private array $files = [],
    ) {}

    /**
     * An empty form, for the first render on a page.
     */
    public static function blank(string $name): self
    {
        return new self($name);
    }

    /**
     * The value sent for a field (to fill it in again), '' when none.
     */
    public function value(string $field): string
    {
        $value = $this->values[$field] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Whether a checkbox field's choice (or a consent field, without $choice) was checked.
     */
    public function checked(string $field, ?string $choice = null): bool
    {
        $value = $this->values[$field] ?? null;

        return $choice === null ? (bool) $value : in_array($choice, array_map('strval', (array) $value), true);
    }

    /** The field's error, null when it has none. */
    public function error(string $field): ?string
    {
        return $this->errors[$field] ?? null;
    }

    /**
     * Turnstile's site key when forms check Turnstile (a site key and the server's secret),
     * else null: for Gaffer's @gaffer/forms/meta.twig, so the widget shows exactly when
     * FormAction checks its token.
     *
     * @internal
     */
    public function turnstile_site_key(): ?string
    {
        return Turnstile::site_key();
    }

    /**
     * The files kept for a File Upload field after a submission with errors: list
     * them and send each `value` back as `kept_files[field][]`.
     *
     * @return list<KeptFile>
     */
    public function files(string $field): array
    {
        return $this->files[$field] ?? [];
    }
}
