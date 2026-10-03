<?php

declare(strict_types=1);

namespace Gaffer\Mcp\Tools;

use Gaffer\Forms\FormReport;
use Gaffer\Mcp\Tool;
use WP_Error;

final class Forms implements Tool
{
    #[\Override]
    public function name(): string
    {
        return 'forms';
    }

    #[\Override]
    public function label(): string
    {
        return 'Gravity Forms forms';
    }

    #[\Override]
    public function description(): string
    {
        return "Gravity Forms' forms as the theme sees them (the same as `php gaffer forms:show`). Without a name: every "
            . 'form with its name (title slug), template and field counts. With a name: its fields, each with the name '
            . 'the template sends (the Admin Field Label; null = not set), label, type, required, and whether '
            . 'views/forms/{name}.twig sends it. Use when writing or checking a form template.';
    }

    #[\Override]
    public function input_schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'Form name: the slug of its title, e.g. "contact"'],
            ],
        ];
    }

    #[\Override]
    public function run(array $input): array|WP_Error
    {
        $name = $input['name'] ?? null;
        if (!is_string($name) || $name === '') {
            return ['forms' => FormReport::all()];
        }

        return FormReport::one($name) ?? new WP_Error('gaffer_forms', "No active Gravity Forms form titled like \"{$name}\".");
    }

    #[\Override]
    public function available(): bool
    {
        return class_exists('GFAPI');
    }
}
