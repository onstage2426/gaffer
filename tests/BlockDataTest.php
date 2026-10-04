<?php

declare(strict_types=1);

namespace Gaffer\Tests;

use Gaffer\Console\Migrate\BlockData;

final class BlockDataTest extends TestCase
{
    private const string P = 'field_content-faq';

    /** @return array<string, mixed> */
    private static function faq(): array
    {
        return [
            'titel' => 'Vragen',
            '_titel' => self::P . '__titel',
            'titel_extra' => 'x',
            '_titel_extra' => self::P . '__titel_extra',
            'vragen_0_vraag' => 'Een?',
            '_vragen_0_vraag' => self::P . '__vragen__vraag',
            'vragen_0_antwoord' => 'Ja.',
            '_vragen_0_antwoord' => self::P . '__vragen__antwoord',
            'vragen_1_vraag' => 'Twee?',
            '_vragen_1_vraag' => self::P . '__vragen__vraag',
            'vragen_1_antwoord' => 'Nee.',
            '_vragen_1_antwoord' => self::P . '__vragen__antwoord',
            'vragen' => 2,
            '_vragen' => self::P . '__vragen',
        ];
    }

    /**
     * @param array<mixed> $data
     * @param list<string> $path
     * @return array{data: array<mixed>, changes: int, problems: list<string>}
     */
    private static function rename_field(array $data, array $path, string $to): array
    {
        return BlockData::rename($data, BlockData::field_renamer(self::P, $path, $to));
    }

    public function test_clean_data_has_no_problems(): void
    {
        self::assertSame([], BlockData::problems(self::faq(), self::P));
        self::assertSame([], BlockData::problems([], self::P));
    }

    public function test_unexpected_shapes_are_problems(): void
    {
        $problems = BlockData::problems([
            'field_abc' => 'stored by key',
            'lonely' => 1,
            '_orphan' => self::P . '__orphan',
            'other' => 1,
            '_other' => 'field_hero-home__other',
            'odd' => 1,
            '_odd' => ['not a string'],
        ], self::P);

        self::assertCount(5, $problems);
        self::assertStringContainsString('by field key', $problems[0]);
        self::assertStringContainsString('"lonely" has no field reference', $problems[1]);
        self::assertStringContainsString('"_orphan" has no value', $problems[2]);
        self::assertStringContainsString('not to a field of this block', $problems[3]);
        self::assertStringContainsString('not to a field of this block', $problems[4]);
    }

    public function test_renames_a_field_and_keeps_order_and_values(): void
    {
        $result = self::rename_field(self::faq(), ['titel'], 'kop');

        self::assertSame(1, $result['changes']);
        self::assertSame([
            'kop', '_kop', 'titel_extra', '_titel_extra', // titel_extra is a different field
            'vragen_0_vraag', '_vragen_0_vraag', 'vragen_0_antwoord', '_vragen_0_antwoord',
            'vragen_1_vraag', '_vragen_1_vraag', 'vragen_1_antwoord', '_vragen_1_antwoord', 'vragen', '_vragen',
        ], array_keys($result['data']));
        self::assertSame('Vragen', $result['data']['kop']);
        self::assertSame(self::P . '__kop', $result['data']['_kop']);
        self::assertSame(self::P . '__titel_extra', $result['data']['_titel_extra']);
    }

    public function test_renaming_a_repeater_renames_its_rows(): void
    {
        $data = self::rename_field(self::faq(), ['vragen'], 'faq')['data'];

        self::assertSame(2, $data['faq']);
        self::assertSame(self::P . '__faq', $data['_faq']);
        self::assertSame('Twee?', $data['faq_1_vraag']);
        self::assertSame(self::P . '__faq__vraag', $data['_faq_1_vraag']);
        self::assertSame(self::P . '__faq__antwoord', $data['_faq_0_antwoord']);
        self::assertArrayNotHasKey('vragen_0_vraag', $data);
    }

    public function test_renaming_a_sub_field_leaves_its_siblings(): void
    {
        $result = self::rename_field(self::faq(), ['vragen', 'vraag'], 'vraag_tekst');
        $data = $result['data'];

        self::assertSame(2, $result['changes']); // both rows
        self::assertSame('Een?', $data['vragen_0_vraag_tekst']);
        self::assertSame(self::P . '__vragen__vraag_tekst', $data['_vragen_1_vraag_tekst']);
        self::assertSame('Ja.', $data['vragen_0_antwoord']);
        self::assertSame(2, $data['vragen']);
        self::assertSame(self::P . '__vragen', $data['_vragen']);
    }

    public function test_nested_repeaters_and_groups(): void
    {
        $data = [
            'secties_0_items_1_tekst' => 'a', '_secties_0_items_1_tekst' => self::P . '__secties__items__tekst',
            'secties_0_items' => 2, '_secties_0_items' => self::P . '__secties__items',
            'secties' => 1, '_secties' => self::P . '__secties',
            'knop_link' => 'url', '_knop_link' => self::P . '__knop__link', // group "knop", sub field "link"
            'knop' => '', '_knop' => self::P . '__knop',
        ];

        $middle = self::rename_field($data, ['secties', 'items'], 'regels')['data'];
        self::assertSame('a', $middle['secties_0_regels_1_tekst']);
        self::assertSame(self::P . '__secties__regels__tekst', $middle['_secties_0_regels_1_tekst']);
        self::assertSame(2, $middle['secties_0_regels']);

        $deep = self::rename_field($data, ['secties', 'items', 'tekst'], 'inhoud')['data'];
        self::assertSame('a', $deep['secties_0_items_1_inhoud']);

        $group = self::rename_field($data, ['knop', 'link'], 'url')['data'];
        self::assertSame('url', $group['knop_url']);
        self::assertSame(self::P . '__knop__url', $group['_knop_url']);
    }

    public function test_names_with_digits_and_underscores(): void
    {
        $data = ['rijen_3_item_1' => 'x', '_rijen_3_item_1' => self::P . '__rijen__item_1', 'rijen' => 4, '_rijen' => self::P . '__rijen'];

        self::assertSame('x', self::rename_field($data, ['rijen', 'item_1'], 'item')['data']['rijen_3_item']);
        self::assertSame('x', self::rename_field($data, ['rijen'], 'rows_2')['data']['rows_2_3_item_1']);
    }

    public function test_collisions_change_nothing(): void
    {
        $result = self::rename_field(self::faq(), ['titel'], 'titel_extra');

        self::assertSame(0, $result['changes']);
        self::assertSame(self::faq(), $result['data']);
        self::assertStringContainsString('already has a value', $result['problems'][0]);
    }

    public function test_a_name_that_does_not_match_its_key_is_a_problem(): void
    {
        $data = ['vraag' => 'x', '_vraag' => self::P . '__vragen__vraag'];

        $result = self::rename_field($data, ['vragen'], 'faq');

        self::assertSame($data, $result['data']);
        self::assertStringContainsString("doesn't match its field key", $result['problems'][0]);
    }

    public function test_renaming_to_the_same_name_changes_nothing(): void
    {
        $result = self::rename_field(self::faq(), ['titel'], 'titel');

        self::assertSame(0, $result['changes']);
        self::assertSame(self::faq(), $result['data']);
    }

    public function test_block_rename_moves_every_key(): void
    {
        $result = BlockData::rename(self::faq(), BlockData::block_renamer(self::P, 'field_faq'));

        self::assertSame(7, $result['changes']);
        self::assertSame(array_keys(self::faq()), array_keys($result['data']));
        self::assertSame('field_faq__vragen__vraag', $result['data']['_vragen_1_vraag']);
        self::assertSame('Nee.', $result['data']['vragen_1_antwoord']);
    }

    public function test_walk_visits_inner_blocks(): void
    {
        $blocks = [['blockName' => 'core/group', 'innerBlocks' => [['blockName' => 'acf/a', 'innerBlocks' => []]]]];
        $seen = [];

        BlockData::walk($blocks, static function (array $block) use (&$seen): array {
            $seen[] = $block['blockName'];
            return $block;
        });

        self::assertSame(['acf/a', 'core/group'], $seen);
    }

    public function test_remove_field_drops_values_and_references(): void
    {
        $result = BlockData::remove_field(self::faq(), self::P, ['titel']);

        self::assertSame(1, $result['changes']);
        self::assertArrayNotHasKey('titel', $result['data']);
        self::assertArrayNotHasKey('_titel', $result['data']);
        self::assertSame('x', $result['data']['titel_extra']); // a different field
    }

    public function test_remove_field_on_a_repeater_or_sub_field(): void
    {
        $repeater = BlockData::remove_field(self::faq(), self::P, ['vragen']);
        self::assertSame(5, $repeater['changes']);
        self::assertSame(['titel', '_titel', 'titel_extra', '_titel_extra'], array_keys($repeater['data']));

        $sub = BlockData::remove_field(self::faq(), self::P, ['vragen', 'antwoord']);
        self::assertSame(2, $sub['changes']);
        self::assertArrayHasKey('vragen_1_vraag', $sub['data']);
        self::assertArrayNotHasKey('vragen_1_antwoord', $sub['data']);
        self::assertSame(2, $sub['data']['vragen']); // the rows stay
    }

    /**
     * @param list<array<string, mixed>> $inner
     * @param list<string|null>|null $content
     * @return array<string, mixed>
     */
    private static function block(?string $name, string $html = '', array $inner = [], ?array $content = null): array
    {
        return ['blockName' => $name, 'attrs' => [], 'innerBlocks' => $inner, 'innerHTML' => $html, 'innerContent' => $content ?? [$html]];
    }

    public function test_remove_blocks_takes_the_blank_line_with_it(): void
    {
        $gap = self::block(null, "\n\n");
        $blocks = [self::block('acf/a'), $gap, self::block('acf/x'), $gap, self::block('acf/b')];

        $result = BlockData::remove_blocks($blocks, 'acf/x');

        self::assertSame(1, $result['removed']);
        self::assertSame(['acf/a', null, 'acf/b'], array_column($result['blocks'], 'blockName'));
    }

    public function test_remove_blocks_at_the_end_takes_the_blank_line_before(): void
    {
        $gap = self::block(null, "\n\n");

        $result = BlockData::remove_blocks([self::block('acf/a'), $gap, self::block('acf/x')], 'acf/x');

        self::assertSame(['acf/a'], array_column($result['blocks'], 'blockName'));
    }

    public function test_remove_blocks_inside_a_parent(): void
    {
        $group = self::block('core/group', '<div></div>', [self::block('acf/x'), self::block('acf/b')], ['<div>', null, "\n\n", null, '</div>']);

        $first = BlockData::remove_blocks([$group], 'acf/x')['blocks'][0];
        self::assertSame(['acf/b'], array_column($first['innerBlocks'], 'blockName'));
        self::assertSame(['<div>', null, '</div>'], $first['innerContent']);
        self::assertSame('<div></div>', $first['innerHTML']);

        $second = BlockData::remove_blocks([$group], 'acf/b')['blocks'][0];
        self::assertSame(['acf/x'], array_column($second['innerBlocks'], 'blockName'));
        self::assertSame(['<div>', null, '</div>'], $second['innerContent']);
    }

    public function test_a_block_containing_blocks_is_not_removed(): void
    {
        $parent = self::block('acf/x', '', [self::block('core/paragraph', '<p>Keep</p>')], [null]);

        $result = BlockData::remove_blocks([$parent], 'acf/x');

        self::assertSame(0, $result['removed']);
        self::assertSame([$parent], $result['blocks']);
        self::assertStringContainsString('contains other blocks', $result['problems'][0]);
    }

    public function test_fields_renamer_finds_fields_by_their_stored_name(): void
    {
        $fields = [
            ['key' => 'field_faq__titel', 'name' => 'titel', 'type' => 'text'],
            ['key' => 'field_faq__knop_primair', 'name' => 'knop_primair', 'type' => 'link'],
            ['key' => 'field_faq__vragen', 'name' => 'vragen', 'type' => 'repeater', 'sub_fields' => [
                ['key' => 'field_faq__vragen__vraag', 'name' => 'vraag', 'type' => 'text'],
                ['key' => 'field_faq__vragen__extra_info', 'name' => 'extra_info', 'type' => 'wysiwyg'],
            ]],
            ['key' => 'field_faq__adres', 'name' => 'adres', 'type' => 'group', 'sub_fields' => [
                ['key' => 'field_faq__adres__stad', 'name' => 'stad', 'type' => 'text'],
            ]],
        ];
        $old_types = ['field_old_titel' => 'text', 'field_old_vraag' => 'textarea'];
        $rename = BlockData::fields_renamer('field_faq', $fields, static fn(string $key): ?string => $old_types[$key] ?? null);

        self::assertSame(['titel', 'field_faq__titel'], $rename('titel', 'field_old_titel'));
        self::assertSame(['knop_primair', 'field_faq__knop_primair'], $rename('knop_primair', 'field_1'));
        self::assertSame(['vragen', 'field_faq__vragen'], $rename('vragen', 'field_2'));
        self::assertSame(['vragen_3_extra_info', 'field_faq__vragen__extra_info'], $rename('vragen_3_extra_info', 'field_3'));
        self::assertSame(['adres_stad', 'field_faq__adres__stad'], $rename('adres_stad', 'field_4'));
        self::assertNull($rename('titel', 'field_faq__titel')); // already derived
        self::assertSame('"onbekend" (field_5) isn\'t a field in fields.php', $rename('onbekend', 'field_5'));
        self::assertSame('"vragen_0_vraag" was a textarea field (field_old_vraag), fields.php makes it a text', $rename('vragen_0_vraag', 'field_old_vraag'));
    }
}
