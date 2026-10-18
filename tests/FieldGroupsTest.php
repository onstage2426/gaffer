<?php

declare(strict_types=1);

namespace Gaffer\Tests;

use Gaffer\FieldGroups;
use Gaffer\Paths;
use LogicException;

final class FieldGroupsTest extends TestCase
{
    private string $theme;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->theme = sys_get_temp_dir() . '/gaffer-groups-' . bin2hex(random_bytes(4));
        mkdir("{$this->theme}/fields/post_type", 0755, true);
        Paths::set_base($this->theme);
    }

    #[\Override]
    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->theme));
    }

    public function test_group_targets_its_location_with_derived_keys(): void
    {
        file_put_contents("{$this->theme}/fields/post_type/review.php", "<?php\n\nreturn [\n"
            . "['label' => 'Velden', 'type' => 'tab'],\n"
            . "['name' => 'score', 'type' => 'number'],\n"
            . "['name' => 'punten', 'type' => 'repeater', 'sub_fields' => [['name' => 'punt', 'type' => 'text']]],\n];\n");

        $group = FieldGroups::group('post_type/review');

        self::assertSame(['post_type/review' => "{$this->theme}/fields/post_type/review.php"], FieldGroups::targets());
        self::assertSame('group_post_type_review', $group['key']);
        self::assertSame('Post type: review', $group['title']);
        self::assertSame([[['param' => 'post_type', 'operator' => '==', 'value' => 'review']]], $group['location']);
        self::assertSame(['field_post_type_review__tab-velden', 'field_post_type_review__score', 'field_post_type_review__punten'], array_column($group['fields'], 'key'));
        self::assertSame('field_post_type_review__punten__punt', $group['fields'][2]['sub_fields'][0]['key']);
    }

    public function test_only_known_params_are_targets(): void
    {
        mkdir("{$this->theme}/fields/user", 0755, true);
        file_put_contents("{$this->theme}/fields/user/klant.php", '<?php return [];');

        self::assertSame([], FieldGroups::targets());
        self::assertSame(['user'], FieldGroups::unknown_dirs());
        $this->expectException(LogicException::class);
        FieldGroups::group('user/klant');
    }

    public function test_target_of_only_expressible_locations(): void
    {
        $rule = static fn(string $param, string $value, string $operator = '=='): array => ['param' => $param, 'operator' => $operator, 'value' => $value];

        self::assertSame('options_page/bs-settings-general', FieldGroups::target_of([[$rule('options_page', 'bs-settings-general')]]));
        self::assertSame('taxonomy/pa_kleur', FieldGroups::target_of([[$rule('taxonomy', 'pa_kleur')]]));
        self::assertNull(FieldGroups::target_of([[$rule('post_type', 'page')], [$rule('post_type', 'post')]])); // two targets
        self::assertNull(FieldGroups::target_of([[$rule('post_type', 'page'), $rule('page_template', 'x.php')]])); // and-rule
        self::assertNull(FieldGroups::target_of([[$rule('post_type', 'page', '!=')]]));
        self::assertNull(FieldGroups::target_of([[$rule('block', 'acf/content')]]));
    }
}
