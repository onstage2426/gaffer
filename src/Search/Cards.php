<?php

declare(strict_types=1);

namespace Gaffer\Search;

use Gaffer\Console\Templates;
use Gaffer\Paths;
use Gaffer\Types\Post;
use Gaffer\View;
use Twig\Environment;

/**
 * Cards rendered into index documents, so the server's first page and the browser's later ones
 * show the same card from one template, and their version: the card template and every template
 * and file it includes, by content.
 *
 * @internal
 */
final class Cards
{
    /**
     * The card fields of one document: "card", plus "card_{variant}" per card variant. Empty
     * when the index has no card or the post is gone.
     *
     * @return array<string, string>
     */
    public static function fields(string $index, int $id): array
    {
        $definition = Indexes::get($index);
        $template = $definition['card'] ?? null;
        $post = $id > 0 ? Post::from($id) : null;
        if (!is_string($template) || $post === null) {
            return [];
        }
        $prepare = $definition['card_context'] ?? null;
        $context = is_callable($prepare) ? (array) $prepare($post) : ['post' => $post];
        $render = static fn(): string => View::fetch($template, $context);

        $fields = ['card' => self::on_site_scheme($render)];
        foreach ((array) ($definition['card_variants'] ?? []) as $variant => $wrap) {
            if (is_callable($wrap)) {
                $fields["card_{$variant}"] = self::on_site_scheme(static fn(): string => (string) $wrap($render));
            }
        }

        return $fields;
    }

    /**
     * A hash of the template and everything it includes. The list of files is kept with their
     * modification times, so most requests only stat them; a changed file means parsing again.
     */
    public static function version(string $template): string
    {
        $key = 'gaffer_search_card_' . md5($template);
        $cached = function_exists('get_transient') ? \get_transient($key) : false;
        if (is_array($cached) && is_array($cached['files'] ?? null) && is_string($cached['hash'] ?? null) && self::unchanged($cached['files'])) {
            return $cached['hash'];
        }

        $files = self::files($template);
        $hash = md5(implode("\0", array_map(static fn(string $file): string => $file . "\0" . md5_file($file), array_keys($files))));
        if (function_exists('set_transient')) {
            \set_transient($key, ['files' => $files, 'hash' => $hash], WEEK_IN_SECONDS);
        }

        return $hash;
    }

    /**
     * The files a template is made of: itself, what it includes (recursively) and the files it
     * reads with source(); a computed name counts every file its pattern matches.
     *
     * @return array<string, int> file => modification time, sorted
     */
    public static function files(string $template): array
    {
        $files = [];
        $queue = [$template];
        $seen = [];
        $parser = self::parser();
        while ($queue !== []) {
            $name = array_shift($queue);
            if (isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;
            $file = self::path($name);
            if ($file === null) {
                continue;
            }
            $files[$file] = (int) filemtime($file);
            if (!str_ends_with($name, '.twig')) {
                continue;
            }
            foreach (($node = Templates::parse($name, $parser)) !== null ? Templates::references($node) : [] as $reference) {
                if ($reference['template'] !== null) {
                    $queue[] = $reference['template'];
                } elseif ($reference['pattern'] !== null) {
                    foreach (glob(Paths::views() . '/' . $reference['pattern']) ?: [] as $match) {
                        $queue[] = substr($match, strlen(Paths::views()) + 1);
                    }
                }
            }
        }
        ksort($files);

        return $files;
    }

    /**
     * An environment to parse with, with the theme's loader and extensions: parsing with
     * View::env() itself would initialize its extensions, and this runs while Gaffer boots,
     * before others may add theirs (doctor's render adds a profiler).
     */
    private static function parser(): Environment
    {
        $main = View::env();
        $env = new Environment($main->getLoader());
        foreach ($main->getExtensions() as $class => $extension) {
            if (!$env->hasExtension($class)) {
                $env->addExtension($extension);
            }
        }

        return $env;
    }

    /** @param array<mixed> $files file => modification time */
    private static function unchanged(array $files): bool
    {
        foreach ($files as $file => $mtime) {
            if (!is_file((string) $file) || filemtime((string) $file) !== $mtime) {
                return false;
            }
        }

        return $files !== [];
    }

    private static function path(string $name): ?string
    {
        try {
            $path = View::env()->getLoader()->getSourceContext($name)->getPath();
        } catch (\Twig\Error\LoaderError) {
            return null;
        }

        return $path !== '' && is_file($path) ? $path : null;
    }

    /**
     * Renders as a request to the site would: from WP-CLI without --url, WordPress builds
     * http:// URLs for theme files on an HTTPS site, which a card would keep in the index.
     *
     * @param \Closure(): string $render
     */
    private static function on_site_scheme(\Closure $render): string
    {
        $https = $_SERVER['HTTPS'] ?? null;
        if (!\is_ssl() && str_starts_with(\home_url('/'), 'https://')) {
            $_SERVER['HTTPS'] = 'on';
        }
        try {
            return $render();
        } finally {
            if ($https === null) {
                unset($_SERVER['HTTPS']);
            } else {
                $_SERVER['HTTPS'] = $https;
            }
        }
    }
}
