<?php

declare(strict_types=1);

namespace Gaffer\Console\Commands;

use Composer\Autoload\ClassLoader;
use Gaffer\Console\Command;
use Gaffer\Console\Render\Request;
use Gaffer\Paths;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Renders one URL the way a web request would, with Twig strict_variables on, and prints
 * the result as one JSON line after MARKER. A protocol between Gaffer versions: the live
 * theme's Gaffer runs a staged copy's `gaffer render:page`, so its options and result only
 * grow (`v` = PROTOCOL). One process per URL because WordPress handles one request per
 * process. With --html the result also holds the page. The render runs in bin/render.php
 * (Render\Request), which loads WordPress at its top level like a web request: this command
 * starts it, passes its output through, and stops it after --timeout seconds.
 */
#[AsCommand('render:page', 'Render one URL with strict variables (the render:* commands and doctor --wp run it)', hidden: true)]
final class RenderPage extends Command
{
    public const string MARKER = Request::MARKER;

    /** The result line's `v`: what a reader of this version understands. */
    public const int PROTOCOL = Request::PROTOCOL;

    #[\Override]
    protected function configure(): void
    {
        $this->addArgument('path', InputArgument::REQUIRED, 'URL path, e.g. /contact/');
        $this->addOption('html', null, InputOption::VALUE_NONE, 'Include the rendered page in the result');
        $this->addOption('user', null, InputOption::VALUE_REQUIRED, 'Render as this user (ID), e.g. the account pages or role prices');
        $this->addOption('session-file', null, InputOption::VALUE_REQUIRED, 'Render with the cookies of this ajax:call cookie jar (its path: the caller\'s theme\'s storage/sessions/{name}.json)');
        $this->addOption('cart', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'WooCommerce: put this product in the cart first, ID or ID:quantity (a variation by its own ID; repeatable)');
        $this->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'Stop the render after this many seconds (an error result)', '120');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = (string) $input->getArgument('path');
        $options = [
            'path' => $path,
            'html' => (bool) $input->getOption('html'),
            'user' => is_string($user = $input->getOption('user')) ? $user : null,
            'cart' => array_values(array_map('strval', (array) $input->getOption('cart'))),
            'session_file' => is_string($file = $input->getOption('session-file')) ? $file : null,
            'url' => is_string($url = $input->getOption('url')) ? $url : null,
        ];
        $timeout = max(1, (int) $input->getOption('timeout'));
        if (self::is_admin_path($path)) {
            self::error_line($path, 'admin pages and wp-login.php aren\'t rendered: a render loads WordPress\'s front-end template loader, which answers them with the 404 page');
            return self::FAILURE;
        }
        // The autoloader this process runs on, so the render uses the same Gaffer.
        $autoload = dirname((string) (new ReflectionClass(ClassLoader::class))->getFileName(), 2) . '/autoload.php';
        $command = [PHP_BINARY, '-d', 'memory_limit=' . ini_get('memory_limit'), dirname(__DIR__, 3) . '/bin/render.php', $autoload, Paths::base(), (string) json_encode($options, JSON_UNESCAPED_SLASHES)];

        // Output goes straight through (no pipes to fill up); this process only watches the clock.
        $process = proc_open($command, [1 => STDOUT, 2 => STDERR], $pipes);
        if ($process === false) {
            $output->writeln('<error>Could not start the render process.</error>');
            return self::FAILURE;
        }
        $deadline = microtime(true) + $timeout;
        while (($status = proc_get_status($process))['running']) {
            if (microtime(true) > $deadline) {
                proc_terminate($process);
                usleep(500_000);
                if (proc_get_status($process)['running']) {
                    proc_terminate($process, 9);
                }
                proc_close($process);
                // Killed before its shutdown function: this is the result line.
                self::error_line($path, "the render took longer than {$timeout} s and was stopped (a slow plugin, an external request that hangs?)");
                return self::FAILURE;
            }
            usleep(20_000);
        }
        proc_close($process);

        return $status['exitcode'] === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** Whether a path is WordPress's admin or login (/wp-admin/…, /wp-login.php), which a render can't show. */
    public static function is_admin_path(string $path): bool
    {
        $path = (string) parse_url($path, PHP_URL_PATH);

        return (bool) preg_match('~(^|/)(wp-admin(/|$)|wp-login\.php$)~', $path);
    }

    /** An error result line written by this process (no render ran, or it was killed). */
    private static function error_line(string $path, string $error): void
    {
        fwrite(STDOUT, "\n" . self::MARKER . json_encode([
            'v' => self::PROTOCOL, 'path' => $path, 'status' => 'error', 'error' => $error,
            'file' => null, 'line' => null, 'redirect' => null, 'http' => 0, 'notices' => [], 'bytes' => 0,
        ], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
    }

    /**
     * The result line from this command's output: the first one (a line that starts with
     * MARKER). Reads that one line only: plugins print after it on shutdown (Redis Object
     * Cache's HTML comment), and a page could contain the marker. Throws with what it couldn't
     * read when the process died first, the line isn't a result, or speaks a newer protocol.
     *
     * @return array{v: int, path: string, status: string, error: ?string, file: ?string, line: ?int, redirect: ?string, http: int, notices: list<string>, bytes: int, time_ms?: int, queries?: int, twig_ms?: int, search?: ?bool, html?: string}
     */
    public static function parse(string $stdout): array
    {
        $at = strpos("\n" . $stdout, "\n" . self::MARKER);
        if ($at === false) {
            $tail = trim(mb_substr($stdout, -500));
            if (str_contains($stdout, 'render:page') && str_contains($stdout, 'not defined')) {
                throw new RuntimeException("the theme's Gaffer has no render:page (older than this one's): update it there (composer update onstage2426/gaffer)");
            }
            throw new RuntimeException('the render process ended without a result line' . ($tail !== '' ? "; its last output: {$tail}" : ' and without output'));
        }

        $line = strtok(substr($stdout, $at + strlen(self::MARKER)), "\n");
        $result = json_decode((string) $line, true);
        if (!is_array($result) || !is_string($result['status'] ?? null)) {
            throw new RuntimeException('the render result line isn\'t readable: ' . mb_substr((string) $line, 0, 300));
        }
        $version = $result['v'] ?? null;
        if (!is_int($version) || $version > self::PROTOCOL) {
            throw new RuntimeException('the render result is protocol version ' . (is_scalar($version) ? (string) $version : 'unknown') . ', this Gaffer reads ' . self::PROTOCOL . ': the rendering theme runs another Gaffer (update this one)');
        }

        /** @var array{v: int, path: string, status: string, error: ?string, file: ?string, line: ?int, redirect: ?string, http: int, notices: list<string>, bytes: int, time_ms?: int, queries?: int, twig_ms?: int, search?: ?bool, html?: string} $result */
        return $result;
    }
}
