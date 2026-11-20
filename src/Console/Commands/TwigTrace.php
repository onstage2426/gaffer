<?php

declare(strict_types=1);

namespace Gaffer\Console\Commands;

use Gaffer\Console\Command;
use Gaffer\Console\Templates;
use Gaffer\Console\ThemeFiles;
use Gaffer\Gaffer;
use Gaffer\Paths;
use Gaffer\Search\Indexes;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Where a template is used and what it uses: the PHP that renders it (an ajax
 * action, a block, a template file; computed names by their pattern), the
 * templates that include it, search cards, what it includes, and the variables
 * it reads. What grep can't follow: includes by Twig name, names built in PHP.
 */
#[AsCommand('twig:trace', 'Where a template is rendered or included, and what it includes and reads')]
final class TwigTrace extends Command
{
    #[\Override]
    protected function configure(): void
    {
        $this->addArgument('template', InputArgument::REQUIRED, 'A Twig name (product/card.twig, @block/hero/hero.twig) or its file (views/product/card.twig)');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        Gaffer::twig();
        $templates = Templates::all();
        $name = self::name((string) $input->getArgument('template'), $templates);
        if ($name === null) {
            $output->writeln('<error>No template ' . $input->getArgument('template') . ' (give its Twig name, e.g. product/card.twig, or its file under views/ or blocks/)</error>');
            return self::FAILURE;
        }
        $trace = self::trace($name, $templates);

        if ($input->getOption('json')) {
            $output->writeln((string) json_encode($trace, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }
        $output->writeln("<info>{$name}</info> " . self::relative($templates[$name]));
        $sections = [
            'Rendered by PHP' => array_map(static fn(array $r): string => "{$r['file']}:{$r['line']}" . ($r['pattern'] !== null ? " (computed: {$r['pattern']})" : ''), $trace['rendered_by']),
            'Included by' => array_map(static fn(array $r): string => "{$r['template']}:{$r['line']} {$r['kind']}" . ($r['pattern'] !== null ? " (computed: {$r['pattern']})" : ''), $trace['included_by']),
            'Search cards' => $trace['cards'],
            'Includes' => array_map(static fn(array $r): string => ($r['template'] ?? "(computed: {$r['pattern']})") . " (line {$r['line']}, {$r['kind']}" . ($r['isolated'] ? '' : ', with all variables') . ')', $trace['includes']),
            'Reads' => array_map(static fn(string $v, bool $optional): string => $v . ($optional ? ' (optional)' : ''), array_keys($trace['variables']), $trace['variables']),
        ];
        foreach ($sections as $title => $lines) {
            $output->writeln("\n{$title}:" . ($lines === [] ? ' none' : ''));
            foreach ($lines as $line) {
                $output->writeln("  {$line}");
            }
        }
        if ($trace['rendered_by'] === [] && $trace['included_by'] === [] && $trace['cards'] === []) {
            $output->writeln("\nNothing renders or includes it (doctor warns).");
        }

        return self::SUCCESS;
    }

    /**
     * @param array<string, string> $templates Twig name => file
     * @return array{template: string, rendered_by: list<array{file: string, line: int, pattern: ?string}>, included_by: list<array{template: string, line: int, kind: string, pattern: ?string}>, cards: list<string>, includes: list<array{template: ?string, pattern: ?string, line: int, kind: string, isolated: bool}>, variables: array<string, bool>}
     */
    public static function trace(string $name, array $templates): array
    {
        $rendered = [];
        foreach (ThemeFiles::find(['php']) as $file) {
            $code = (string) file_get_contents($file);
            foreach (Templates::php_renders($code) as ['template' => $template, 'pattern' => $pattern, 'offset' => $offset]) {
                if ($template === $name || ($template === null && $pattern !== null && fnmatch($pattern, $name))) {
                    $rendered[] = ['file' => self::relative($file), 'line' => substr_count($code, "\n", 0, $offset) + 1, 'pattern' => $template === null ? $pattern : null];
                }
            }
        }

        $included = [];
        foreach (array_keys($templates) as $other) {
            $module = $other === $name ? null : Templates::parse($other);
            foreach ($module !== null ? Templates::references($module) : [] as $ref) {
                if ($ref['template'] === $name || ($ref['template'] === null && $ref['pattern'] !== null && fnmatch($ref['pattern'], $name))) {
                    $included[] = ['template' => $other, 'line' => $ref['line'], 'kind' => $ref['kind'], 'pattern' => $ref['template'] === null ? $ref['pattern'] : null];
                }
            }
        }

        $cards = [];
        foreach (Indexes::all() as $index => $definition) {
            if (in_array($name, Indexes::templates($definition), true)) {
                $cards[] = "config/search.php index \"{$index}\"";
            }
        }

        $module = Templates::parse($name);

        return [
            'template' => $name,
            'rendered_by' => $rendered,
            'included_by' => $included,
            'cards' => $cards,
            'includes' => $module !== null ? Templates::references($module) : [],
            'variables' => $module !== null ? Templates::variables($module) : [],
        ];
    }

    /** @param array<string, string> $templates Twig name => file */
    private static function name(string $given, array $templates): ?string
    {
        if (isset($templates[$given])) {
            return $given;
        }
        $file = realpath(str_starts_with($given, '/') ? $given : Paths::base(ltrim($given, './')));
        $found = $file === false ? false : array_search($file, array_map(static fn(string $f): string|false => realpath($f), $templates), true);

        return is_string($found) ? $found : null;
    }

    private static function relative(string $file): string
    {
        return str_starts_with($file, Paths::base() . '/') ? substr($file, strlen(Paths::base()) + 1) : $file;
    }
}
