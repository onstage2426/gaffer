<?php

declare(strict_types=1);

namespace Gaffer;

/**
 * The theme's Vite assets: the build in public/ (via its manifest). There is no
 * dev server mode: a change shows after `npm run build`.
 * Every asset is a Vite entry ("assets/js/app.js", "assets/css/app.css"). Built
 * file names carry a content hash, so URLs need no version parameter.
 */
final class Vite
{
    /** @var array<string, array<string, mixed>>|null */
    private static ?array $manifest = null;

    /** @var array<string, true> URLs tags() already output, so a second call doesn't repeat them */
    private static array $printed = [];

    /**
     * The URL of an entry (a script passed to JavaScript, a stylesheet for an e-mail), null
     * when the build doesn't have it.
     */
    public static function url(string $asset): ?string
    {
        $file = self::manifest()[$asset]['file'] ?? null;

        return is_string($file) ? self::public_url() . '/' . $file : null;
    }

    /**
     * An entry the build doesn't have (not built yet, a renamed entry, a deploy without
     * public/): the page is missing its styles or scripts, so it stays out of page caches
     * (`DONOTCACHEPAGE`, no-cache headers while they can still be sent) until the build is there.
     * `doctor` names the entry (AssetsCheck).
     */
    private static function unbuilt(): void
    {
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        if (function_exists('nocache_headers') && !headers_sent()) {
            \nocache_headers();
        }
    }

    /**
     * The tags for these entries: <link rel="stylesheet"> for CSS,
     * <script type="module"> for JS (plus, in the build, the CSS it imports and a
     * modulepreload for each chunk it imports), a font preload for woff2. A URL already output
     * isn't repeated. An entry the build doesn't have prints nothing and keeps the page out of
     * page caches; an entry of another kind throws InvalidArgumentException.
     *
     * @param list<string> $assets
     */
    public static function tags(array $assets): void
    {
        foreach ($assets as $asset) {
            $url = self::url($asset);
            if ($url === null) {
                self::unbuilt();
                continue;
            }

            match (pathinfo($asset, PATHINFO_EXTENSION)) {
                'css', 'scss', 'sass', 'less', 'pcss' => self::output('style', $url),
                'js', 'mjs', 'ts', 'tsx', 'jsx' => self::output_built_script($asset, $url),
                'woff2' => self::output('font', $url),
                default => throw new \InvalidArgumentException("Vite::tags(): no tag for \"{$asset}\" (a style, script or woff2 entry)."),
            };
        }
    }

    /**
     * @internal For doctor.
     * @return array<string, array<string, mixed>>
     */
    public static function manifest(): array
    {
        if (self::$manifest === null) {
            $file = Paths::public() . '/.vite/manifest.json';
            $contents = is_file($file) ? (string) file_get_contents($file) : '';
            $manifest = json_validate($contents) ? json_decode($contents, true) : null;
            self::$manifest = is_array($manifest) ? $manifest : [];
        }

        return self::$manifest;
    }

    /**
     * A built JS entry: the CSS it and its imported chunks pull in, a
     * modulepreload per imported chunk, then the script.
     */
    private static function output_built_script(string $asset, string $url): void
    {
        $chunks = self::imported_chunks($asset);
        foreach ([$asset, ...$chunks] as $key) {
            foreach ((array) (self::manifest()[$key]['css'] ?? []) as $css) {
                self::output('style', self::public_url() . '/' . $css);
            }
        }
        foreach ($chunks as $key) {
            self::output('modulepreload', self::public_url() . '/' . self::manifest()[$key]['file']);
        }
        self::output('script', $url);
    }

    /**
     * The manifest keys of the chunks an entry imports statically, recursively.
     *
     * @param array<string, true> $seen
     * @return list<string>
     */
    private static function imported_chunks(string $key, array &$seen = []): array
    {
        $chunks = [];
        foreach ((array) (self::manifest()[$key]['imports'] ?? []) as $import) {
            if (is_string($import) && !isset($seen[$import]) && isset(self::manifest()[$import]['file'])) {
                $seen[$import] = true;
                $chunks[] = $import;
                array_push($chunks, ...self::imported_chunks($import, $seen));
            }
        }

        return $chunks;
    }

    /** @param 'script'|'style'|'modulepreload'|'font' $kind */
    private static function output(string $kind, string $url): void
    {
        if (isset(self::$printed[$url])) {
            return;
        }
        self::$printed[$url] = true;

        $href = \esc_url($url);
        echo match ($kind) {
            'script' => "<script type=\"module\" src=\"{$href}\"></script>\n",
            'style' => "<link rel=\"stylesheet\" href=\"{$href}\">\n",
            'modulepreload' => "<link rel=\"modulepreload\" href=\"{$href}\">\n",
            'font' => "<link rel=\"preload\" as=\"font\" type=\"font/woff2\" href=\"{$href}\" crossorigin>\n",
        };
    }

    private static function public_url(): string
    {
        return \get_template_directory_uri() . substr(Paths::public(), strlen(\get_template_directory()));
    }
}
