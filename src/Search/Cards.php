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
     * The card fields of one document: "card" for the first of `cards`, "card_{view}" for the
     * others, and each of those again per card variant ("card_{variant}", "card_{view}_{variant}").
     * Empty when the index has no cards or the post is gone.
     *
     * @return array<string, string>
     */
    public static function fields(string $index, int $id): array
    {
        $definition = Indexes::get($index);
        $post = $id > 0 ? Post::from($id) : null;
        if ($definition === null || Indexes::templates($definition) === [] || $post === null) {
            return [];
        }
        $prepare = $definition['card_context'] ?? null;
        $context = is_callable($prepare) ? (array) $prepare($post) : ['post' => $post];

        $fields = [];
        foreach (Indexes::templates($definition) as $view => $template) {
            $render = static fn(): string => View::fetch($template, $context);
            $fields[Indexes::field($definition, $view, null)] = self::compact(self::on_site_scheme($render));
            foreach ((array) ($definition['card_variants'] ?? []) as $variant => $wrap) {
                if (is_callable($wrap)) {
                    $fields[Indexes::field($definition, $view, (string) $variant)] = self::compact(self::on_site_scheme(static fn(): string => (string) $wrap($render)));
                }
            }
        }

        return $fields;
    }

    /**
     * A stored card for the top of a page (the archive's first row, the LCP): its images'
     * loading="lazy" as loading="eager", and with $priority fetchpriority="high" on the first.
     */
    public static function eager(string $html, bool $priority = false): string
    {
        $html = (string) preg_replace('/\bloading=(["\'])lazy\1/i', 'loading=$1eager$1', $html);
        if ($priority && !preg_match('/\bfetchpriority=/i', $html)) {
            $html = (string) preg_replace('/<img\b/i', '<img fetchpriority="high"', $html, 1);
        }

        return $html;
    }

    /**
     * A card without the template's indentation and blank lines: every run of whitespace with a
     * line break becomes one line break, which a browser shows the same (about a tenth of an
     * index and of every response). Inside <pre>, <textarea>, <script> and <style> whitespace is
     * kept as it is.
     */
    public static function compact(string $html): string
    {
        $parts = preg_split('#(<(pre|textarea|script|style)\b.*?</\2\s*>)#si', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return $html;
        }
        $out = '';
        // [text, block, block's tag name, text, …]
        for ($i = 0, $n = count($parts); $i < $n; $i += 3) {
            $out .= (string) preg_replace('/[ \t\r]*\n\s*/', "\n", $parts[$i]) . ($parts[$i + 1] ?? '');
        }

        return trim($out);
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
        $hash = md5(implode("\0", array_map(static fn(string $file): string => $file . "\0" . md5_file(Paths::base($file)), array_keys($files))));
        if (function_exists('set_transient')) {
            \set_transient($key, ['files' => $files, 'hash' => $hash], WEEK_IN_SECONDS);
        }

        return $hash;
    }

    /**
     * A hash of the theme files that define these callables ([class, method]), by content and
     * relative to the theme: a change to a card method rebuilds the index like a template does.
     *
     * @param list<mixed> $callables
     */
    public static function code_version(array $callables): string
    {
        $files = [];
        foreach ($callables as $callable) {
            if (!is_array($callable) || !is_string($callable[0] ?? null) || !is_string($callable[1] ?? null) || !method_exists($callable[0], $callable[1])) {
                continue;
            }
            $file = (string) realpath((string) new \ReflectionMethod($callable[0], $callable[1])->getFileName());
            if (str_starts_with($file, self::root() . '/')) {
                $files[substr($file, strlen(self::root()) + 1)] = md5_file($file);
            }
        }
        ksort($files);

        return md5((string) json_encode($files));
    }

    /**
     * The files a template is made of: itself, what it includes (recursively) and the files it
     * reads with source(); a computed name counts every file its pattern matches. Relative to the
     * theme: the same theme is often seen through two paths (a web server in a container, the
     * CLI on the host), which must agree on the version.
     *
     * @return array<string, int> file (relative to the theme) => modification time, sorted
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
            $files[substr($file, strlen(self::root()) + 1)] = (int) filemtime($file);
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

    /** @param array<mixed> $files file (relative to the theme) => modification time */
    private static function unchanged(array $files): bool
    {
        foreach ($files as $file => $mtime) {
            $path = Paths::base((string) $file);
            if (!is_file($path) || filemtime($path) !== $mtime) {
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

        // Only files inside the theme count (and can be named relative to it).
        $path = $path !== '' ? (string) realpath($path) : '';

        return $path !== '' && str_starts_with($path, self::root() . '/') ? $path : null;
    }

    /** The theme folder as the filesystem has it (realpath): template paths resolve to it. */
    private static function root(): string
    {
        return (string) (realpath(Paths::base()) ?: Paths::base());
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
