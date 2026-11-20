<?php

declare(strict_types=1);

namespace Gaffer\Console\Commands;

use Gaffer\Console\Command;
use Gaffer\Forms\FormReport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('forms:show', 'Gravity Forms forms and their fields as the theme sees them (no name: all forms)')]
final class FormsShow extends Command
{
    protected bool $wordpress = true;

    #[\Override]
    protected function configure(): void
    {
        $this->addArgument('form', InputArgument::OPTIONAL, 'Form name (the slug of its title, e.g. contact)');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!class_exists('GFAPI')) {
            $output->writeln('<error>Gravity Forms is not active.</error>');
            return self::FAILURE;
        }
        $name = $input->getArgument('form');

        if (!is_string($name)) {
            $table = new Table($output);
            $table->setHeaders(['Name', 'Title', 'Template', 'Fields with a name', 'Without']);
            foreach (FormReport::all() as $form) {
                $table->addRow([$form['name'], $form['title'], $form['template'] ?? '-', $form['named_fields'], $form['unnamed_fields']]);
            }
            $table->render();
            return self::SUCCESS;
        }

        $form = FormReport::one($name);
        if ($form === null) {
            $output->writeln("<error>No active Gravity Forms form titled like \"{$name}\".</error>");
            return self::FAILURE;
        }

        $output->writeln("{$form['title']} (form {$form['id']}), template: " . ($form['template'] ?? 'none'));
        $table = new Table($output);
        $table->setHeaders(['Name (Admin Field Label)', 'Label', 'Type', 'Required', 'In template']);
        foreach ($form['fields'] as $field) {
            $table->addRow([$field['name'] ?? '<comment>(none: set an Admin Field Label)</comment>', $field['label'], self::type($field['type'], $field['upload']),
                $field['required'] ? 'yes' : '', $field['in_template'] ? 'yes' : '-']);
        }
        $table->render();

        return self::SUCCESS;
    }

    /**
     * A File Upload field's rules after its type: "fileupload (several, max 4; jpg, png; 10 MB)".
     *
     * @param array{multiple: bool, max_files: ?int, extensions: list<string>, max_mb: ?int}|null $upload
     */
    private static function type(string $type, ?array $upload): string
    {
        if ($upload === null) {
            return $type;
        }
        $rules = [$upload['multiple'] ? ($upload['max_files'] !== null ? "several, max {$upload['max_files']}" : 'several') : 'one file'];
        $rules[] = $upload['extensions'] !== [] ? implode(', ', $upload['extensions']) : 'any type';
        $rules[] = $upload['max_mb'] !== null ? "{$upload['max_mb']} MB" : 'server limit';

        return "{$type} (" . implode('; ', $rules) . ')';
    }
}
