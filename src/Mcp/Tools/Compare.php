<?php

declare(strict_types=1);

namespace Gaffer\Mcp\Tools;

use Gaffer\Mcp\GafferCli;
use Gaffer\Mcp\Tool;
use WP_Error;

final class Compare implements Tool
{
    #[\Override]
    public function name(): string
    {
        return 'compare';
    }

    #[\Override]
    public function label(): string
    {
        return 'Compare a page with its design';
    }

    #[\Override]
    public function description(): string
    {
        return 'Compares a design (a URL, with DESIGN_COOKIE from the theme\'s .env for one behind a login, or a local HTML file) with the path of this site that '
            . 'implements it, in a headless browser at one width: every visible text of the design is paired with the same text on the site, and what differs is '
            . 'listed per text: font size, weight, line height, letter spacing, font, text-transform, colour and the gap to the text above. Also the texts the site '
            . 'lacks and the fonts each side uses. `within` compares one part (e.g. "footer"). Use after building a page from a design and after each round of fixes, '
            . 'at the design\'s widths (e.g. 390 and 1440); then the styles tool for one element\'s other properties.';
    }

    #[\Override]
    public function input_schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'design' => ['type' => 'string', 'description' => 'The design: a URL ("https://…") or an HTML file'],
                'path' => ['type' => 'string', 'pattern' => '^/', 'description' => 'The path of this site, e.g. "/"'],
                'width' => ['type' => 'integer', 'minimum' => 200, 'maximum' => 3840, 'description' => 'Viewport width, default 1440'],
                'within' => ['type' => 'string', 'description' => 'Only inside the first match of this selector on both pages'],
                'theme' => ['type' => 'string', 'description' => 'Render a full copy of the theme next to it (its directory name)'],
            ],
            'required' => ['design', 'path'],
        ];
    }

    #[\Override]
    public function run(array $input): array|WP_Error
    {
        $args = ['render:compare', (string) $input['design'], (string) $input['path'], '--json'];
        if (is_int($input['width'] ?? null)) {
            $args[] = "--width={$input['width']}";
        }
        foreach (['within', 'theme'] as $option) {
            if (is_string($input[$option] ?? null)) {
                $args[] = "--{$option}={$input[$option]}";
            }
        }

        return Styles::json(GafferCli::run($args), 'render:compare', 'gaffer_compare');
    }

    #[\Override]
    public function available(): bool
    {
        return true;
    }
}
