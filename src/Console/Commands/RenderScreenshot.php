<?php

declare(strict_types=1);

namespace Gaffer\Console\Commands;

use Gaffer\Console\Command;
use Gaffer\Console\Env;
use Gaffer\Console\Render\Renderer;
use Gaffer\Console\Render\StagedTheme;
use Gaffer\Paths;
use Gaffer\Storage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Screenshots of one page at several widths, with the console errors and failed requests
 * the browser saw. The page is rendered the way doctor does (--theme the same) and handed
 * to a headless Chromium as the page's answer; styles, scripts, images and ajax come from
 * the site itself, as a new logged-out visitor's: no --user or --cart, since what shops
 * show of a cart or a login comes from cookies and ajax, which a render can't give the
 * browser. The browser is Playwright, the theme's own dev dependency (bin/screenshot.cjs),
 * or a Playwright server at PLAYWRIGHT_WS_ENDPOINT in the theme's .env when this machine
 * can't run Chromium.
 */
#[AsCommand('render:screenshot', 'Screenshot a page at several widths, with its console errors and failed requests')]
final class RenderScreenshot extends Command
{
    protected bool $wordpress = true;

    #[\Override]
    protected function configure(): void
    {
        $this->addArgument('path', InputArgument::REQUIRED, 'URL path, e.g. /contact/ or "/winkel/?s=shirt"');
        $this->addOption('width', null, InputOption::VALUE_REQUIRED, 'Viewport widths, comma-separated', '390,1440');
        $this->addOption('full', null, InputOption::VALUE_NONE, 'The whole page, not only the first screen');
        $this->addOption('click', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Click this CSS selector before the screenshot (repeatable; with --fill in the order given)');
        $this->addOption('fill', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Type into a field first: selector=value, e.g. "input[name=qty]=3" (repeatable)');
        $this->addOption('theme', null, InputOption::VALUE_REQUIRED, 'Render a full copy of the theme next to it (e.g. blueprint-stage), with its own assets');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Print the result as JSON (the gaffer-screenshot MCP tool)');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = (string) $input->getArgument('path');
        $json = (bool) $input->getOption('json');
        $fail = static function (string $error) use ($output, $json): int {
            $output->writeln($json ? (string) json_encode(['error' => $error], JSON_UNESCAPED_SLASHES) : "<error>{$error}</error>");
            return self::FAILURE;
        };

        if (!str_starts_with($path, '/')) {
            return $fail("\"{$path}\" is not a URL path (it starts with /)");
        }
        $widths = array_map('intval', explode(',', (string) $input->getOption('width')));
        if (array_filter($widths, static fn(int $w): bool => $w < 200 || $w > 3840) !== []) {
            return $fail('--width takes widths in pixels between 200 and 3840, e.g. 390,1440');
        }
        try {
            $steps = self::steps($input instanceof ArgvInput ? $input->getRawTokens(true) : []);
            $entry = is_string($theme = $input->getOption('theme')) ? StagedTheme::entry($theme) : null;
        } catch (\InvalidArgumentException $e) {
            return $fail($e->getMessage());
        }
        $render = new Renderer(is_string($url = $input->getOption('url')) ? $url : null, static fn(string $p) => null, [], $entry)
            ->render([$path], true, as_live: false)[$path];
        if (is_string($render)) {
            return $fail("The render failed: {$render}");
        }
        if ($render['status'] === 'error') {
            return $fail("The render failed: {$render['error']} ({$render['file']}:{$render['line']})");
        }
        if ($render['status'] === 'redirect') {
            return $fail("{$path} redirects ({$render['http']}) to {$render['redirect']}: screenshot that instead");
        }

        $out = Storage::private_dir('screenshots/' . self::slug($path));
        array_map('unlink', glob("{$out}/*.png") ?: []);
        file_put_contents("{$out}/page.html", $render['html'] ?? '');
        $home = (array) parse_url(\home_url('/'));
        $options = [
            'theme' => Paths::base(),
            'url' => ($home['scheme'] ?? 'https') . '://' . ($home['host'] ?? 'localhost') . (isset($home['port']) ? ":{$home['port']}" : '') . $path,
            'html' => "{$out}/page.html",
            'http' => $render['http'],
            'widths' => $widths,
            'full' => (bool) $input->getOption('full'),
            'steps' => $steps,
            'out' => $out,
            'endpoint' => Env::get('PLAYWRIGHT_WS_ENDPOINT'),
        ];

        $result = self::browser($options);
        if (isset($result['error'])) {
            return $fail($result['error']);
        }
        $result = ['path' => $path, 'http' => $render['http'], ...$result];
        if ($json) {
            $output->writeln((string) json_encode($result, JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        foreach ($result['shots'] as $shot) {
            $output->writeln("{$shot['width']}px: {$shot['file']}");
        }
        foreach (['console' => 'Console errors', 'failed' => 'Failed requests'] as $key => $label) {
            if ($result[$key] !== []) {
                $output->writeln("<comment>{$label}:</comment>");
                foreach ($result[$key] as $line) {
                    $output->writeln("  {$line}");
                }
            }
        }

        return self::SUCCESS;
    }

    /**
     * The --click and --fill options in the order given (fill a field, then click submit).
     *
     * @param list<string> $tokens the command line after the command name
     * @return list<array{click: string}|array{fill: string, value: string}>
     * @throws \InvalidArgumentException on a --fill without "="
     */
    public static function steps(array $tokens): array
    {
        $steps = [];
        foreach ($tokens as $i => $token) {
            if (!preg_match('/^--(click|fill)(?:=(.*))?$/s', $token, $m)) {
                continue;
            }
            $value = $m[2] ?? (string) ($tokens[$i + 1] ?? '');
            if ($m[1] === 'click') {
                $steps[] = ['click' => $value];
                continue;
            }
            // At the last "=": selectors may have one ([name=qty]), values rarely do.
            $at = strrpos($value, '=');
            if ($at === false) {
                throw new \InvalidArgumentException("--fill takes selector=value, not \"{$value}\"");
            }
            $steps[] = ['fill' => substr($value, 0, $at), 'value' => substr($value, $at + 1)];
        }

        return $steps;
    }

    /** The folder name for a path's screenshots: "/" is "home", "/winkel/?s=x" "winkel-s-x". */
    public static function slug(string $path): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($path)), '-') ?: 'home';
    }

    /**
     * @param array<string, mixed> $options
     * @return array{error: string}|array{shots: list<array{width: int, file: string}>, console: list<string>, failed: list<string>}
     */
    private static function browser(array $options): array
    {
        $command = ['node', dirname(__DIR__, 3) . '/bin/screenshot.cjs', (string) json_encode($options, JSON_UNESCAPED_SLASHES)];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, Paths::base());
        if ($process === false) {
            return ['error' => 'Could not start Node (`node`), which runs the browser.'];
        }
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $lines = array_filter(explode("\n", trim($stdout)));
        $result = json_decode((string) end($lines), true);
        if (!is_array($result)) {
            $tail = trim(mb_substr($stderr !== '' ? $stderr : $stdout, -500));
            return ['error' => 'The browser script gave no result' . ($tail !== '' ? ": {$tail}" : ' (is Node, `node`, installed?)')];
        }

        /** @var array{error: string}|array{shots: list<array{width: int, file: string}>, console: list<string>, failed: list<string>} $result */
        return $result;
    }
}
