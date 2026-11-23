<?php

declare(strict_types=1);

namespace Gaffer\Tests;

use Gaffer\Ajax;
use Gaffer\Forms\FormReport;
use Gaffer\Forms\FormState;
use Gaffer\Forms\KeptFile;
use Gaffer\Forms\Uploads;
use Gaffer\Forms\FormTemplates;
use Gaffer\Forms\GravityForm;
use Gaffer\View;
use GF_Field;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
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
            <input type="file" name="files[fotos][]" multiple>
            <input type="file" name="files[formulier]">
            <input type="hidden" name="kept_files[fotos][]" value="">
            TWIG);

        self::assertSame(['naam', 'email', 'fotos', 'formulier'], FormTemplates::field_names($file));
        self::assertSame(['email'], FormTemplates::literal_names($file, 'fields'));
        self::assertSame(['fotos', 'formulier'], FormTemplates::literal_names($file, 'files'));
        self::assertSame(['fotos'], FormTemplates::literal_names($file, 'files', list: true));
        unlink($file);
    }

    public function test_multipart_in_the_template_or_a_partial_it_includes(): void
    {
        $partial = "{{ include('components/form/meta.twig', {}, with_context = false) }}";
        self::reset(View::class, 'env', null);
        View::set_env(new Environment(new ArrayLoader([
            'forms/plain.twig' => '<form hx-post="x">' . $partial . '</form>',
            'forms/htmx.twig' => '<form hx-post="x" hx-encoding="multipart/form-data"></form>',
            'forms/enctype.twig' => "<form method=\"post\" enctype='multipart/form-data'></form>",
            'forms/shared.twig' => "{{ include('components/form/shell.twig', {}, with_context = false) }}",
            'forms/comment.twig' => '{# not hx-encoding="multipart/form-data" #}<form></form>',
            'components/form/shell.twig' => '<form hx-encoding="multipart/form-data">' . $partial . '</form>',
            'components/form/meta.twig' => '<input type="hidden" name="form">',
        ])));

        self::assertFalse(FormTemplates::multipart('forms/plain.twig'));
        self::assertTrue(FormTemplates::multipart('forms/htmx.twig'));
        self::assertTrue(FormTemplates::multipart('forms/enctype.twig'));
        self::assertTrue(FormTemplates::multipart('forms/shared.twig'));
        self::assertFalse(FormTemplates::multipart('forms/comment.twig'));
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

    public function test_uploads_by_field(): void
    {
        // PHP's $_FILES['files'] for files[fotos][] (two files), files[formulier] and junk
        $files = [
            'name' => ['fotos' => ['a.jpg', 'b.jpg'], 'formulier' => 'f.pdf', 'deep' => ['x' => ['y.jpg']], 0 => 'n.jpg'],
            'type' => ['fotos' => ['image/jpeg', 'image/jpeg'], 'formulier' => 'application/pdf'],
            'tmp_name' => ['fotos' => ['/tmp/p1', '/tmp/p2'], 'formulier' => '/tmp/p3'],
            'error' => ['fotos' => [0, 0], 'formulier' => 0],
            'size' => ['fotos' => [10, 20], 'formulier' => 30],
        ];

        self::assertSame([
            'fotos' => ['name' => ['a.jpg', 'b.jpg'], 'type' => ['image/jpeg', 'image/jpeg'], 'tmp_name' => ['/tmp/p1', '/tmp/p2'], 'error' => [0, 0], 'size' => [10, 20]],
            'formulier' => ['name' => 'f.pdf', 'type' => 'application/pdf', 'tmp_name' => '/tmp/p3', 'error' => 0, 'size' => 30],
        ], Uploads::by_field($files));
        self::assertSame([], Uploads::by_field(null));
        // fields[x] (since 2026-11-23) has the same shape as files[x]
        self::assertSame(Uploads::by_field($files), Uploads::by_field(['fields' => $files]['fields']));
        self::assertSame([], Uploads::by_field(['name' => 'a.jpg']));
    }

    public function test_kept_files(): void
    {
        $entry = ['temp_filename' => 'abc_input_3_xyz.jpg', 'uploaded_filename' => 'IMG_1.jpg', 'hash' => 'f00', 'id' => 'uuid'];
        $kept = KeptFile::from_gravity($entry);

        self::assertNotNull($kept);
        self::assertSame('IMG_1.jpg', $kept->name);
        self::assertSame(['temp_filename' => 'abc_input_3_xyz.jpg', 'uploaded_filename' => 'IMG_1.jpg', 'hash' => 'f00'], KeptFile::parse($kept->value));
        self::assertNull(KeptFile::from_gravity(['temp_filename' => 'a.jpg']));
        self::assertNull(KeptFile::parse('{"temp_filename":"a.jpg","uploaded_filename":"a.jpg","hash":7}'));
        self::assertNull(KeptFile::parse('nope'));
        self::assertNull(KeptFile::parse(['temp_filename' => 'a.jpg']));

        $state = new FormState('schade', files: ['fotos' => [$kept]]);
        self::assertSame([$kept], $state->files('fotos'));
        self::assertSame([], $state->files('other'));
    }

    public function test_too_large(): void
    {
        self::assertTrue(Ajax::too_large(['CONTENT_LENGTH' => (string) (9 * 1024 * 1024)], '8M'));
        self::assertFalse(Ajax::too_large(['CONTENT_LENGTH' => '1000'], '8M'));
        self::assertFalse(Ajax::too_large(['CONTENT_LENGTH' => '999999999'], '0')); // no limit
        self::assertFalse(Ajax::too_large([], '8M'));
    }

    public function test_upload_rules(): void
    {
        $field = new GF_Field();
        $field->type = 'fileupload';
        $field->multipleFiles = true;
        $field->maxFiles = '4';
        $field->allowedExtensions = 'JPG, .png,pdf ';
        $field->maxFileSize = '';
        self::assertSame(['multiple' => true, 'max_files' => 4, 'extensions' => ['jpg', 'png', 'pdf'], 'max_mb' => null], FormReport::upload($field));

        $field->multipleFiles = false;
        $field->maxFileSize = 10;
        self::assertSame(['multiple' => false, 'max_files' => null, 'extensions' => ['jpg', 'png', 'pdf'], 'max_mb' => 10], FormReport::upload($field));

        $text = new GF_Field();
        $text->type = 'text';
        self::assertNull(FormReport::upload($text));
    }
}
