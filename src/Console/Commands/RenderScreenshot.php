<?php

declare(strict_types=1);

namespace Gaffer\Console\Commands;

use Gaffer\Console\Command;
use Gaffer\Console\Render\BrowserPage;
use Gaffer\Console\Render\Visitor;
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
 * A design (a URL, or an HTML file) is screenshotted as it is, to look at it next to the site.
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
        $this->addArgument('path', InputArgument::REQUIRED, 'URL path, e.g. /contact/ or "/winkel/?s=shirt"; or a design: a URL (https://…, DESIGN_COOKIE from .env) or an HTML file');
        $this->addOption('width', null, InputOption::VALUE_REQUIRED, 'Viewport widths, comma-separated', '390,1440');
        $this->addOption('full', null, InputOption::VALUE_NONE, 'The whole page, not only the first screen: scrolled through first (lazy images), in parts of --slice pixels. The window is as tall as the page then: fixed elements sit at its bottom, and one moved off-screen (translate) can show');
        $this->addOption('clip', null, InputOption::VALUE_REQUIRED, 'Only this element (CSS selector, the first visible match), whole: one block or section instead of the page');
        $this->addOption('slice', null, InputOption::VALUE_REQUIRED, 'With --full: the height of each part in pixels (0: one image)', '1200');
        $this->addOption('click', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Click this CSS selector before the screenshot (repeatable; with --fill/--hover in the order given)');
        $this->addOption('fill', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Type into a field first: selector=value, split at the first "=" outside [...] and quotes, e.g. "input[name=qty]=3" (repeatable)');
        $this->addOption('select', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Choose an option of a <select> first: selector=value (the option\'s value or its text), split like --fill (repeatable)');
        $this->addOption('hover', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Hover this CSS selector (repeatable; the pointer stays there for the screenshot)');
        $this->addOption('scroll', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Scroll down to this many pixels, or a CSS selector to the top of the window (repeatable, in order with the other steps): scroll-triggered UI');
        $this->addOption('local-storage', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'A returning visitor\'s stored state: key=value in localStorage before the page loads (repeatable)');
        $this->addOption('steps', null, InputOption::VALUE_REQUIRED, 'All the steps as JSON instead of --click/--hover/--fill/--select/--scroll (for tools): [{"click": "…"}, {"hover": "…"}, {"fill": "…", "value": "…"}, {"select": "…", "value": "…"}, {"scroll": "320"}]');
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

        $slice = (string) $input->getOption('slice');
        if (!ctype_digit($slice) || ((int) $slice > 0 && (int) $slice < 200)) {
            return $fail('--slice takes a height in pixels (200 or more), or 0 for one image');
        }
        try {
            $widths = BrowserPage::widths((string) $input->getOption('width'));
            $steps = self::steps($input instanceof ArgvInput ? $input->getRawTokens(true) : []);
            if (is_string($json_steps = $input->getOption('steps'))) {
                if ($steps !== []) {
                    throw new \InvalidArgumentException('--steps takes all the steps: no --click, --hover, --fill, --select or --scroll with it');
                }
                $steps = self::json_steps($json_steps);
            }
            $entry = is_string($theme = $input->getOption('theme')) ? StagedTheme::entry($theme) : null;
            $visitor = Visitor::from(null, [], $input->getOption('session'));
            $storage = self::storage((array) $input->getOption('local-storage'));
        } catch (\InvalidArgumentException $e) {
            return $fail($e->getMessage());
        }
        $clip = is_string($clip = $input->getOption('clip')) && $clip !== '' ? $clip : null;
        if ($clip !== null && $input->getOption('full')) {
            return $fail('--clip takes the element whole: no --full with it');
        }
        // A site path is rendered (its session's cookies go to the browser); a design URL or file is opened as it is.
        $target = BrowserPage::target($path, is_string($url = $input->getOption('url')) ? $url : null, $visitor, $entry);
        if (is_string($target)) {
            return $fail($target);
        }

        $out = is_string($target['out'] ?? null) ? $target['out'] : Storage::theme_dir('screenshots/' . BrowserPage::slug($path));
        $options = [
            ...$target,
            'widths' => $widths,
            'full' => (bool) $input->getOption('full'),
            'slice' => (int) $slice,
            'clip' => $clip,
            'steps' => $steps,
            'storage' => (object) $storage,
            'out' => $out,
        ];

        /** @var array{error: string}|array{shots: list<array{width: int, file: string, files: list<string>, height: int, truncated?: int}>, console: list<string>, failed: list<string>} $result */
        $result = BrowserPage::run('screenshot.cjs', $options);
        if (isset($result['error'])) {
            return $fail($result['error']);
        }
        $result = ['path' => $path, 'http' => $target['http'] ?? null, ...$result];
        if ($json) {
            $output->writeln((string) json_encode($result, JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        foreach ($result['shots'] as $shot) {
            $output->writeln("{$shot['width']}px, " . ($clip !== null ? 'element' : 'page') . " {$shot['height']}px high" . (isset($shot['truncated']) ? " (only the first {$shot['truncated']}px)" : '') . ': ' . implode(' ', $shot['files']));
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
     * The --click, --hover, --fill, --select and --scroll options in the order given (fill a field, then click submit).
     *
     * @param list<string> $tokens the command line after the command name
     * @return list<array{click: string}|array{hover: string}|array{scroll: string}|array{fill: string, value: string}|array{select: string, value: string}>
     * @throws \InvalidArgumentException on a --fill without "="
     */
    public static function steps(array $tokens): array
    {
        $steps = [];
        foreach ($tokens as $i => $token) {
            if (!preg_match('/^--(click|hover|fill|select|scroll)(?:=(.*))?$/s', $token, $m)) {
                continue;
            }
            $value = $m[2] ?? (string) ($tokens[$i + 1] ?? '');
            if ($m[1] === 'click' || $m[1] === 'hover' || $m[1] === 'scroll') {
                $steps[] = [$m[1] => $value];
                continue;
            }
            $step = self::fill($value, "--{$m[1]}");
            $steps[] = $m[1] === 'select' ? ['select' => $step['fill'], 'value' => $step['value']] : $step;
        }

        return $steps;
    }

    /**
     * A --fill's selector and value: split at the first "=" outside [...] and quotes, so an
     * attribute selector keeps its own ("input[name=\"a=b\"]=3") and the value may have one.
     *
     * @return array{fill: string, value: string}
     * @throws \InvalidArgumentException when there is no such "=" or no selector before it
     */
    public static function fill(string $option, string $name = '--fill'): array
    {
        $depth = 0;
        $quote = null;
        for ($i = 0, $n = strlen($option); $i < $n; $i++) {
            $char = $option[$i];
            if ($char === '\\') {
                $i++; // an escaped character is never a separator
                continue;
            }
            if ($quote !== null) {
                $quote = $char === $quote ? null : $quote;
                continue;
            }
            match (true) {
                $char === '"' || $char === "'" => $quote = $char,
                $char === '[' => $depth++,
                $char === ']' => $depth = max(0, $depth - 1),
                default => null,
            };
            if ($char === '=' && $depth === 0 && $i > 0) {
                return ['fill' => substr($option, 0, $i), 'value' => substr($option, $i + 1)];
            }
        }

        throw new \InvalidArgumentException("{$name} takes selector=value, not \"{$option}\"");
    }

    /**
     * --steps: the steps as JSON, as the gaffer-screenshot MCP tool sends them.
     *
     * @return list<array{click: string}|array{hover: string}|array{scroll: string}|array{fill: string, value: string}|array{select: string, value: string}>
     * @throws \InvalidArgumentException on anything else
     */
    public static function json_steps(string $json): array
    {
        $data = json_decode($json, true);
        if (!is_array($data) || !array_is_list($data)) {
            throw new \InvalidArgumentException('--steps takes a JSON list of steps, e.g. [{"click": ".menu-toggle"}]');
        }
        $steps = [];
        foreach ($data as $step) {
            $steps[] = match (true) {
                is_array($step) && is_string($step['click'] ?? null) => ['click' => $step['click']],
                is_array($step) && is_string($step['hover'] ?? null) => ['hover' => $step['hover']],
                is_array($step) && is_string($step['scroll'] ?? null) => ['scroll' => $step['scroll']],
                is_array($step) && is_string($step['select'] ?? null) && is_string($step['value'] ?? null) => ['select' => $step['select'], 'value' => $step['value']],
                is_array($step) && is_string($step['fill'] ?? null) && is_string($step['value'] ?? '') => ['fill' => $step['fill'], 'value' => (string) ($step['value'] ?? '')],
                default => throw new \InvalidArgumentException('A step is {"click": selector}, {"hover": selector}, {"scroll": pixels or selector}, {"fill": selector, "value": text} or {"select": selector, "value": option}, not ' . json_encode($step)),
            };
        }

        return $steps;
    }
}
