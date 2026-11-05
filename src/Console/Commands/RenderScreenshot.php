<?php

declare(strict_types=1);

namespace Gaffer\Console\Commands;

use Gaffer\Console\Command;
use Gaffer\Console\Render\BrowserPage;
use Gaffer\Console\Render\Renderer;
use Gaffer\Console\Render\Request;
use Gaffer\Console\Render\StagedTheme;
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
 * the site itself, as a new logged-out visitor's, or with --session as the visitor of an
 * ajax:call session (its cookies in both the render and the browser): what shops show of a
 * cart comes from cookies and ajax, so a render's --cart alone wouldn't show in the browser.
 * The browser is Playwright, the theme's own dev dependency (bin/screenshot.cjs), or a
 * Playwright server at PLAYWRIGHT_WS_ENDPOINT in the theme's .env when this machine can't
 * run Chromium.
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
        $this->addOption('full', null, InputOption::VALUE_NONE, 'The whole page, not only the first screen: scrolled through first (lazy images), in parts of --slice pixels');
        $this->addOption('slice', null, InputOption::VALUE_REQUIRED, 'With --full: the height of each part in pixels (0: one image)', '1200');
        $this->addOption('click', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Click this CSS selector before the screenshot (repeatable; with --fill/--hover in the order given)');
        $this->addOption('fill', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Type into a field first: selector=value, e.g. "input[name=qty]=3" (repeatable)');
        $this->addOption('hover', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Hover this CSS selector (repeatable; the pointer stays there for the screenshot)');
        $this->addOption('local-storage', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'A returning visitor\'s stored state: key=value in localStorage before the page loads (repeatable)');
        $this->addOption('session', null, InputOption::VALUE_REQUIRED, 'As the visitor of this ajax:call session: its cookies in the render and the browser (its cart, its drawer)');
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
        $slice = (string) $input->getOption('slice');
        if (!ctype_digit($slice) || ((int) $slice > 0 && (int) $slice < 200)) {
            return $fail('--slice takes a height in pixels (200 or more), or 0 for one image');
        }
        if (array_filter($widths, static fn(int $w): bool => $w < 200 || $w > 3840) !== []) {
            return $fail('--width takes widths in pixels between 200 and 3840, e.g. 390,1440');
        }
        try {
            $steps = self::steps($input instanceof ArgvInput ? $input->getRawTokens(true) : []);
            $entry = is_string($theme = $input->getOption('theme')) ? StagedTheme::entry($theme) : null;
            $session = is_string($session = $input->getOption('session')) ? $session : null;
            $cookies = $session !== null ? Request::session($session) : [];
            $storage = self::storage((array) $input->getOption('local-storage'));
        } catch (\InvalidArgumentException $e) {
            return $fail($e->getMessage());
        }
        $render = new Renderer(is_string($url = $input->getOption('url')) ? $url : null, static fn(string $p) => null, $session !== null ? ["--session={$session}"] : [], $entry)
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

        $out = Storage::private_dir('screenshots/' . BrowserPage::slug($path));
        array_map('unlink', glob("{$out}/*.png") ?: []);
        file_put_contents("{$out}/page.html", $render['html'] ?? '');
        $home = (array) parse_url(\home_url('/'));
        $options = [
            'url' => ($home['scheme'] ?? 'https') . '://' . ($home['host'] ?? 'localhost') . (isset($home['port']) ? ":{$home['port']}" : '') . $path,
            'html' => "{$out}/page.html",
            'http' => $render['http'],
            'widths' => $widths,
            'full' => (bool) $input->getOption('full'),
            'slice' => (int) $slice,
            'steps' => $steps,
            'cookies' => (object) $cookies,
            'storage' => (object) $storage,
            'out' => $out,
        ];

        /** @var array{error: string}|array{shots: list<array{width: int, file: string, files: list<string>, height: int}>, console: list<string>, failed: list<string>} $result */
        $result = BrowserPage::run('screenshot.cjs', $options);
        if (isset($result['error'])) {
            return $fail($result['error']);
        }
        $result = ['path' => $path, 'http' => $render['http'], ...$result];
        if ($json) {
            $output->writeln((string) json_encode($result, JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        foreach ($result['shots'] as $shot) {
            $output->writeln("{$shot['width']}px, page {$shot['height']}px high: " . implode(' ', $shot['files']));
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
     * --local-storage key=value pairs.
     *
     * @param array<mixed> $pairs
     * @return array<string, string>
     * @throws \InvalidArgumentException on one without "="
     */
    public static function storage(array $pairs): array
    {
        $storage = [];
        foreach ($pairs as $pair) {
            // At the first "=": keys rarely have one, values (JSON) may.
            $at = strpos((string) $pair, '=');
            if ($at === false || $at === 0) {
                throw new \InvalidArgumentException("--local-storage takes key=value, not \"{$pair}\"");
            }
            $storage[substr((string) $pair, 0, $at)] = substr((string) $pair, $at + 1);
        }

        return $storage;
    }

    /**
     * The --click, --hover and --fill options in the order given (fill a field, then click submit).
     *
     * @param list<string> $tokens the command line after the command name
     * @return list<array{click: string}|array{hover: string}|array{fill: string, value: string}>
     * @throws \InvalidArgumentException on a --fill without "="
     */
    public static function steps(array $tokens): array
    {
        $steps = [];
        foreach ($tokens as $i => $token) {
            if (!preg_match('/^--(click|hover|fill)(?:=(.*))?$/s', $token, $m)) {
                continue;
            }
            $value = $m[2] ?? (string) ($tokens[$i + 1] ?? '');
            if ($m[1] === 'click' || $m[1] === 'hover') {
                $steps[] = [$m[1] => $value];
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
}
