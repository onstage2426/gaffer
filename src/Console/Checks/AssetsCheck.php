<?php

declare(strict_types=1);

namespace Gaffer\Console\Checks;

use FilesystemIterator;
use Gaffer\Console\Report;
use Gaffer\Console\ThemeFiles;
use Gaffer\Paths;
use Gaffer\Vite;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The Vite build: present, not older than the code Vite compiles in assets/ (JS,
 * CSS and their preprocessors; images and fonts served straight from assets/ don't
 * count: one a stylesheet uses comes with an edit to that stylesheet), and holding
 * every entry the theme's PHP asks Vite for. Skipped while the dev server runs.
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
