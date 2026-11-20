<?php

declare(strict_types=1);

namespace Gaffer\Console\Commands;

use Gaffer\Console\Command;
use Gaffer\Forms\GravityForm;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Submits through Gaffer's own path (GravityForm::submit()) to a throwaway Gravity Forms
 * form and reads back what Gravity Forms made of it: stored values (slashing, checkbox and
 * consent input keys), the entry's status (Gravity Forms' honeypot and spam filters let
 * GFAPI submissions through), the answer's shape, the kept-files list Gaffer reads. Then it
 * deletes the form and its entries. One case per process: Gravity Forms keeps a process's
 * first submission (GFFormDisplay::$submission). Prints {"problems": [...]} (doctor --wp).
 *
 * No notifications or add-on feeds run (the form has none, and both are filtered off); a
 * plugin hooked on every form's gform_after_submission still sees the entry.
 */
#[AsCommand('forms:probe', 'Check how Gravity Forms takes Gaffer\'s submissions on a throwaway form (doctor --wp runs it)', hidden: true)]
final class FormsProbe extends Command
{
    protected bool $wordpress = true;

    /** A value that shows slashing, quoting, entities and encoding problems. */
    public const string TEXT = "O'Brien \"quoted\" back\\slash & é";

    public const string AREA = "line one\nline two";

    #[\Override]
    protected function configure(): void
    {
        $this->addArgument('case', InputArgument::REQUIRED, 'valid or invalid');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $case = $input->getArgument('case');
        if (!class_exists('GFAPI') || !in_array($case, ['valid', 'invalid'], true)) {
            $output->writeln((string) json_encode(['problems' => ['Gravity Forms is not active, or the case is not valid/invalid']]));
            return self::FAILURE;
        }
        \add_filter('gform_disable_notification', '__return_true');
        \add_filter('gform_addon_pre_process_feeds', static fn(): array => []);

        $title = 'Gaffer probe ' . bin2hex(random_bytes(4));
        $id = \GFAPI::add_form(self::form($title));
        if ($id instanceof \WP_Error) {
            $output->writeln((string) json_encode(['problems' => ['GFAPI::add_form() refused the probe form: ' . $id->get_error_message()]]));
            return self::FAILURE;
        }
        try {
            $problems = $case === 'valid' ? self::valid($title, $id) : self::invalid($title);
        } catch (\Throwable $e) {
            $problems = ['Submitting threw ' . $e::class . ': ' . $e->getMessage()];
        } finally {
            $entries = \GFAPI::get_entries($id);
            foreach (is_array($entries) ? $entries : [] as $entry) {
                if (isset($entry['id'])) {
                    \GFAPI::delete_entry((int) $entry['id']);
                }
            }
            \GFAPI::delete_form($id);
        }
        $output->writeln((string) json_encode(['problems' => $problems], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }

    /** @return list<string> */
    private static function valid(string $title, int $id): array
    {
        $form = GravityForm::find(sanitize_title($title));
        if ($form === null) {
            return ['GravityForm::find() doesn\'t find a form by the slug of its title'];
        }
        $result = $form->submit(['text' => self::TEXT, 'choices' => ['b', 'c'], 'consent' => '1', 'area' => self::AREA]);
        $problems = [];
        if (!$result['valid']) {
            return ['A valid submission was refused: ' . json_encode($result['errors'] ?: $result['message'], JSON_UNESCAPED_UNICODE)];
        }
        if (!str_contains((string) $result['message'], 'Probe sent')) {
            $problems[] = 'The confirmation message isn\'t in the answer (confirmation_message)';
        }
        $entries = \GFAPI::get_entries($id);
        $entry = is_array($entries) ? ($entries[0] ?? null) : null;
        if (!is_array($entry)) {
            return [...$problems, 'No entry was stored'];
        }
        if (($entry['status'] ?? null) !== 'active') {
            $problems[] = 'The entry was stored as "' . ($entry['status'] ?? '?') . '", not active: Gravity Forms\' honeypot or spam filter no longer lets GFAPI submissions through';
        }
        $expected = ['1' => self::TEXT, '2.1' => '', '2.2' => 'b', '2.3' => 'c', '3.1' => '1', '4' => self::AREA];
        foreach ($expected as $input => $value) {
            if ((string) ($entry[$input] ?? '') !== $value) {
                $problems[] = "Input {$input} was stored as " . json_encode($entry[$input] ?? null, JSON_UNESCAPED_UNICODE) . ', sent ' . json_encode($value, JSON_UNESCAPED_UNICODE)
                    . ' (input keys input_{id}/input_{id}_{n}, wp_slash() for rgpost())';
            }
        }
        $property = new \ReflectionClass('GFFormsModel');
        if (!$property->hasProperty('uploaded_files') || !$property->getProperty('uploaded_files')->isStatic() || !$property->getProperty('uploaded_files')->isPublic()) {
            $problems[] = 'GFFormsModel::$uploaded_files is gone: files kept after errors (FormState::files()) no longer work';
        }

        return $problems;
    }

    /** @return list<string> */
    private static function invalid(string $title): array
    {
        $form = GravityForm::find(sanitize_title($title));
        if ($form === null) {
            return ['GravityForm::find() doesn\'t find a form by the slug of its title'];
        }
        $result = $form->submit(['text' => '', 'area' => self::AREA]);
        if ($result['valid']) {
            return ['A submission without a required field was accepted'];
        }
        if ($result['failed'] || ($result['errors']['text'] ?? '') === '') {
            return ['A missing required field didn\'t come back as that field\'s error (validation_messages by field ID): ' . json_encode($result, JSON_UNESCAPED_UNICODE)];
        }

        return [];
    }

    /** @return array<string, mixed> */
    private static function form(string $title): array
    {
        return [
            'title' => $title,
            'description' => 'Made and deleted by php gaffer forms:probe',
            'labelPlacement' => 'top_label',
            'button' => ['type' => 'text', 'text' => 'Send'],
            'fields' => [
                ['type' => 'text', 'id' => 1, 'label' => 'Text', 'adminLabel' => 'text', 'isRequired' => true],
                ['type' => 'checkbox', 'id' => 2, 'label' => 'Choices', 'adminLabel' => 'choices',
                    'choices' => [['text' => 'A', 'value' => 'a'], ['text' => 'B', 'value' => 'b'], ['text' => 'C', 'value' => 'c']],
                    'inputs' => [['id' => '2.1', 'label' => 'A'], ['id' => '2.2', 'label' => 'B'], ['id' => '2.3', 'label' => 'C']]],
                ['type' => 'consent', 'id' => 3, 'label' => 'Consent', 'adminLabel' => 'consent', 'checkboxLabel' => 'I agree',
                    'inputs' => [['id' => '3.1', 'label' => 'Consent'], ['id' => '3.2', 'label' => 'Text', 'isHidden' => true], ['id' => '3.3', 'label' => 'Description', 'isHidden' => true]]],
                ['type' => 'textarea', 'id' => 4, 'label' => 'Area', 'adminLabel' => 'area'],
            ],
            'confirmations' => ['probe' => ['id' => 'probe', 'name' => 'Default', 'isDefault' => true, 'type' => 'message', 'message' => 'Probe sent']],
            'notifications' => [],
        ];
    }
}
