<?php

declare(strict_types=1);

namespace Gaffer\Mcp\Tools;

use Gaffer\Console\Commands\DoctorRender;
use Gaffer\Console\Render\HtmlDiff;
use Gaffer\Mcp\GafferCli;
use Gaffer\Mcp\Tool;
use InvalidArgumentException;
use RuntimeException;
use WP_Error;

final class Render implements Tool
{
    #[\Override]
    public function name(): string
    {
        return 'render';
    }

    #[\Override]
    public function label(): string
    {
        return 'Render a URL';
    }

    #[\Override]
    public function description(): string
    {
        return 'Renders one URL of this site the way doctor does (in a fresh WordPress, Twig strict variables on, as a '
            . 'logged-out visitor unless `user` is given) and returns status (ok/redirect/error), the HTTP status (404 for a missing page), the redirect target, the error with file and '
            . 'line, PHP notices from theme files, time_ms, queries and twig_ms (template render time; compiling is extra while the Twig cache is off), and the HTML. '
            . 'Pass a CSS selector to get only the matching elements: whole pages are often 50-100 KB. `user` (a user ID) renders logged in; '
            . '`cart` (WooCommerce) puts products in the cart first: cart, checkout and account pages are where shops break. Nothing is written '
            . '(no session, the user\'s saved cart untouched). Use to check a page after a change.';
    }

    #[\Override]
    public function input_schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string', 'pattern' => '^/', 'description' => 'URL path with query string, e.g. "/contact/" or "/winkel/?s=shirt"'],
                'selector' => ['type' => 'string', 'description' => 'CSS selector, e.g. "main" or "#form-contact"; returns the outer HTML of every match'],
                'user' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Render logged in as this user ID'],
                'cart' => [
                    'type' => 'array',
                    'description' => 'WooCommerce: products to put in the cart first (a variation by its own ID)',
                    'items' => [
                        'type' => 'object',
                        'properties' => ['product' => ['type' => 'integer', 'minimum' => 1], 'quantity' => ['type' => 'integer', 'minimum' => 1]],
                        'required' => ['product'],
                    ],
                ],
            ],
            'required' => ['path'],
        ];
    }

    #[\Override]
    public function run(array $input): array|WP_Error
    {
        $args = ['doctor:render', (string) $input['path'], '--html'];
        if (isset($input['user'])) {
            $args[] = '--user=' . (int) $input['user'];
        }
        foreach (is_array($input['cart'] ?? null) ? $input['cart'] : [] as $item) {
            $args[] = '--cart=' . (int) ($item['product'] ?? 0) . ':' . max(1, (int) ($item['quantity'] ?? 1));
        }
        $output = GafferCli::run($args);
        try {
            $result = DoctorRender::parse($output);
        } catch (RuntimeException $e) {
            return new WP_Error('gaffer_render', ucfirst($e->getMessage()));
        }

        $selector = $input['selector'] ?? null;
        if (!is_string($selector) || $selector === '') {
            return $result;
        }

        $html = $result['html'] ?? '';
        unset($result['html']);
        try {
            $result['matches'] = HtmlDiff::select_all($html, $selector);
        } catch (InvalidArgumentException $e) {
            return new WP_Error('gaffer_render', $e->getMessage());
        }

        return $result;
    }

    #[\Override]
    public function available(): bool
    {
        return true;
    }
}
