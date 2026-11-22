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
    /** The file's format: one without it (before 2026-11-15) or of a newer one is refused. */
    public const int FORMAT = 1;

    public readonly Visitor $visitor;

    /**
     * @param array<string, Page> $pages by URL path
     * @param Visitor|null $visitor who rendered it: render:diff renders the same way
     */
    public function __construct(
        public readonly string $name,
        public readonly string $source,
        public readonly string $created,
        public readonly array $pages,
        ?Visitor $visitor = null,
    ) {
        $this->visitor = $visitor ?? Visitor::anonymous();
    }

    public static function file(string $name): string
    {
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $name)) {
            throw new InvalidArgumentException("\"{$name}\" is not a snapshot name (letters, digits, - and _)");
        }

        return Storage::theme_dir('snapshots') . "/{$name}.json";
    }

    public function save(): string
    {
        $file = self::file($this->name);
        $json = json_encode(['format' => self::FORMAT, 'source' => $this->source, 'created' => $this->created, 'visitor' => $this->visitor->to_array(), 'pages' => $this->pages], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
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
        return self::read($name, (string) file_get_contents($file));
    }

    /**
     * A snapshot from its file's contents.
     *
     * @throws RuntimeException on anything but a snapshot of this format
     */
    public static function read(string $name, string $json): self
    {
        $data = json_decode($json, true);
        if (!is_array($data) || !is_array($data['pages'] ?? null)) {
            throw new RuntimeException("Snapshot \"{$name}\" isn't a snapshot Gaffer can read: take it again with php gaffer render:snapshot {$name}");
        }
        $format = $data['format'] ?? null;
        if (!is_int($format)) {
            throw new RuntimeException("Snapshot \"{$name}\" was taken by an older Gaffer (no format): take it again with php gaffer render:snapshot {$name} (before changing the code, or with --from)");
        }
        if ($format > self::FORMAT) {
            throw new RuntimeException("Snapshot \"{$name}\" has format {$format}, from a newer Gaffer (this one reads " . self::FORMAT . "): update Gaffer, or take it again with php gaffer render:snapshot {$name}");
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

        $visitor = Visitor::from_array(is_array($data['visitor'] ?? null) ? $data['visitor'] : []);

        return new self($name, (string) ($data['source'] ?? ''), (string) ($data['created'] ?? ''), $pages, $visitor);
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

    /**
     * Where most pages redirect to, when they do (more than half of three or more pages, to one
     * URL apart from its query): a login, maintenance or geo plugin answering instead of the
     * site, which would make every comparison empty. Null when the pages are the site's own.
     *
     * @param array<string, array{http: int, redirect: ?string, error: ?string, html: string}> $pages
     */
    public static function redirected(array $pages): ?string
    {
        $targets = array_count_values(array_map(static fn(array $page): string => (string) strtok((string) $page['redirect'], '?'),
            array_filter($pages, static fn(array $page): bool => is_string($page['redirect']) && $page['redirect'] !== '')));
        arsort($targets);
        $target = array_key_first($targets);

        return $target !== null && count($pages) >= 3 && $targets[$target] * 2 > count($pages) ? $target : null;
    }
}
