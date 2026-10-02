<?php

declare(strict_types=1);

namespace Gaffer\Tests;

use Gaffer\BlockFields;
use Gaffer\Paths;
use LogicException;

final class BlockFieldsTest extends TestCase
{
    private string $theme;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->theme = sys_get_temp_dir() . '/gaffer-fields-' . bin2hex(random_bytes(4));
        mkdir("{$this->theme}/blocks/contentFaq", 0755, true);
        file_put_contents("{$this->theme}/blocks/contentFaq/block.json", '{"name": "acf/content-faq", "title": "Content FAQ"}');
        Paths::set_base($this->theme);
    }

    #[\Override]
    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->theme));
    }

    public function test_group_targets_the_block_with_derived_keys(): void
    {
        $this->fields(<<<'PHP'
            ['label' => 'Velden', 'type' => 'tab'],
            ['label' => 'Titel', 'name' => 'titel', 'type' => 'text'],
            ['label' => 'Vragen', 'name' => 'vragen', 'type' => 'repeater', 'sub_fields' => [
                ['label' => 'Vraag', 'name' => 'vraag', 'type' => 'text'],
            ]],
            PHP);

        $group = BlockFields::group('contentFaq');

        self::assertSame('group_content-faq', $group['key']);
        self::assertSame('Block: Content FAQ', $group['title']);
        self::assertSame([[['param' => 'block', 'operator' => '==', 'value' => 'acf/content-faq']]], $group['location']);
        self::assertSame(
            ['field_content-faq__tab-velden', 'field_content-faq__titel', 'field_content-faq__vragen'],
            array_column($group['fields'], 'key'),
        );
        self::assertSame('field_content-faq__vragen__vraag', $group['fields'][2]['sub_fields'][0]['key']);
        self::assertSame('Titel', $group['fields'][1]['label']); // the rest is passed through
    }

    public function test_explicit_keys_are_rejected(): void
    {
        $this->fields("['key' => 'field_123', 'name' => 'titel', 'type' => 'text'],");

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("don't set 'key'");
        BlockFields::group('contentFaq');
    }

    public function test_duplicate_keys_are_rejected(): void
    {
        $this->fields("['label' => 'Velden', 'type' => 'tab'], ['label' => 'Velden', 'type' => 'tab'],");

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('field_content-faq__tab-velden');
        BlockFields::group('contentFaq');
    }

    public function test_names_that_would_make_keys_ambiguous_are_rejected(): void
    {
        foreach (['_titel', 'titel__kort'] as $name) {
            $this->fields("['name' => '{$name}', 'type' => 'text'],");
            try {
                BlockFields::group('contentFaq');
                self::fail("{$name} was accepted");
            } catch (LogicException $e) {
                self::assertStringContainsString("can't start with '_' or contain '__'", $e->getMessage());
            }
        }
    }

    public function test_fields_need_a_type(): void
    {
        $this->fields("['name' => 'titel'],");

        $this->expectException(LogicException::class);
        BlockFields::group('contentFaq');
    }

    private function fields(string $list): void
    {
        file_put_contents("{$this->theme}/blocks/contentFaq/fields.php", "<?php\n\nreturn [\n{$list}\n];\n");
    }
}
