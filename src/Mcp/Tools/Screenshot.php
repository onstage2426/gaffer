<?php

declare(strict_types=1);

namespace Gaffer\Mcp\Tools;

use Gaffer\Mcp\GafferCli;
use Gaffer\Mcp\Tool;
use WP_Error;

final class Screenshot implements Tool
{
    #[\Override]
    public function name(): string
    {
        return 'screenshot';
    }

    #[\Override]
    public function label(): string
    {
        return 'Screenshot a page';
    }

    #[\Override]
    public function description(): string
    {
        return 'Screenshots one URL of this site in a headless browser at the given widths (default 390 and 1440) and returns the PNG files '
            . '(read them to look at them), the console errors and the failed requests of the page itself. The page is rendered the way the render tool does; '
            . 'styles, scripts, images and ajax come from the site, as a new logged-out visitor\'s, or with `session` as the visitor of a `php gaffer ajax:call --session` session (its cart in the page and in ajax answers). `steps` click, hover or fill elements first, '
            . 'in order (the first visible match), e.g. to open a menu or a drawer, or to check a hover state next to an active one. `local_storage` sets a returning visitor\'s stored state '
            . '(a VAT toggle, a dismissed banner) before the page loads: check both states of anything a page remembers. `full` scrolls through the whole page (lazy images) and returns it in parts '
            . 'of `slice` pixels (`files`, with the page `height`); prefer the first screen and steps where they show it. Use after any change with a visual effect, and to compare with a design. A Turnstile error in the console is expected (it rejects headless browsers). '
            . 'Needs Playwright in the theme (`npm install --save-dev playwright`); the error says what is missing.';
    }

    #[\Override]
    public function input_schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string', 'pattern' => '^/', 'description' => 'URL path with query string, e.g. "/contact/"'],
                'widths' => ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 200, 'maximum' => 3840], 'description' => 'Viewport widths in pixels, default [390, 1440]'],
                'full' => ['type' => 'boolean', 'description' => 'The whole page instead of the first screen, in parts'],
                'slice' => ['type' => 'integer', 'minimum' => 0, 'description' => 'With full: the height of each part in pixels, default 1200 (0: one image)'],
                'local_storage' => ['type' => 'object', 'additionalProperties' => ['type' => 'string'], 'description' => 'localStorage items set before the page loads, e.g. {"vat": "incl"}'],
                'steps' => [
                    'type' => 'array',
                    'description' => 'Before the screenshot, in order: {"click": "<selector>"}, {"hover": "<selector>"} or {"fill": "<selector>", "value": "<text>"}',
                    'items' => [
                        'type' => 'object',
                        'properties' => ['click' => ['type' => 'string'], 'hover' => ['type' => 'string'], 'fill' => ['type' => 'string'], 'value' => ['type' => 'string']],
                    ],
                ],
                'session' => ['type' => 'string', 'description' => 'The name of a session started with `php gaffer ajax:call <action> --session=<name>` (e.g. after adding to the cart)'],
                'theme' => ['type' => 'string', 'description' => 'Render a full copy of the theme next to it (its directory name, e.g. "blueprint-stage"), with its own assets'],
            ],
            'required' => ['path'],
        ];
    }

    #[\Override]
    public function run(array $input): array|WP_Error
    {
        $args = ['render:screenshot', (string) $input['path'], '--json'];
        if (is_array($input['widths'] ?? null) && $input['widths'] !== []) {
            $args[] = '--width=' . implode(',', array_map('intval', $input['widths']));
        }
        if (($input['full'] ?? false) === true) {
            $args[] = '--full';
        }
        if (is_int($input['slice'] ?? null)) {
            $args[] = "--slice={$input['slice']}";
        }
        foreach (is_array($input['local_storage'] ?? null) ? $input['local_storage'] : [] as $key => $value) {
            $args[] = "--local-storage={$key}=" . (is_scalar($value) ? (string) $value : (string) json_encode($value));
        }
        foreach (is_array($input['steps'] ?? null) ? $input['steps'] : [] as $step) {
            if (is_string($step['click'] ?? null)) {
                $args[] = "--click={$step['click']}";
            } elseif (is_string($step['hover'] ?? null)) {
                $args[] = "--hover={$step['hover']}";
            } elseif (is_string($step['fill'] ?? null)) {
                $args[] = "--fill={$step['fill']}=" . (string) ($step['value'] ?? '');
            } else {
                return new WP_Error('gaffer_screenshot', 'A step is {"click": selector}, {"hover": selector} or {"fill": selector, "value": text}');
            }
        }
        if (is_string($input['session'] ?? null)) {
            $args[] = "--session={$input['session']}";
        }
        if (is_string($input['theme'] ?? null)) {
            $args[] = "--theme={$input['theme']}";
        }

        $lines = array_filter(explode("\n", trim(GafferCli::run($args))));
        $result = json_decode((string) end($lines), true);
        if (!is_array($result)) {
            return new WP_Error('gaffer_screenshot', 'render:screenshot gave no result: ' . mb_substr((string) end($lines), 0, 500));
        }
        if (is_string($result['error'] ?? null)) {
            return new WP_Error('gaffer_screenshot', $result['error']);
        }

        return $result;
    }

    #[\Override]
    public function available(): bool
    {
        return true;
    }
}
