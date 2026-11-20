<?php

declare(strict_types=1);

namespace Gaffer\Forms;

use GF_Field;

/**
 * A Gravity Forms form used headless: the theme renders its own markup, Gravity
 * Forms validates, stores the entry, sends notifications and runs its add-on
 * feeds. Forms are found by the slug of their title ("Nieuwsbrief" →
 * nieuwsbrief), fields by their Admin Field Label: no IDs in the theme.
 *
 * @internal
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
     * entry, notifications and feeds. File Upload fields get the files the form
     * sent ($files, by field name, from Uploads::by_field()) and the files kept
     * from an earlier try ($kept, KeptFile values by field name); after errors,
     * `kept` has what Gravity Forms kept this time.
     *
     * @param array<string, mixed> $values
     * @param array<string, array<string, mixed>> $files
     * @param array<string, list<string>> $kept
     * @return array{valid: bool, errors: array<string, string>, failed: bool, message: ?string, redirect: ?string, kept: array<string, list<KeptFile>>}
     */
    public function submit(array $values, array $files = [], array $kept = []): array
    {
        $input = [];
        $uploads = [];
        foreach ($this->fields() as $name => $field) {
            if ($field->type !== 'fileupload') {
                $input += self::input($field, $values[$name] ?? null);
                continue;
            }
            if (isset($files[$name])) {
                $_FILES["input_{$field->id}"] = $files[$name]; // still PHP's upload: Gravity Forms checks is_uploaded_file()
            }
            $entries = array_values(array_filter(array_map(KeptFile::parse(...), $kept[$name] ?? [])));
            if ($entries !== []) {
                $uploads["input_{$field->id}"] = $entries;
            }
        }
        if ($uploads !== []) {
            $input['gform_uploaded_files'] = (string) json_encode($uploads, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        // Gravity Forms reads its input like a request's $_POST, slashed (rgpost() strips slashes).
        $result = \GFAPI::submit_form($this->id(), \wp_slash($input));
        if ($result instanceof \WP_Error) {
            return ['valid' => false, 'errors' => [], 'failed' => true, 'message' => trim(\wp_strip_all_tags($result->get_error_message())), 'redirect' => null, 'kept' => []];
        }

        if (!($result['is_valid'] ?? false)) {
            $names = array_flip(array_map(static fn(GF_Field $f): int => (int) $f->id, $this->fields()));
            $errors = [];
            $form_errors = [];
            foreach ((array) ($result['validation_messages'] ?? []) as $id => $message) {
                // a field with several files has one message per refused file ("a.php - ...")
                $message = implode(' ', array_map(static fn(mixed $m): string => trim(\wp_strip_all_tags(is_scalar($m) ? (string) $m : '')), (array) $message));
                if (isset($names[(int) $id])) {
                    $errors[$names[(int) $id]] = $message;
                } else {
                    $form_errors[] = $message; // a field the form's template doesn't have
                }
            }
            $kept = $this->kept();
            if ($errors === [] && $form_errors === []) {
                // Invalid without saying why: Gravity Forms answered in a shape Gaffer doesn't
                // read (an update?). Fail the form loudly instead of re-rendering it unchanged.
                error_log("Gaffer forms: Gravity Forms refused form {$this->id()} without a validation message; `php gaffer doctor --wp` checks how Gaffer submits");
                $form_errors[] = '';
            }

            return $form_errors === []
                ? ['valid' => false, 'errors' => $errors, 'failed' => false, 'message' => null, 'redirect' => null, 'kept' => $kept]
                : ['valid' => false, 'errors' => $errors, 'failed' => true, 'message' => trim(implode(' ', $form_errors)) ?: null, 'redirect' => null, 'kept' => $kept];
        }

        return ($result['confirmation_type'] ?? '') === 'redirect'
            ? ['valid' => true, 'errors' => [], 'failed' => false, 'message' => null, 'redirect' => (string) $result['confirmation_redirect'], 'kept' => []]
            : ['valid' => true, 'errors' => [], 'failed' => false, 'message' => (string) ($result['confirmation_message'] ?? ''), 'redirect' => null, 'kept' => []];
    }

    /**
     * The files Gravity Forms kept in its temporary folder after a submission with
     * errors (the valid files of fields without an error, and earlier kept ones), by field name.
     *
     * @return array<string, list<KeptFile>>
     */
    private function kept(): array
    {
        $by_input = \GFFormsModel::$uploaded_files[$this->id()] ?? [];
        $kept = [];
        foreach ($this->fields() as $name => $field) {
            $entries = $by_input["input_{$field->id}"] ?? null;
            if ($field->type === 'fileupload' && is_array($entries)) {
                $files = array_values(array_filter(array_map(KeptFile::from_gravity(...), $entries)));
                if ($files !== []) {
                    $kept[$name] = $files;
                }
            }
        }

        return $kept;
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
