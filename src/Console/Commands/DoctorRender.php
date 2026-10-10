<?php

declare(strict_types=1);

namespace Gaffer\Console\Commands;

use Gaffer\Console\Command;
use Gaffer\Console\WordPress;
use Gaffer\Paths;
use Gaffer\View;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use Twig\Error\Error as TwigError;

/**
 * Renders one URL the way a web request would, with Twig strict_variables on,
 * and prints the result as one JSON line. Used by `doctor --wp`, one process per
 * URL because WordPress can only handle one request per process. With --html
 * the result also holds the page (the `gaffer/render` MCP tool).
 */
#[AsCommand('doctor:render', 'Render one URL with strict variables (used by doctor --wp)', hidden: true)]
final class DoctorRender extends Command
{
    public const string MARKER = 'GAFFER_RENDER_RESULT ';

    /** @var array{path: string, status: string, error: ?string, file: ?string, line: ?int, redirect: ?string, http: int, notices: list<string>, bytes: int, html?: string} */
    private array $result;

    /** Output buffer level below the page's buffer. */
    private ?int $buffer_level = null;

    #[\Override]
    protected function configure(): void
    {
        $this->addArgument('path', InputArgument::REQUIRED, 'URL path, e.g. /contact/');
        $this->addOption('html', null, InputOption::VALUE_NONE, 'Include the rendered page in the result');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = (string) $input->getArgument('path');
        $this->result = ['path' => $path, 'status' => 'ok', 'error' => null, 'file' => null, 'line' => null, 'redirect' => null, 'http' => 200, 'notices' => [], 'bytes' => 0];
        if ($input->getOption('html')) {
            $this->result['html'] = '';
        }

        // Templates may redirect and exit, or fatal; report from shutdown either way.
        register_shutdown_function(function (): void {
            // Runs before WordPress's own shutdown flush: empty the page into the result first.
            while ($this->buffer_level !== null && ob_get_level() > $this->buffer_level) {
                ob_end_flush();
            }
            $error = error_get_last();
            if ($this->result['status'] === 'ok' && $error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                $this->fail(strtok($error['message'], "\n") ?: $error['message'], $error['file'], $error['line']);
            }
            fwrite(STDOUT, "\n" . self::MARKER . json_encode($this->result, JSON_UNESCAPED_SLASHES) . "\n");
        });

        // PHP warnings/notices raised in theme files (not plugins or core).
        set_error_handler(function (int $no, string $message, string $file, int $line): bool {
            if (str_starts_with($file, Paths::base() . '/') && !str_contains($file, '/vendor/')) {
                $this->result['notices'][] = substr($file, strlen(Paths::base()) + 1) . ":{$line} {$message}";
            }
            return false;
        });

        $_SERVER['REQUEST_URI'] = $path;
        define('WP_USE_THEMES', true);
        WordPress::load($input->getOption('url'));
        View::env()->enableStrictVariables();

        // The HTTP status WordPress sends (404 for a missing page, 3xx for a redirect).
        add_filter('status_header', function (string $header, int $code): string {
            $this->result['http'] = $code;
            return $header;
        }, PHP_INT_MAX, 2);

        add_filter('wp_redirect', function (string $location, int $status): string {
            $this->result['http'] = $status;
            $this->result['status'] = 'redirect';
            $this->result['redirect'] = $location;
            return $location;
        }, PHP_INT_MAX, 2);

        // Keep the page out of stdout, only count it (and keep it with --html). Flushed in the shutdown function above.
        $this->buffer_level = ob_get_level();
        ob_start(function (string $buffer): string {
            $this->result['bytes'] += strlen($buffer);
            if (isset($this->result['html'])) {
                $this->result['html'] .= $buffer;
            }
            return '';
        });

        try {
            wp();
            require ABSPATH . 'wp-includes/template-loader.php';
        } catch (Throwable $e) {
            $source = $e instanceof TwigError ? $e->getSourceContext()?->getPath() : null;
            $this->fail(
                $e::class . ': ' . ($e instanceof TwigError ? $e->getRawMessage() : $e->getMessage()),
                $source ?: $e->getFile(),
                $e instanceof TwigError && $e->getTemplateLine() > 0 ? $e->getTemplateLine() : $e->getLine(),
            );
        }

        return self::SUCCESS;
    }

    /**
     * The result line from this command's output. Reads that one line only: plugins
     * print after it on shutdown (Redis Object Cache's HTML comment). Throws with what
     * it couldn't read when the process died first or the line isn't a result.
     *
     * @return array{path: string, status: string, error: ?string, file: ?string, line: ?int, redirect: ?string, http: int, notices: list<string>, bytes: int, html?: string}
     */
    public static function parse(string $stdout): array
    {
        $at = strrpos($stdout, self::MARKER);
        if ($at === false) {
            $tail = trim(mb_substr($stdout, -500));
            throw new RuntimeException('the render process ended without a result line' . ($tail !== '' ? "; its last output: {$tail}" : ' and without output'));
        }

        $line = strtok(substr($stdout, $at + strlen(self::MARKER)), "\n");
        $result = json_decode((string) $line, true);
        if (!is_array($result) || !is_string($result['status'] ?? null)) {
            throw new RuntimeException('the render result line isn\'t readable: ' . mb_substr((string) $line, 0, 300));
        }

        return $result;
    }

    private function fail(string $message, string $file, int $line): void
    {
        $this->result['status'] = 'error';
        $this->result['error'] = $message;
        $this->result['file'] = $file;
        $this->result['line'] = $line;
    }
}
