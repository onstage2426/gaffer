<?php

declare(strict_types=1);

namespace Gaffer\Mcp\Tools;

use Dom\HTMLDocument;
use Gaffer\Console\Commands\DoctorRender;
use Gaffer\Mcp\GafferCli;
use Gaffer\Mcp\Tool;
use RuntimeException;
use Throwable;
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
            . 'logged-out visitor) and returns status (ok/redirect/error), the HTTP status (404 for a missing page), the redirect target, the error with file and '
            . 'line, PHP notices from theme files, and the HTML. Pass a CSS selector to get only the matching elements: '
            . 'whole pages are often 50-100 KB. Use to check a page after a change.';
    }

    #[\Override]
    public function input_schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string', 'pattern' => '^/', 'description' => 'URL path with query string, e.g. "/contact/" or "/winkel/?s=shirt"'],
                'selector' => ['type' => 'string', 'description' => 'CSS selector, e.g. "main" or "#form-contact"; returns the outer HTML of every match'],
            ],
            'required' => ['path'],
        ];
    }

    #[\Override]
    public function run(array $input): array|WP_Error
    {
        $output = GafferCli::run(['doctor:render', (string) $input['path'], '--html']);
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
            $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);
            $result['matches'] = array_map(
                static fn(\Dom\Node $node): string => $document->saveHtml($node),
                iterator_to_array($document->querySelectorAll($selector), false),
            );
        } catch (Throwable $e) {
            return new WP_Error('gaffer_render', "Invalid selector \"{$selector}\": {$e->getMessage()}");
        }

        return $result;
    }

    #[\Override]
    public function available(): bool
    {
        return true;
    }
}
