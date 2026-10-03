<?php

declare(strict_types=1);

namespace Gaffer\Forms;

use GF_Field;

/**
 * A Gravity Forms form used headless: the theme renders its own markup, Gravity
 * Forms validates, stores the entry, sends notifications and runs its add-on
 * feeds. Forms are found by the slug of their title ("Nieuwsbrief" →
 * nieuwsbrief), fields by their Admin Field Label: no IDs in the theme.
 */
final class GravityForm
{
    /** Field types that hold no value. */
    private const array LAYOUT = ['section', 'page', 'html', 'captcha'];

    /** @param array<string, mixed> $form */
    private function __construct(private readonly array $form) {}

    /**
     * The active form whose title slugs to $name, null when there is none (or no Gravity Forms).
     */
    public static function find(string $name): ?self
    {
        if (!class_exists('GFAPI')) {
            return null;
        }
        foreach (\GFAPI::get_forms() as $form) {
            if (\sanitize_title((string) $form['title']) === $name) {
                return new self($form);
            }
        }

        return null;
    }

    public function id(): int
    {
        return (int) $this->form['id'];
    }

    public function title(): string
    {
        return (string) $this->form['title'];
    }

    /**
     * Fields that hold a value, by Admin Field Label (fields without one are left out).
     *
     * @return array<string, GF_Field>
     */
    public function fields(): array
    {
        $fields = [];
        foreach ($this->data_fields() as $field) {
            if ((string) $field->adminLabel !== '') {
                $fields[(string) $field->adminLabel] = $field;
            }
        }

        return $fields;
    }

    /**
     * Fields that hold a value but have no Admin Field Label, so the theme can't send them.
     *
     * @return list<GF_Field>
     */
    public function unnamed(): array
    {
        return array_values(array_filter($this->data_fields(), static fn(GF_Field $f): bool => (string) $f->adminLabel === ''));
    }

    /**
     * Submits the values (by field name) through Gravity Forms: its validation,
     * entry, notifications and feeds.
     *
     * @param array<string, mixed> $values
     * @return array{valid: bool, errors: array<string, string>, message: ?string, redirect: ?string}
     */
    public function submit(array $values): array
    {
        $input = [];
        foreach ($this->fields() as $name => $field) {
            $input += self::input($field, $values[$name] ?? null);
        }

        $result = \GFAPI::submit_form($this->id(), $input);
        if ($result instanceof \WP_Error) {
            return ['valid' => false, 'errors' => ['' => $result->get_error_message()], 'message' => null, 'redirect' => null];
        }

        if (!($result['is_valid'] ?? false)) {
            $names = array_flip(array_map(static fn(GF_Field $f): int => (int) $f->id, $this->fields()));
            $errors = [];
            foreach ((array) ($result['validation_messages'] ?? []) as $id => $message) {
                $errors[(string) ($names[(int) $id] ?? '')] = trim(\wp_strip_all_tags((string) $message));
            }

            return ['valid' => false, 'errors' => $errors, 'message' => null, 'redirect' => null];
        }

        return ($result['confirmation_type'] ?? '') === 'redirect'
            ? ['valid' => true, 'errors' => [], 'message' => null, 'redirect' => (string) $result['confirmation_redirect']]
            : ['valid' => true, 'errors' => [], 'message' => (string) ($result['confirmation_message'] ?? ''), 'redirect' => null];
    }

    /**
     * What a successful submission shows, without submitting (the answer to spam,
     * which must look like a success).
     *
     * @return array{message: ?string, redirect: ?string}
     */
    public function default_confirmation(): array
    {
        foreach ((array) ($this->form['confirmations'] ?? []) as $confirmation) {
            if (!($confirmation['isDefault'] ?? false)) {
                continue;
            }

            return match ($confirmation['type'] ?? 'message') {
                'page' => ['message' => null, 'redirect' => (string) \get_permalink((int) ($confirmation['pageId'] ?? 0))],
                'redirect' => ['message' => null, 'redirect' => (string) ($confirmation['url'] ?? '')],
                default => ['message' => \wpautop((string) ($confirmation['message'] ?? '')), 'redirect' => null],
            };
        }

        return ['message' => null, 'redirect' => null];
    }

    /** @return list<GF_Field> */
    private function data_fields(): array
    {
        return array_values(array_filter(
            (array) ($this->form['fields'] ?? []),
            static fn(mixed $f): bool => $f instanceof GF_Field && !in_array($f->type, self::LAYOUT, true),
        ));
    }

    /**
     * Gravity Forms' input keys for one field's value: input_{id}, or input_{id}_{n}
     * for checkboxes (one per chosen choice) and consent (checked = 1).
     *
     * @return array<string, string>
     */
    private static function input(GF_Field $field, mixed $value): array
    {
        if ($field->type === 'consent') {
            return $value ? ["input_{$field->id}_1" => '1'] : [];
        }
        if ($field->type === 'checkbox') {
            $chosen = array_map('strval', (array) $value);
            $input = [];
            foreach ((array) $field->choices as $i => $choice) {
                $id = (string) ($field->inputs[$i]['id'] ?? '');
                if ($id !== '' && in_array((string) $choice['value'], $chosen, true)) {
                    $input['input_' . str_replace('.', '_', $id)] = (string) $choice['value'];
                }
            }
            return $input;
        }

        return ["input_{$field->id}" => is_scalar($value) ? (string) $value : ''];
    }
}
