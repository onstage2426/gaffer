<?php

declare(strict_types=1);

namespace Gaffer\Tests;

use Gaffer\Console\Migrate\FieldReferences;
use Gaffer\Console\Migrate\Location;
use Gaffer\Console\Migrate\Reference;
use Gaffer\FieldGroups;

final class FieldReferencesTest extends TestCase
{
    /** The UI group's fields as ACF loads them (random keys), and the same ones in fields/ */
    private const array UI = [
        ['key' => 'field_aaa', 'name' => 'formaten', 'type' => 'text'],
        ['key' => 'field_bbb', 'name' => 'vragen', 'type' => 'repeater', 'sub_fields' => [['key' => 'field_ccc', 'name' => 'vraag', 'type' => 'text']]],
        ['key' => 'field_ddd', 'name' => 'oud', 'type' => 'text'],
        ['key' => 'field_eee', 'name' => 'aantal', 'type' => 'text'],
    ];

    private const array CODE = [
        ['name' => 'formaten', 'type' => 'text'],
        ['name' => 'vragen', 'type' => 'repeater', 'sub_fields' => [['name' => 'vraag', 'type' => 'text']]],
        ['name' => 'aantal', 'type' => 'number'],
    ];

    public function test_only_the_targets_own_references_are_rewritten(): void
    {
        $sorter = self::sorter('post_type/product');
        $sorted = $sorter->classify([
            self::meta(1, '_formaten', 'field_aaa', 'product', 10),
            self::meta(2, '_vragen_0_vraag', 'field_ccc', 'product', 10),
            self::meta(3, '_vragen', 'field_bbb', 'product', 11),        // a revision of a product: owner type is the parent's
            self::meta(4, '_formaten', 'field_aaa', 'post', 12),         // a blog draft from when the group targeted posts
            self::meta(5, '_categorieen', 'field_aaa', 'product', 10),   // the field's old name
            self::meta(6, '_', 'field_aaa', 'product', 10),
            self::meta(7, '_aantal_label', 'field_eee', 'product', 13),  // stored under another name: not a type problem
            self::meta(8, '_aantal', 'field_eee', 'product', 13),
            self::meta(9, '_oud', 'field_ddd', 'product', 14),
        ]);

        self::assertSame([1, 2, 3], array_map(static fn(array $r): int => $r['reference']->location->id, $sorted['rewrite']));
        self::assertSame(['field_product__formaten', 'field_product__vragen__vraag', 'field_product__vragen'], array_map(static fn(array $r): string => str_replace('post_type_', '', $r['to']), $sorted['rewrite']));
        self::assertSame([10, 'vragen'], [$sorted['rewrite'][1]['post_id'], $sorted['rewrite'][1]['field']]);
        self::assertSame([4], array_map(static fn(Reference $r): int => $r->location->id, $sorted['outside']));
        self::assertSame([[5, 'formaten'], [6, 'formaten'], [7, 'aantal']], array_map(static fn(array $found): array => [$found[0]->location->id, $found[1]], $sorted['other_names']));
        self::assertSame([
            '"aantal" of "Product: Info" is a text field, fields/post_type/product.php makes it a number',
            '"oud" of "Product: Info" isn\'t in fields/post_type/product.php: add it, or delete its values',
        ], array_keys($sorted['problems']));
    }

    public function test_options_by_prefix_and_language(): void
    {
        $sorter = self::sorter('options_page/theme', ['options', 'options_nl']);
        $sorted = $sorter->classify([
            self::option(1, '_options_formaten', 'field_aaa'),
            self::option(2, '_options_nl_vragen_2_vraag', 'field_ccc'),
            self::option(3, '_options_naam', 'field_aaa'),         // an old name
            self::option(4, '_widget_formaten', 'field_aaa'),      // another options post_id
        ]);

        self::assertSame([['options', 'formaten'], ['options_nl', 'vragen']], array_map(static fn(array $r): array => [$r['post_id'], $r['field']], $sorted['rewrite']));
        self::assertSame([3], array_map(static fn(array $found): int => $found[0]->location->id, $sorted['other_names']));
        self::assertSame([4], array_map(static fn(Reference $r): int => $r->location->id, $sorted['outside']));
    }

    public function test_broken_keys_are_references_under_a_field_name(): void
    {
        $sorter = self::sorter('taxonomy/product_cat');
        $broken = $sorter->broken([
            new Reference(new Location('termmeta', 1, 'product_cat term 5 _formaten'), '_formaten', 'field_aaa2', 'product_cat', 5),
            new Reference(new Location('termmeta', 2, 'product_cat term 5 _iets_anders'), '_iets_anders', 'field_zzz', 'product_cat', 5),
        ]);

        self::assertSame([[1, 'field_taxonomy_product_cat__formaten']], array_map(static fn(array $found): array => [$found[0]->location->id, $found[1]['key']], $broken));
    }

    public function test_leftovers_are_target_keys_under_another_name(): void
    {
        $sorter = self::sorter('post_type/product');
        $leftovers = $sorter->leftovers([
            self::meta(1, '_categorieen', 'field_aaa', 'product', 10),                          // an old name, UI key
            self::meta(2, '_', 'field_product_derived', 'product', 10),                          // unknown key, no field name: not ours
            self::meta(3, '_formaten', 'field_aaa', 'product', 10),                              // fine (migrate:fields moves it)
            self::meta(4, '_oud_naam', 'field_post_type_product__formaten', 'product', 10),      // derived key, another name
            self::meta(5, '_formaten', 'field_other_group', 'product', 10),                      // another group's field: not ours
            self::meta(6, '_categorieen', 'field_aaa', 'post', 12),                              // outside the target
        ]);

        self::assertSame([[1, 'under another name than its field "formaten"'], [4, 'under another name than its field "formaten"']],
            array_map(static fn(array $found): array => [$found[0]->location->id, $found[1]], $leftovers));
    }

    public function test_removed_fields_are_found_by_name(): void
    {
        $products = self::sorter('post_type/product');
        self::assertSame('^_?excluton_3d_sku$', $products->name_regex((string) $products->child_pattern(['excluton_3d_sku'])));
        self::assertSame('^_?vragen_[0-9]+_bron$', $products->name_regex((string) $products->child_pattern(['vragen', 'bron'])));
        self::assertNull($products->child_pattern(['formaten', 'x'])); // not a repeater or group
        self::assertTrue($products->has('vragen.vraag'));

        $options = self::sorter('options_page/theme', ['options', 'options_nl']);
        self::assertSame('^_?(options|options_nl)_titel$', $options->name_regex((string) $options->child_pattern(['titel'])));
    }

    /** @param list<string> $prefixes */
    private static function sorter(string $target, array $prefixes = []): FieldReferences
    {
        $old = [];
        foreach (FieldReferences::paths(self::UI) as $field) {
            $old[$field['key']] = [...$field, 'title' => 'Product: Info'];
        }
        $new = FieldReferences::paths(FieldGroups::keyed(self::CODE, FieldGroups::prefix($target), "fields/{$target}.php"));

        return new FieldReferences($target, $old, $new, $prefixes);
    }

    private static function meta(int $id, string $name, string $key, string $type, int $post): Reference
    {
        return new Reference(new Location('postmeta', $id, "{$type} {$post} {$name}"), $name, $key, $type, $post);
    }

    private static function option(int $id, string $name, string $key): Reference
    {
        return new Reference(new Location('option', $id, "option {$name}"), $name, $key, 'option', null);
    }
}
