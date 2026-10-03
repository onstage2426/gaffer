<?php

declare(strict_types=1);

namespace Gaffer\Forms;

/**
 * Gravity Forms' forms and fields as the theme sees them: what `forms:show` prints
 * and the `gaffer/forms` MCP tool returns.
 */
final class FormReport
{
    /**
     * Every form, by name.
     *
     * @return list<array{name: string, title: string, id: int, template: ?string, named_fields: int, unnamed_fields: int}>
     */
    public static function all(): array
    {
        if (!class_exists('GFAPI')) {
            return [];
        }
        $templates = FormTemplates::all();
        $forms = [];
        foreach (\GFAPI::get_forms() as $form) {
            $name = \sanitize_title((string) $form['title']);
            $gravity = GravityForm::find($name);
            $forms[] = [
                'name' => $name,
                'title' => (string) $form['title'],
                'id' => (int) $form['id'],
                'template' => isset($templates[$name]) ? "views/forms/{$name}.twig" : null,
                'named_fields' => $gravity ? count($gravity->fields()) : 0,
                'unnamed_fields' => $gravity ? count($gravity->unnamed()) : 0,
            ];
        }

        return $forms;
    }

    /**
     * One form's fields: named ones (the Admin Field Label the template sends) and
     * those without a name. Null when there's no such form.
     *
     * @return array{name: string, title: string, id: int, template: ?string, fields: list<array{name: ?string, label: string, type: string, required: bool, in_template: bool}>}|null
     */
    public static function one(string $name): ?array
    {
        $gravity = GravityForm::find($name);
        if ($gravity === null) {
            return null;
        }
        $templates = FormTemplates::all();
        $sent = isset($templates[$name]) ? FormTemplates::field_names($templates[$name]) : [];

        $fields = [];
        foreach ($gravity->fields() as $field_name => $field) {
            $fields[] = ['name' => $field_name, 'label' => (string) $field->label, 'type' => (string) $field->type,
                'required' => (bool) $field->isRequired, 'in_template' => in_array($field_name, $sent, true)];
        }
        foreach ($gravity->unnamed() as $field) {
            $fields[] = ['name' => null, 'label' => (string) $field->label, 'type' => (string) $field->type,
                'required' => (bool) $field->isRequired, 'in_template' => false];
        }

        return ['name' => $name, 'title' => $gravity->title(), 'id' => $gravity->id(),
            'template' => isset($templates[$name]) ? "views/forms/{$name}.twig" : null, 'fields' => $fields];
    }
}
