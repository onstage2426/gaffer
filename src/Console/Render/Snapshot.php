<?php

declare(strict_types=1);

namespace Gaffer\Console\Render;

use Gaffer\Storage;
use InvalidArgumentException;
use RuntimeException;

/**
 * A saved render of pages (render:snapshot) to compare against later (render:diff):
 * storage/snapshots/{name}.json, never committed (it's site content).
 *
 * @phpstan-type Page array{http: int, redirect: ?string, error: ?string, html: string}
 */
final class Snapshot
{
    /**
     * @param array<string, Page> $pages by URL path
     */
    public function __construct(
        public readonly string $name,
        public readonly string $source,
        public readonly string $created,
        public readonly array $pages,
    ) {}

    public static function file(string $name): string
    {
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $name)) {
            throw new InvalidArgumentException("\"{$name}\" is not a snapshot name (letters, digits, - and _)");
        }

        return Storage::private_dir('snapshots') . "/{$name}.json";
    }

    public function save(): string
    {
        $file = self::file($this->name);
        $json = json_encode(['source' => $this->source, 'created' => $this->created, 'pages' => $this->pages], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false || file_put_contents($file, $json) === false) {
            throw new RuntimeException("Could not write {$file}");
        }

        return $file;
    }

    public static function load(string $name): self
    {
        $file = self::file($name);
        if (!is_file($file)) {
            throw new RuntimeException("No snapshot \"{$name}\": make one with php gaffer render:snapshot {$name}");
        }
        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data) || !is_array($data['pages'] ?? null)) {
            throw new RuntimeException("{$file} isn't a snapshot Gaffer can read");
        }

        $pages = [];
        foreach ($data['pages'] as $path => $page) {
            $pages[(string) $path] = [
                'http' => (int) ($page['http'] ?? 0),
                'redirect' => is_string($page['redirect'] ?? null) ? $page['redirect'] : null,
                'error' => is_string($page['error'] ?? null) ? $page['error'] : null,
                'html' => (string) ($page['html'] ?? ''),
            ];
        }

        return new self($name, (string) ($data['source'] ?? ''), (string) ($data['created'] ?? ''), $pages);
    }

    /**
     * A page from a render result (Renderer::render() with html), or from why it has none.
     *
     * @param array{status: string, error: ?string, redirect: ?string, http: int, html?: string}|string $result
     * @return Page
     */
    public static function page(array|string $result): array
    {
        if (is_string($result)) {
            return ['http' => 0, 'redirect' => null, 'error' => $result, 'html' => ''];
        }

        return ['http' => $result['http'], 'redirect' => $result['redirect'], 'error' => $result['error'], 'html' => $result['html'] ?? ''];
    }
}
