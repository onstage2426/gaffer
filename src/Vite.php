<?php

declare(strict_types=1);

namespace Gaffer;

/**
 * The theme's Vite assets: the build in public/ (via its manifest), or the dev
 * server's while it runs (public/.vite/hotfile), for administrators only.
 * Every asset is a Vite entry ("assets/js/app.js", "assets/css/app.css").
 */
final class Vite
{
    /** @var array<string, array<string, mixed>>|null */
    private static ?array $manifest = null;
    private static string $dev_url;
    private static ?bool $dev_mode = null;

    /**
     * The URL of an entry: on a <link> or in JS, and for the editor style (`mce_css`).
     */
    public static function url(string $asset): string
    {
        if (self::is_dev_asset()) {
            return self::dev_url($asset);
        }

        return self::build_url($asset);
    }

    private static function is_dev_asset(): bool
    {
        return self::is_dev_mode() && \current_user_can("manage_options");
    }

    private static function build_url(string $asset): string
    {
        $manifest = self::manifest();

        if (isset($manifest[$asset])) {
            return self::public_url() . "/" . $manifest[$asset]["file"];
        }

        return "";
    }

    private static function dev_url(string $asset): string
    {
        self::$dev_url ??= rtrim(file_get_contents(self::hotfile()));
        return self::$dev_url . "/" . $asset;
    }

    private static function path(string $asset): ?string
    {
        $manifest = self::manifest();

        if (isset($manifest[$asset])) {
            return Paths::public() . "/" . $manifest[$asset]["file"];
        }

        return null;
    }

    /**
     * @internal For doctor.
     * @return array<string, array<string, mixed>>
     */
    public static function manifest(): array
    {
        if (self::$manifest === null) {
            $public_path = Paths::public();
            $file_path   = "$public_path/.vite/manifest.json";

            $contents     = file_exists($file_path) ? file_get_contents($file_path) : null;
            self::$manifest = $contents !== null && json_validate($contents)
                ? json_decode($contents, true)
                : [];
        }

        return self::$manifest;
    }

    /** @internal For the admin bar and doctor. */
    public static function is_dev_mode(): bool
    {
        return self::$dev_mode ??= file_exists(self::hotfile());
    }

    private static function hotfile(): string
    {
        return Paths::public() . "/.vite/hotfile";
    }

    private static function public_url(): string
    {
        $public_path = Paths::public();
        $theme_dir   = \get_template_directory();
        $theme_uri   = \get_template_directory_uri();

        return $theme_uri . substr($public_path, strlen($theme_dir));
    }

    /**
     * The tags for these entries: <link> for CSS (also the CSS a JS entry
     * imports, in the build), <script type="module"> for JS, preload for fonts.
     *
     * @param list<string> $assets
     */
    public static function tags(array $assets): void
    {
        $is_dev     = self::is_dev_asset();
        $manifest   = self::manifest();
        $public_path = Paths::public();

        foreach ($assets as $asset) {
            $file_url = self::url($asset);
            if ($file_url === "") {
                continue;
            }

            $file_ext  = pathinfo((string) $asset, PATHINFO_EXTENSION);
            $abs_path  = $is_dev ? null : self::path($asset);
            $versioned = $abs_path ? self::versioned($file_url, $abs_path) : $file_url;

            if ($file_ext === "css") {
                echo <<<HTML
                <link rel="preload" as="style" href="{$versioned}" />
                <link rel="stylesheet" href="{$versioned}" />
                HTML;
            } elseif ($file_ext === "js") {
                if (!$is_dev && !empty($manifest[$asset]["css"])) {
                    foreach ($manifest[$asset]["css"] as $css_file) {
                        $css_url       = self::public_url() . "/$css_file";
                        $css_versioned = self::versioned($css_url, "$public_path/$css_file");
                        echo <<<HTML
                        <link rel="preload" as="style" href="{$css_versioned}" />
                        <link rel="stylesheet" href="{$css_versioned}" />
                        HTML;
                    }
                }

                echo <<<HTML
                <link rel="modulepreload" href="{$versioned}" />
                <script type="module" src="{$versioned}"></script>
                HTML;
            } elseif ($file_ext === "woff2") {
                echo <<<HTML
                <link rel="preload" as="font" type="font/woff2" href="{$versioned}" crossorigin />
                HTML;
            }
        }
    }

    private static function versioned(string $url, string $abs_path): string
    {
        $ver = file_exists($abs_path) ? filemtime($abs_path) : null;
        return $ver !== null ? "{$url}?ver={$ver}" : $url;
    }
}
