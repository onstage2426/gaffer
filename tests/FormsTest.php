<?php

declare(strict_types=1);

namespace Gaffer\Tests;

use Gaffer\Forms\FormState;
use Gaffer\Forms\FormTemplates;
use Gaffer\Forms\GravityForm;
use GF_Field;
use ReflectionMethod;

final class FormsTest extends TestCase
{
    public function test_form_state(): void
    {
        $state = new FormState('contact', ['naam' => 'Jan', 'kleur' => ['rood', 'blauw'], 'akkoord' => '1'], ['email' => 'Ongeldig']);

        self::assertSame('Jan', $state->value('naam'));
        self::assertSame('', $state->value('kleur')); // an array isn't a text value
        self::assertSame('', $state->value('missing'));
        self::assertTrue($state->checked('kleur', 'rood'));
        self::assertFalse($state->checked('kleur', 'groen'));
        self::assertTrue($state->checked('akkoord'));
        self::assertSame('Ongeldig', $state->error('email'));
        self::assertNull($state->error('naam'));
        self::assertFalse(FormState::blank('contact')->sent);
    }

    public function test_field_names_in_a_template(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'form') . '.twig';
        file_put_contents($file, <<<'TWIG'
            {{ include('components/form/field.twig', { form: form, name: 'naam', label: 'Naam' }, with_context = false) }}
            <input type="email" name="fields[email]">
            {{ include('components/form/field.twig', { name: "naam" }) }}
            TWIG);

        self::assertSame(['naam', 'email'], FormTemplates::field_names($file));
        unlink($file);
    }

    /** @return array<string, string> */
    private static function input(GF_Field $field, mixed $value): array
    {
        return new ReflectionMethod(GravityForm::class, 'input')->invoke(null, $field, $value);
    }

    public function test_gravity_forms_input_keys(): void
    {
        $text = new GF_Field();
        $text->id = 4;
        $text->type = 'email';
        self::assertSame(['input_4' => 'a@b.nl'], self::input($text, 'a@b.nl'));
        self::assertSame(['input_4' => ''], self::input($text, ['not', 'scalar']));

        $consent = new GF_Field();
        $consent->id = 7;
        $consent->type = 'consent';
        self::assertSame(['input_7_1' => '1'], self::input($consent, 'on'));
        self::assertSame([], self::input($consent, null));

        $checkbox = new GF_Field();
        $checkbox->id = 9;
        $checkbox->type = 'checkbox';
        $checkbox->choices = [['text' => 'Rood', 'value' => 'rood'], ['text' => 'Groen', 'value' => 'groen'], ['text' => 'Blauw', 'value' => 'blauw']];
        $checkbox->inputs = [['id' => '9.1'], ['id' => '9.2'], ['id' => '9.3']];
        self::assertSame(['input_9_1' => 'rood', 'input_9_3' => 'blauw'], self::input($checkbox, ['rood', 'blauw', 'paars']));
    }
}
