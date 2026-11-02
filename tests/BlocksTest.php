<?php

declare(strict_types=1);

namespace Gaffer\Tests;

use Gaffer\Blocks;
use Gaffer\Paths;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

final class BlocksTest extends TestCase
{
    private string $theme;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->theme = sys_get_temp_dir() . '/gaffer-blocks-' . bin2hex(random_bytes(4));
        mkdir("{$this->theme}/blocks/contentFaq", 0777, true);
        file_put_contents("{$this->theme}/blocks/contentFaq/block.json", '{"name": "acf/content-faq", "title": "FAQ"}');
        file_put_contents("{$this->theme}/blocks/contentFaq/fields.php", <<<'PHP'
            <?php
            return [
                ['label' => 'Velden', 'type' => 'tab'],
                ['label' => 'Titel', 'name' => 'titel', 'type' => 'text'],
                ['label' => 'Vragen', 'name' => 'vragen', 'type' => 'repeater', 'sub_fields' => [
                    ['label' => 'Vraag', 'name' => 'vraag', 'type' => 'text'],
                    ['label' => 'Antwoord', 'name' => 'antwoord', 'type' => 'wysiwyg'],
                ]],
                ['label' => 'Flip', 'name' => 'flip', 'type' => 'true_false'],
                ['label' => 'Afbeelding', 'name' => 'afbeelding', 'type' => 'image'],
                ['label' => 'Knop', 'name' => 'knop', 'type' => 'link'],
                ['label' => 'Categorieën', 'name' => 'categorieen', 'type' => 'taxonomy'],
                ['label' => 'Instellingen', 'name' => 'instellingen', 'type' => 'group', 'sub_fields' => [['label' => 'A', 'name' => 'a', 'type' => 'text']]],
            ];
            PHP);
        Paths::set_base($this->theme);
    }

    #[\Override]
    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->theme));
    }

    public function test_values_are_stored_next_to_their_derived_keys_as_acf_stores_them(): void
    {
        $markup = Blocks::markup('acf/content-faq', [
            'titel' => 'Vragen & antwoorden',
            'vragen' => [['vraag' => 'Bezorgen jullie?', 'antwoord' => '<p>Ja -- gekoeld.</p>'], ['vraag' => 'Waar?']],
            'flip' => false,
            'afbeelding' => 100,
            'knop' => ['title' => 'Contact', 'url' => 'https://example.nl/contact/'],
            'categorieen' => [56, 57],
        ]);

        self::assertSame(
            '<!-- wp:acf/content-faq {"name":"acf/content-faq","data":{"titel":"Vragen \u0026 antwoorden","_titel":"field_content-faq__titel",'
            . '"vragen":2,"_vragen":"field_content-faq__vragen",'
            . '"vragen_0_vraag":"Bezorgen jullie?","_vragen_0_vraag":"field_content-faq__vragen__vraag",'
            . '"vragen_0_antwoord":"\u003cp\u003eJa \u002d\u002d gekoeld.\u003c/p\u003e","_vragen_0_antwoord":"field_content-faq__vragen__antwoord",'
            . '"vragen_1_vraag":"Waar?","_vragen_1_vraag":"field_content-faq__vragen__vraag",'
            . '"flip":"0","_flip":"field_content-faq__flip","afbeelding":100,"_afbeelding":"field_content-faq__afbeelding",'
            . '"knop":{"title":"Contact","url":"https://example.nl/contact/","target":""},"_knop":"field_content-faq__knop",'
            . '"categorieen":["56","57"],"_categorieen":"field_content-faq__categorieen"},"mode":"preview"} /-->',
            $markup,
        );
    }

    public function test_a_block_without_values_has_empty_data(): void
    {
        self::assertSame('<!-- wp:acf/content-faq {"name":"acf/content-faq","data":[],"mode":"preview"} /-->', Blocks::markup('acf/content-faq', []));
    }

    /** @return iterable<string, array{string, array<string, mixed>, string}> */
    public static function refused(): iterable
    {
        yield 'unknown block' => ['acf/content-fag', [], 'No ACF block acf/content-fag with a fields.php (did you mean acf/content-faq?)'];
        yield 'unknown field' => ['acf/content-faq', ['titl' => 'x'], 'acf/content-faq: no field "titl" (did you mean "titel"?)'];
        yield 'unknown sub field' => ['acf/content-faq', ['vragen' => [['vraaag' => 'x']]], 'no field "vraaag" (did you mean "vraag"?)'];
        yield 'repeater not a list' => ['acf/content-faq', ['vragen' => ['vraag' => 'x']], 'vragen is a repeater: give a list of rows'];
        yield 'row not by name' => ['acf/content-faq', ['vragen' => [['x', 'y']]], 'vragen, row 0: a row is an array by sub field name'];
        yield 'true/false not a bool' => ['acf/content-faq', ['flip' => 1], 'flip is a true/false field: give a bool'];
        yield 'image not an ID' => ['acf/content-faq', ['afbeelding' => 'foto.jpg'], 'afbeelding is an image field: give the attachment ID'];
        yield 'link without a url' => ['acf/content-faq', ['knop' => ['title' => 'x']], 'knop is a link field'];
        yield 'a bool for text' => ['acf/content-faq', ['titel' => true], 'a bool fits only a true/false field'];
        yield 'group' => ['acf/content-faq', ['instellingen' => ['a' => 'x']], "instellingen is a group: Blocks::markup() doesn't write group fields yet"];
    }

    /** @param array<string, mixed> $values */
    #[DataProvider('refused')]
    public function test_values_a_field_cant_take_are_refused(string $block, array $values, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        Blocks::markup($block, $values);
    }
}
