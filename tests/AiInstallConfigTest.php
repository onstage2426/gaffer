<?php

declare(strict_types=1);

namespace Gaffer\Tests;

use Gaffer\Console\Commands\AiInstall;

final class AiInstallConfigTest extends TestCase
{
    public function test_an_existing_config_keeps_its_other_keys(): void
    {
        $existing = "<?php\n\n// ours\nreturn [\n    'agents' => ['claude'],\n    'mcp_production' => true, // live-site migration\n];\n";

        self::assertSame(
            "<?php\n\n// ours\nreturn [\n    'agents' => ['claude', 'codex'],\n    'mcp_production' => true, // live-site migration\n];\n",
            AiInstall::config_file(['claude', 'codex'], $existing),
        );
    }

    public function test_a_new_config_lists_the_agents(): void
    {
        self::assertStringContainsString("'agents' => ['claude'],", AiInstall::config_file(['claude']));
    }
}
