<?php

declare(strict_types=1);

namespace Gaffer\Console\Commands;

use Gaffer\Console\Command;
use Gaffer\Console\WordPress;
use Gaffer\Paths;
use Gaffer\View;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use Twig\Error\Error as TwigError;

/**
 * Renders one URL the way a web request would, with Twig strict_variables on,
 * and prints the result as one JSON line. Used by `doctor --wp`, one process per
 * URL because WordPress can only handle one request per process.
 */
#[AsCommand('doctor:render', 'Render one URL with strict variables (used by doctor --wp)', hidden: true)]
final class DoctorRender extends Command
{
    public const string MARKER = 'GAFFER_RENDER_RESULT ';

    /** @var array{path: string, status: string, error: ?string, file: ?string, line: ?int, redirect: ?string, notices: list<string>, bytes: int} */
    private array $result;

    #[\Override]
    protected function configure(): void
    {
        $this->addArgument('path', InputArgument::REQUIRED, 'URL path, e.g. /contact/');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = (string) $input->getArgument('path');
        $this->result = ['path' => $path, 'status' => 'ok', 'error' => null, 'file' => null, 'line' => null, 'redirect' => null, 'notices' => [], 'bytes' => 0];

        // Templates may redirect and exit, or fatal; report from shutdown either way.
        register_shutdown_function(function (): void {
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

        add_filter('wp_redirect', function (string $location): string {
            $this->result['status'] = 'redirect';
            $this->result['redirect'] = $location;
            return $location;
        }, PHP_INT_MAX);

        // Discard the page itself, only count it (WordPress flushes buffers on shutdown).
        ob_start(function (string $buffer): string {
            $this->result['bytes'] += strlen($buffer);
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

    private function fail(string $message, string $file, int $line): void
    {
        $this->result['status'] = 'error';
        $this->result['error'] = $message;
        $this->result['file'] = $file;
        $this->result['line'] = $line;
    }
}
