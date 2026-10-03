<?php

declare(strict_types=1);

namespace Gaffer\Forms;

/**
 * What a form template (views/forms/{name}.twig) shows: the values sent, the
 * errors per field, or that it was sent and Gravity Forms' confirmation message.
 */
final readonly class FormState
{
    /**
     * @param array<string, mixed> $values by field name
     * @param array<string, string> $errors by field name ('' = about the whole form)
     */
    public function __construct(
        public string $name,
        public array $values = [],
        public array $errors = [],
        public bool $sent = false,
        public ?string $message = null,
        public bool $failed = false,
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

    public function error(string $field): ?string
    {
        return $this->errors[$field] ?? null;
    }
}
