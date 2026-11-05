<?php

declare(strict_types=1);

namespace Gaffer\Console\Checks;

use FilesystemIterator;
use Gaffer\Console\Report;
use Gaffer\Console\Templates;
use Gaffer\Console\ThemeFiles;
use Gaffer\Gaffer;
use Gaffer\Paths;
use Gaffer\Vite;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The Vite build: present, not older than the code Vite compiles in assets/ (JS,
 * CSS and their preprocessors; images and fonts served straight from assets/ don't
 * count: one a stylesheet uses comes with an edit to that stylesheet), and holding
 * every entry the theme's PHP asks Vite for. Templates changed since the build are
 * checked for classes the built CSS doesn't have (Tailwind scans templates too: a new
 * utility in a template needs a build as much as a CSS edit). Skipped while the dev
 * server runs.
 */
final class AssetsCheck implements Check
{
    private const string CALL = '/Vite::(?:tags|url)\(\s*(\[[^\]]*\]|([\'"])[^\'"]+\2)/';

    /** What Vite compiles: a change to one of these needs a new build. */
    private const array CODE = ['js', 'mjs', 'cjs', 'ts', 'mts', 'jsx', 'tsx', 'vue', 'svelte', 'css', 'scss', 'sass', 'less', 'styl', 'pcss', 'postcss'];

    #[\Override]
    public function run(Report $report): void
    {
        $sources = Paths::base('assets');
        if (!is_dir($sources) || Vite::is_dev_mode()) {
            return;
        }

        $manifest_file = Paths::public() . '/.vite/manifest.json';
        if (!is_file($manifest_file)) {
            $report->error('assets', 'No Vite build (public/.vite/manifest.json is missing)', null, null, 'Run `npm run build`.');
            return;
        }

        $newest = self::newest($sources);
        if ($newest !== null && $newest[0] > (int) filemtime($manifest_file)) {
            // The file in the hint: doctor-baseline.json matches messages.
            $report->warning('assets', 'The build is older than its sources', null, null,
                substr($newest[1], strlen(Paths::base()) + 1) . ' changed since. Run `npm run build` (and deploy public/).');
        }

        $manifest = Vite::manifest();
        $this->new_classes($report, $manifest, (int) filemtime($manifest_file));
        // The whole file: a call's arguments may span lines.
        foreach (ThemeFiles::find(['php']) as $file) {
            $code = (string) file_get_contents($file);
            preg_match_all(self::CALL, $code, $calls, PREG_OFFSET_CAPTURE);
            foreach ($calls[1] as [$arguments, $offset]) {
                preg_match_all('/([\'"])([^\'"]+)\1/', $arguments, $assets);
                foreach ($assets[2] as $asset) {
                    if (!isset($manifest[$asset])) {
                        $report->error('assets', "{$asset} is not a Vite entry (not in the manifest)", $file, substr_count($code, "\n", 0, $offset) + 1,
                            'Add it to the entries in vite.config.js and build, or fix the path.');
                    }
                }
            }
        }
    }

    /**
     * Templates changed since the build whose literal classes aren't all in the built CSS.
     * Only changed templates: a class that is no Tailwind utility (a JavaScript hook) is
     * then reported once, until the next build.
     *
     * @param array<string, mixed> $manifest
     */
    private function new_classes(Report $report, array $manifest, int $built): void
    {
        $css = '';
        foreach ($manifest as $chunk) {
            foreach (is_array($chunk) ? [$chunk['file'] ?? null, ...(array) ($chunk['css'] ?? [])] : [] as $file) {
                if (is_string($file) && str_ends_with($file, '.css') && is_file($path = Paths::public() . '/' . $file)) {
                    $css .= (string) file_get_contents($path);
                }
            }
        }
        if ($css === '') {
            return;
        }

        Gaffer::twig();
        foreach (Templates::all() as $file) {
            if ((int) filemtime($file) <= $built) {
                continue;
            }
            $missing = array_values(array_filter(self::classes((string) file_get_contents($file)), static fn(string $class): bool => !str_contains($css, '.' . self::escape($class))));
            if ($missing !== []) {
                $report->warning('assets', 'Changed since the build, with classes the built CSS doesn\'t have', $file, null,
                    implode(' ', array_slice($missing, 0, 8)) . (count($missing) > 8 ? ' …' : '') . '. Tailwind utilities only exist after `npm run build` (ignore it for a class that is no utility, like a JavaScript hook: it goes away with the next build).');
            }
        }
    }

    /**
     * The class names written literally in a template's class attributes (Twig output
     * in them left out).
     *
     * @return list<string>
     */
    public static function classes(string $source): array
    {
        $classes = [];
        foreach (TagsCheck::tags($source) as $tag) {
            foreach ($tag['attrs'] as [$name, $value]) {
                if (strtolower($name) === 'class' && $value !== null) {
                    $plain = (string) preg_replace('/\{\{.*?\}\}|\{%.*?%\}/s', ' ', $value);
                    foreach (preg_split('/\s+/', trim($plain), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $class) {
                        $classes[$class] = true;
                    }
                }
            }
        }

        return array_map('strval', array_keys($classes));
    }

    /** A class name as a CSS selector writes it (CSS.escape for the characters Tailwind uses). */
    public static function escape(string $class): string
    {
        $escaped = (string) preg_replace('/[^a-zA-Z0-9_\x{80}-\x{10FFFF}-]/u', '\\\\$0', $class);

        // A leading digit becomes a code point escape ("2xl:" → "\32 xl\:").
        return preg_match('/^[0-9]/', $escaped) ? '\\' . dechex(ord($escaped[0])) . ' ' . substr($escaped, 1) : $escaped;
    }

    /**
     * The most recently changed code file under $dir: [mtime, path].
     *
     * @return array{int, string}|null
     */
    private static function newest(string $dir): ?array
    {
        $newest = null;
        /** @var iterable<SplFileInfo> $files */
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->isFile() && in_array(strtolower($file->getExtension()), self::CODE, true) && ($newest === null || $file->getMTime() > $newest[0])) {
                $newest = [$file->getMTime(), $file->getPathname()];
            }
        }

        return $newest;
    }
}
