<?php

declare(strict_types=1);

namespace Gaffer\Console\Commands;

use Closure;
use Gaffer\Console\Command;
use Gaffer\Console\ConfigStubs;
use Gaffer\Config;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('config:show', "Show the theme's config, flagging keys Gaffer doesn't know")]
final class ConfigShow extends Command
{
    #[\Override]
    protected function configure(): void
    {
        $this->addArgument('key', InputArgument::OPTIONAL, 'Dot-notation key, e.g. theme.types');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $key = $input->getArgument('key');

        if (is_string($key)) {
            $value = self::exportable(Config::get($key));
            $output->writeln($input->getOption('json')
                ? json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                : ($value === null ? "<comment>{$key} is not set.</comment>" : var_export($value, true)));
            return self::SUCCESS;
        }

        $stubs = ConfigStubs::keys();
        $rows = [];

        foreach (Config::all() as $file => $values) {
            foreach ($values as $name => $value) {
                $known = $stubs[$file] ?? null;
                [$status, $hint] = match (true) {
                    $known === null => ['theme', null],
                    in_array($name, $known, true) => ['gaffer', null],
                    default => ['unknown', ConfigStubs::did_you_mean($name, $known)],
                };
                $rows[] = [
                    'key' => "{$file}.{$name}",
                    'value' => self::exportable($value),
                    'status' => $status,
                    'hint' => $hint,
                ];
            }
        }

        if ($input->getOption('json')) {
            $output->writeln(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return self::SUCCESS;
        }

        $labels = ['gaffer' => '', 'theme' => '<comment>theme config</comment>', 'unknown' => '<error>unknown</error>'];
        $table = new Table($output);
        $table->setHeaders(['Key', 'Value', '']);
        foreach ($rows as $row) {
            $table->addRow([
                $row['key'],
                self::short($row['value']),
                $labels[$row['status']] . ($row['hint'] ? " did you mean {$row['hint']}?" : ''),
            ]);
        }
        $table->render();

        return self::SUCCESS;
    }

    /**
     * Closures can't be printed or JSON-encoded; show them as "Closure".
     */
    private static function exportable(mixed $value): mixed
    {
        return match (true) {
            $value instanceof Closure => 'Closure',
            is_array($value) => array_map(self::exportable(...), $value),
            default => $value,
        };
    }

    private static function short(mixed $value): string
    {
        $text = is_string($value) ? $value : (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return mb_strlen($text) > 70 ? mb_substr($text, 0, 67) . '...' : $text;
    }
}
