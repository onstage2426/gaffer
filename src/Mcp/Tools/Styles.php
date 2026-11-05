<?php

declare(strict_types=1);

namespace Gaffer\Mcp\Tools;

use Gaffer\Mcp\GafferCli;
use Gaffer\Mcp\Tool;
use WP_Error;

final class Styles implements Tool
{
    #[\Override]
    public function name(): string
    {
        return 'styles';
    }

    #[\Override]
    public function label(): string
    {
        return 'Computed styles of elements';
    }

    #[\Override]
    public function description(): string
    {
        return 'The computed styles of the elements matching CSS selectors, in a headless browser: on a path of this site (rendered like the render tool), '
            . 'a design\'s URL (DESIGN_COOKIE in the theme\'s .env for a design behind a login) or a local HTML file. Per match (at most 10): its box, the properties '
            . 'that differ from the browser\'s defaults (font, size, weight, line height, letter spacing, colours, spacing, borders, radius, shadow), ::before/::after, '
            . 'and with `children` the boxes of its children (alignment). Use to read exact values from a design instead of its CSS, and to check the same '
            . 'element on the site. For a whole page against a design, use the compare tool.';
    }

    #[\Override]
    public function input_schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'target' => ['type' => 'string', 'description' => 'A path of this site ("/contact/"), a URL ("https://design.example/home.html") or an HTML file'],
                'selectors' => ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 1, 'description' => 'CSS selectors, e.g. ["footer h2", ".hero .button"]'],
                'width' => ['type' => 'integer', 'minimum' => 200, 'maximum' => 3840, 'description' => 'Viewport width, default 1440'],
                'children' => ['type' => 'boolean', 'description' => 'Also the boxes of each match\'s children'],
                'theme' => ['type' => 'string', 'description' => 'For a path: render a full copy of the theme next to it (its directory name)'],
            ],
            'required' => ['target', 'selectors'],
        ];
    }

    #[\Override]
    public function run(array $input): array|WP_Error
    {
        $args = ['render:styles', (string) $input['target'], ...array_map('strval', (array) $input['selectors']), '--json'];
        if (is_int($input['width'] ?? null)) {
            $args[] = "--width={$input['width']}";
        }
        if (($input['children'] ?? false) === true) {
            $args[] = '--children';
        }
        if (is_string($input['theme'] ?? null)) {
            $args[] = "--theme={$input['theme']}";
        }

        return self::json(GafferCli::run($args), 'render:styles', 'gaffer_styles');
    }

    #[\Override]
    public function available(): bool
    {
        return true;
    }

    /**
     * The JSON result line of a command, or its error.
     *
     * @return array<string, mixed>|WP_Error
     */
    public static function json(string $stdout, string $command, string $code): array|WP_Error
    {
        $lines = array_filter(explode("\n", trim($stdout)));
        $result = json_decode((string) end($lines), true);
        if (!is_array($result)) {
            return new WP_Error($code, "{$command} gave no result: " . mb_substr((string) end($lines), 0, 500));
        }
        if (is_string($result['error'] ?? null)) {
            return new WP_Error($code, $result['error']);
        }

        /** @var array<string, mixed> $result */
        return $result;
    }
}
