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
 * Renders one URL the way a web request would, with Twig strict_variables on,
 * and prints the result as one JSON line. Used by `doctor --wp`, one process per
 * URL because WordPress can only handle one request per process. With --html
 * the result also holds the page (the `gaffer/render` MCP tool). The render runs
 * in bin/render.php (Render\Request), which loads WordPress at its top level like
 * a web request: this command only starts it and passes its output through.
 */
#[AsCommand('doctor:render', 'Render one URL with strict variables (used by doctor --wp)', hidden: true)]
final class DoctorRender extends Command
{
    public const string MARKER = Request::MARKER;

    #[\Override]
    protected function configure(): void
    {
        $this->addArgument('path', InputArgument::REQUIRED, 'URL path, e.g. /contact/');
        $this->addOption('html', null, InputOption::VALUE_NONE, 'Include the rendered page in the result');
        $this->addOption('user', null, InputOption::VALUE_REQUIRED, 'Render as this user (ID), e.g. the account pages or role prices');
        $this->addOption('cart', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'WooCommerce: put this product in the cart first, ID or ID:quantity (a variation by its own ID; repeatable)');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $options = [
            'path' => (string) $input->getArgument('path'),
            'html' => (bool) $input->getOption('html'),
            'user' => is_string($user = $input->getOption('user')) ? $user : null,
            'cart' => array_values(array_map('strval', (array) $input->getOption('cart'))),
            'url' => is_string($url = $input->getOption('url')) ? $url : null,
        ];
        // The autoloader this process runs on, so the render uses the same Gaffer.
        $autoload = dirname((string) (new ReflectionClass(ClassLoader::class))->getFileName(), 2) . '/autoload.php';
        $command = [PHP_BINARY, dirname(__DIR__, 3) . '/bin/render.php', $autoload, Paths::base(), (string) json_encode($options, JSON_UNESCAPED_SLASHES)];

        $process = proc_open($command, [1 => STDOUT, 2 => STDERR], $pipes);
        if ($process === false) {
            $output->writeln('<error>Could not start the render process.</error>');
            return self::FAILURE;
        }

        return proc_close($process) === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * The result line from this command's output. Reads that one line only: plugins
     * print after it on shutdown (Redis Object Cache's HTML comment). Throws with what
     * it couldn't read when the process died first or the line isn't a result.
     *
     * @return array{path: string, status: string, error: ?string, file: ?string, line: ?int, redirect: ?string, http: int, notices: list<string>, bytes: int, time_ms?: int, queries?: int, twig_ms?: int, html?: string}
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

}
