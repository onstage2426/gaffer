<?php

declare(strict_types=1);

namespace Gaffer\Tests;

use Gaffer\BlockFields;
use Gaffer\Console\Baseline;
use Gaffer\Console\Migrate\Backup;
use Gaffer\FieldGroups;
use Gaffer\HealthReport;
use Gaffer\Paths;
use Gaffer\Purchases;
use PHPUnit\Framework\TestCase as BaseTestCase;
use ReflectionClassConstant;

/**
 * What Gaffer's rules and formats leave in databases and files a site keeps, compared with the
 * committed tests/stored.txt: block names from folders, the field keys ACF stores next to every
 * value, what the purchase guard keeps on carts and orders, and the version numbers of the files
 * Gaffer reads back. All frozen for 1.x (API.md, "Data and conventions"): a change here orphans
 * stored data on every site, so it fails until the snapshot is updated on purpose
 * (`UPDATE_STORED=1 composer test`) and comes with a migration that reads the old form.
 */
final class StoredTest extends BaseTestCase
{
    private const string SNAPSHOT = __DIR__ . '/stored.txt';

    private string $theme;

    protected function setUp(): void
    {
        $this->theme = sys_get_temp_dir() . '/gaffer-stored-' . bin2hex(random_bytes(4));
        mkdir("{$this->theme}/blocks/contentFaq", 0755, true);
        mkdir("{$this->theme}/fields/post_type", 0755, true);
        file_put_contents("{$this->theme}/blocks/contentFaq/block.json", '{"title": "FAQ"}');
        $fields = <<<'PHP'
            <?php return [
                ['label' => 'Velden', 'type' => 'tab'],
                ['name' => 'titel', 'type' => 'text'],
                ['label' => 'Let op', 'type' => 'message'],
                ['name' => 'vragen', 'type' => 'repeater', 'sub_fields' => [['name' => 'vraag', 'type' => 'text'], ['name' => 'antwoord', 'type' => 'wysiwyg']]],
                ['name' => 'kop', 'type' => 'group', 'sub_fields' => [['name' => 'tekst', 'type' => 'text']]],
            ];
            PHP;
        file_put_contents("{$this->theme}/blocks/contentFaq/fields.php", $fields);
        file_put_contents("{$this->theme}/fields/post_type/review.php", $fields);
        Paths::set_base($this->theme);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->theme));
    }

    public function test_stored_derivations_match_the_snapshot(): void
    {
        $stored = self::stored();

        if (getenv('UPDATE_STORED') === '1') {
            file_put_contents(self::SNAPSHOT, $stored);
        }

        self::assertFileExists(self::SNAPSHOT, 'Create it with: UPDATE_STORED=1 composer test');
        self::assertSame(
            (string) file_get_contents(self::SNAPSHOT),
            $stored,
            'What Gaffer stores changed: every site has the old form in its database or files. Read both (API.md, "Data and conventions"), then update the snapshot on purpose: UPDATE_STORED=1 composer test',
        );
    }

    private static function stored(): string
    {
        $lines = ['# Block names: blocks/{folder}/ → the name post content stores'];
        foreach (['contentFaq', 'content-faq', 'heroHome', 'herohome', 'slider-top10', 'faqBlock'] as $folder) {
            $lines[] = "{$folder} → " . BlockFields::folder_name($folder);
        }

        $lines[] = '';
        $lines[] = '# Block field keys (blocks/contentFaq/fields.php): stored next to every value in post content';
        $block = BlockFields::group('contentFaq');
        $lines[] = 'group ' . $block['key'];
        array_push($lines, ...self::keys($block['fields']));

        $lines[] = '';
        $lines[] = '# Field group keys (fields/post_type/review.php): stored in postmeta, termmeta and options';
        $group = FieldGroups::group('post_type/review');
        $lines[] = 'group ' . $group['key'];
        array_push($lines, ...self::keys($group['fields']));

        $lines[] = '';
        $lines[] = '# The purchase guard: the price a customer saw';
        $lines[] = 'cart item key ' . new ReflectionClassConstant(Purchases::class, 'PRICE')->getValue();
        $lines[] = 'order item meta ' . new ReflectionClassConstant(Purchases::class, 'ORDER_PRICE')->getValue();

        $lines[] = '';
        $lines[] = '# Files Gaffer reads back (a newer version is refused, older ones are read)';
        $lines[] = 'uploads/gaffer/gaffer/backups/migrate/*.json (before 2026-11-27 uploads/gaffer/backups/migrate/, read) format ' . Backup::FORMAT;
        $lines[] = Baseline::FILE . ' version ' . Baseline::VERSION;
        $lines[] = 'health --json format ' . HealthReport::FORMAT;

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param array<array-key, mixed> $fields
     * @return list<string>
     */
    private static function keys(array $fields, string $indent = ''): array
    {
        $lines = [];
        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }
            $lines[] = $indent . (string) ($field['type'] ?? '?') . ' ' . (string) ($field['key'] ?? '?');
            if (is_array($field['sub_fields'] ?? null)) {
                array_push($lines, ...self::keys($field['sub_fields'], "{$indent}  "));
            }
        }

        return $lines;
    }
}
