<?php

declare(strict_types=1);

namespace Gaffer\Console\Commands;

use Gaffer\Console\Command;
use Gaffer\Forms\FormTemplates;
use Gaffer\Forms\GravityForm;
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
        $templates = FormTemplates::all();
        $name = $input->getArgument('form');

        if (!is_string($name)) {
            $table = new Table($output);
            $table->setHeaders(['Name', 'Title', 'Template', 'Fields with a name', 'Without']);
            foreach (\GFAPI::get_forms() as $form) {
                $slug = \sanitize_title((string) $form['title']);
                $gravity = GravityForm::find($slug);
                $table->addRow([$slug, $form['title'], isset($templates[$slug]) ? "views/forms/{$slug}.twig" : '-',
                    $gravity ? count($gravity->fields()) : 0, $gravity ? count($gravity->unnamed()) : 0]);
            }
            $table->render();
            return self::SUCCESS;
        }

        $gravity = GravityForm::find($name);
        if ($gravity === null) {
            $output->writeln("<error>No active Gravity Forms form titled like \"{$name}\".</error>");
            return self::FAILURE;
        }
        $sent = isset($templates[$name]) ? FormTemplates::field_names($templates[$name]) : [];

        $output->writeln("{$gravity->title()} (form {$gravity->id()}), template: " . (isset($templates[$name]) ? "views/forms/{$name}.twig" : 'none'));
        $table = new Table($output);
        $table->setHeaders(['Name (Admin Field Label)', 'Label', 'Type', 'Required', 'In template']);
        foreach ($gravity->fields() as $field_name => $field) {
            $table->addRow([$field_name, $field->label, $field->type, $field->isRequired ? 'yes' : '', in_array($field_name, $sent, true) ? 'yes' : '-']);
        }
        foreach ($gravity->unnamed() as $field) {
            $table->addRow(['<comment>(none: set an Admin Field Label)</comment>', $field->label, $field->type, $field->isRequired ? 'yes' : '', '-']);
        }
        $table->render();

        return self::SUCCESS;
    }
}
