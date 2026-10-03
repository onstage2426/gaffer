<?php

declare(strict_types=1);

namespace Gaffer\Tests;

use Gaffer\Ai\McpConfig;
use Gaffer\Mcp\Tools\BlockUsage;
use Gaffer\Mcp\Tools\LastErrors;

final class McpTest extends TestCase
{
    public function test_block_occurrences_include_nested_blocks(): void
    {
        $faq = ['blockName' => 'acf/content-faq', 'attrs' => ['data' => ['titel' => 'Vragen', '_titel' => 'field_x', 'vragen_0_vraag' => 'Wat?']], 'innerBlocks' => []];
        $blocks = [
            $faq,
            ['blockName' => 'core/group', 'attrs' => [], 'innerBlocks' => [$faq, ['blockName' => 'core/paragraph', 'attrs' => [], 'innerBlocks' => []]]],
            ['blockName' => null, 'attrs' => [], 'innerBlocks' => []],
        ];

        $found = BlockUsage::occurrences($blocks, 'acf/content-faq');

        self::assertCount(2, $found);
        self::assertSame(['titel' => 'Vragen', 'vragen_0_vraag' => 'Wat?'], BlockUsage::data($found[0]));
        self::assertSame(['level' => 2], BlockUsage::data(['blockName' => 'core/heading', 'attrs' => ['level' => 2]]));
    }

    public function test_log_entries_are_merged_and_ordered_by_last_occurrence(): void
    {
        $log = "ted line from the middle of an entry\n"
            . "[03-Oct-2026 10:00:00 UTC] PHP Warning:  A in /x.php on line 1\n"
            . "[03-Oct-2026 10:00:01 UTC] PHP Fatal error:  B\nStack trace:\n#0 {main}\n"
            . "[03-Oct-2026 10:00:02 UTC] PHP Warning:  A in /x.php on line 1\n";

        self::assertSame([
            ['message' => "PHP Fatal error:  B\nStack trace:\n#0 {main}", 'count' => 1, 'last' => '03-Oct-2026 10:00:01 UTC'],
            ['message' => 'PHP Warning:  A in /x.php on line 1', 'count' => 2, 'last' => '03-Oct-2026 10:00:02 UTC'],
        ], LastErrors::entries($log, true));
    }

    public function test_only_gaffers_toml_tables_are_removed(): void
    {
        $toml = "[mcp_servers.gaffer]\ncommand = \"wp\"\n\n[mcp_servers.gaffer.env]\nA = \"1\"\n\n"
            . "[mcp_servers.gaffer-other]\ncommand = \"y\"\n\n[profile]\nmodel = \"x\"\n";

        self::assertSame("[mcp_servers.gaffer-other]\ncommand = \"y\"\n\n[profile]\nmodel = \"x\"\n", McpConfig::without_toml_table($toml, 'mcp_servers'));
    }
}
