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
 * The Vite build: present, not older than the sources in assets/, and holding
 * every entry the theme's PHP asks Vite for. Skipped while the dev server runs.
 */
final class AssetsCheck implements Check
{
    private const string CALL = '/Vite::(?:tags|url)\(\s*(\[[^\]]*\]|([\'"])[^\'"]+\2)/';

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
            $report->warning('assets', 'The build is older than its sources (' . substr($newest[1], strlen(Paths::base()) + 1) . ' changed since)', null, null,
                'Run `npm run build` (and deploy public/).');
        }

        $manifest = Vite::manifest();
        foreach (ThemeFiles::find(['php']) as $file) {
            foreach (file($file) ?: [] as $i => $line) {
                if (!preg_match_all(self::CALL, $line, $calls)) {
                    continue;
                }
                foreach ($calls[1] as $arguments) {
                    preg_match_all('/([\'"])([^\'"]+)\1/', $arguments, $assets);
                    foreach ($assets[2] as $asset) {
                        if (!isset($manifest[$asset])) {
                            $report->error('assets', "{$asset} is not a Vite entry (not in the manifest)", $file, $i + 1,
                                'Add it to the entries in vite.config.js and build, or fix the path.');
                        }
                    }
                }
            }
        }
    }

    /**
     * The most recently changed file under $dir: [mtime, path].
     *
     * @return array{int, string}|null
     */
    private static function newest(string $dir): ?array
    {
        $newest = null;
        /** @var iterable<SplFileInfo> $files */
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->isFile() && ($newest === null || $file->getMTime() > $newest[0])) {
                $newest = [$file->getMTime(), $file->getPathname()];
            }
        }

        return $newest;
    }
}
