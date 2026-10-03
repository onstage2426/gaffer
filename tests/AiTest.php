<?php

declare(strict_types=1);

namespace Gaffer\Tests;

use Gaffer\Ai\Installer;
use Gaffer\Ai\Signature;
use Gaffer\Config;
use Gaffer\Paths;
use Gaffer\View;
use InvalidArgumentException;
use ReflectionFunction;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class AiTest extends TestCase
{
    private string $theme;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->theme = sys_get_temp_dir() . '/gaffer-ai-' . bin2hex(random_bytes(4));
        mkdir("{$this->theme}/.ai/guidelines", 0755, true);
        mkdir("{$this->theme}/.ai/skills/site-deploy", 0755, true);
        mkdir("{$this->theme}/config", 0755, true);

        file_put_contents("{$this->theme}/style.css", "/*\nTheme Name: Demo Theme\n*/\n");
        file_put_contents("{$this->theme}/config/theme.php", "<?php return ['debug' => false, 'colour' => 'x'];");
        file_put_contents("{$this->theme}/.ai/guidelines/views.md", "## Views (theme override)\n");
        file_put_contents("{$this->theme}/.ai/guidelines/shop.md", "## Shop rules\n");
        file_put_contents("{$this->theme}/.ai/skills/site-deploy/SKILL.md", "---\nname: site-deploy\ndescription: x\n---\n");
        file_put_contents("{$this->theme}/AGENTS.md", "My own notes above.\n\n<!-- gaffer:start -->\nold\n<!-- gaffer:end -->\n\nAnd below.\n");
        file_put_contents("{$this->theme}/.gitignore", "vendor/\n");

        Paths::set_base($this->theme);
        Config::load("{$this->theme}/config");
        self::reset(View::class, 'env', null);
        View::set_env(new Environment(new ArrayLoader([])));
    }

    #[\Override]
    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->theme));
    }

    public function test_writes_guidelines_keeping_content_outside_the_markers(): void
    {
        Installer::update(['claude'], []);
        $agents = (string) file_get_contents("{$this->theme}/AGENTS.md");

        self::assertStringStartsWith("My own notes above.\n\n<!-- gaffer:start -->\n# Demo Theme — AI guidelines", $agents);
        self::assertStringEndsWith("<!-- gaffer:end -->\n\nAnd below.\n", $agents);
        self::assertStringNotContainsString("\nold\n", $agents);
    }

    public function test_existing_file_without_markers_is_kept(): void
    {
        file_put_contents("{$this->theme}/CLAUDE.md", "# Hand-written notes\n");

        $written = Installer::update(['claude'], []);

        self::assertSame("# Hand-written notes\n\n<!-- gaffer:start -->\n@AGENTS.md\n<!-- gaffer:end -->\n", file_get_contents("{$this->theme}/CLAUDE.md"));
        self::assertStringContainsString('kept its existing content', implode("\n", $written));
    }

    public function test_theme_guidelines_override_and_extend_gaffers(): void
    {
        Installer::update(['claude'], []);
        $agents = (string) file_get_contents("{$this->theme}/AGENTS.md");

        self::assertStringContainsString('## Gaffer', $agents);                // core.md from Gaffer
        self::assertStringContainsString('## Views (theme override)', $agents); // views.md replaced
        self::assertStringNotContainsString('## Views (Twig)', $agents);
        self::assertStringContainsString('## Shop rules', $agents);             // extra theme file
        self::assertStringNotContainsString('## WooCommerce', $agents);         // plugin not active
        self::assertStringContainsString("`theme.colour`", $agents);            // generated reference
    }

    public function test_plugin_guidelines_only_when_active(): void
    {
        Installer::update(['codex'], ['woocommerce']);

        self::assertStringContainsString('## WooCommerce', (string) file_get_contents("{$this->theme}/AGENTS.md"));
    }

    public function test_plugin_skills_only_when_active(): void
    {
        Installer::update(['claude'], []);
        self::assertFileDoesNotExist("{$this->theme}/.claude/skills/gaffer-form");
        self::assertStringNotContainsString('## Forms', (string) file_get_contents("{$this->theme}/AGENTS.md"));

        Installer::update(['claude'], ['gravityforms']);
        self::assertFileExists("{$this->theme}/.claude/skills/gaffer-form/SKILL.md");
        self::assertStringContainsString('## Forms', (string) file_get_contents("{$this->theme}/AGENTS.md"));

        Installer::update(['claude'], []);
        self::assertFileDoesNotExist("{$this->theme}/.claude/skills/gaffer-form"); // plugin deactivated
    }

    public function test_mcp_config_keeps_other_servers_and_is_removed_again(): void
    {
        file_put_contents("{$this->theme}/.mcp.json", json_encode(['mcpServers' => ['other' => ['command' => 'x']]]));
        $launch = ['command' => 'wp', 'args' => ['--path=/srv/wp', 'mcp-adapter', 'serve', '--server=gaffer', '--user=1']];

        Installer::update(['claude'], ['mcp-adapter'], $launch);
        $config = json_decode((string) file_get_contents("{$this->theme}/.mcp.json"), true);

        self::assertSame(['type' => 'stdio', ...$launch], $config['mcpServers']['gaffer']);
        self::assertSame(['command' => 'x'], $config['mcpServers']['other']);
        self::assertStringContainsString("/.mcp.json\n", (string) file_get_contents("{$this->theme}/.gitignore"));
        self::assertStringContainsString('## MCP server', (string) file_get_contents("{$this->theme}/AGENTS.md"));

        Installer::update(['claude'], []); // plugin deactivated
        self::assertSame(['mcpServers' => ['other' => ['command' => 'x']]], json_decode((string) file_get_contents("{$this->theme}/.mcp.json"), true));
        self::assertStringNotContainsString('.mcp.json', (string) file_get_contents("{$this->theme}/.gitignore"));
    }

    public function test_codex_and_grok_get_a_toml_table_next_to_their_own_settings(): void
    {
        mkdir("{$this->theme}/.codex", 0755, true);
        $own = "model = \"gpt-6\"\n\n[mcp_servers.other]\ncommand = \"x\"\n";
        file_put_contents("{$this->theme}/.codex/config.toml", $own);
        $launch = ['command' => 'wp', 'args' => ['mcp-adapter', 'serve', '--server=gaffer']];

        Installer::update(['codex', 'grok'], [], $launch);
        Installer::update(['codex', 'grok'], [], $launch); // replaced, not added twice
        $table = "[mcp_servers.gaffer]\ncommand = \"wp\"\nargs = [\"mcp-adapter\", \"serve\", \"--server=gaffer\"]\n";

        self::assertSame($own . "\n" . $table, file_get_contents("{$this->theme}/.codex/config.toml"));
        self::assertSame($table, file_get_contents("{$this->theme}/.grok/config.toml"));
        self::assertStringContainsString("/.codex/config.toml\n/.grok/skills/\n/.grok/config.toml\n", (string) file_get_contents("{$this->theme}/.gitignore"));

        Installer::update(['codex', 'grok'], []); // MCP no longer available
        self::assertSame($own, file_get_contents("{$this->theme}/.codex/config.toml"));
        self::assertFileDoesNotExist("{$this->theme}/.grok/config.toml");
    }

    public function test_mcp_config_written_only_by_gaffer_is_deleted(): void
    {
        Installer::update(['claude'], [], ['command' => 'wp', 'args' => []]);
        self::assertFileExists("{$this->theme}/.mcp.json");

        Installer::clear();
        self::assertFileDoesNotExist("{$this->theme}/.mcp.json");
    }

    public function test_claude_imports_agents_md(): void
    {
        Installer::update(['claude'], []);

        self::assertSame("<!-- gaffer:start -->\n@AGENTS.md\n<!-- gaffer:end -->\n", file_get_contents("{$this->theme}/CLAUDE.md"));
        self::assertFileDoesNotExist("{$this->theme}/.agents");
    }

    public function test_dev_notes_are_imported_when_present(): void
    {
        file_put_contents("{$this->theme}/AGENTS_DEV.md", "# Dev notes\n");

        Installer::update(['claude'], []);

        self::assertStringContainsString("@AGENTS.md\n@AGENTS_DEV.md\n", (string) file_get_contents("{$this->theme}/CLAUDE.md"));
        self::assertStringContainsString('Also read `AGENTS_DEV.md`', (string) file_get_contents("{$this->theme}/AGENTS.md"));
    }

    public function test_skills_are_copied_per_agent_and_stale_ones_removed(): void
    {
        mkdir("{$this->theme}/.claude/skills/my-own", 0755, true);
        file_put_contents("{$this->theme}/.claude/skills/my-own/SKILL.md", 'mine');
        mkdir("{$this->theme}/.claude/skills/gone", 0755, true);
        file_put_contents("{$this->theme}/.claude/skills/gone/.gaffer-generated", '');

        Installer::update(['claude', 'codex'], []);

        foreach (['.claude/skills', '.agents/skills'] as $dir) {
            self::assertFileExists("{$this->theme}/{$dir}/gaffer-block/SKILL.md");
            self::assertFileExists("{$this->theme}/{$dir}/site-deploy/SKILL.md");
        }
        self::assertFileDoesNotExist("{$this->theme}/.claude/skills/gone");
        self::assertSame('mine', file_get_contents("{$this->theme}/.claude/skills/my-own/SKILL.md"));
    }

    public function test_gitignore_block_follows_the_agents(): void
    {
        Installer::update(['claude', 'grok'], []);
        Installer::update(['claude'], []);
        $ignore = (string) file_get_contents("{$this->theme}/.gitignore");

        self::assertStringStartsWith("vendor/\n\n# gaffer:ai", $ignore);
        self::assertSame(1, substr_count($ignore, '# gaffer:ai:end'));
        self::assertStringContainsString("/AGENTS.md\n/CLAUDE.md\n/.claude/skills/\n", $ignore);
        self::assertStringNotContainsString('.grok', $ignore);
    }

    public function test_deselected_agent_output_is_removed(): void
    {
        mkdir("{$this->theme}/.agents/skills/my-own", 0755, true);
        file_put_contents("{$this->theme}/.agents/skills/my-own/SKILL.md", 'mine');
        mkdir("{$this->theme}/.claude", 0755, true);
        file_put_contents("{$this->theme}/.claude/settings.local.json", '{}');
        Installer::update(['claude', 'codex', 'grok'], []);

        $written = Installer::update(['codex'], []);

        self::assertFileDoesNotExist("{$this->theme}/CLAUDE.md");
        self::assertFileDoesNotExist("{$this->theme}/.claude/skills");   // emptied, so removed
        self::assertFileExists("{$this->theme}/.claude/settings.local.json"); // not ours
        self::assertFileDoesNotExist("{$this->theme}/.grok");             // emptied up to the theme root
        self::assertFileExists("{$this->theme}/.agents/skills/gaffer-block/SKILL.md");
        self::assertContains('removed CLAUDE.md', $written);
    }

    public function test_clear_removes_generated_output_only(): void
    {
        file_put_contents("{$this->theme}/CLAUDE.md", "# Hand-written notes\n");
        mkdir("{$this->theme}/.claude/skills/my-own", 0755, true);
        file_put_contents("{$this->theme}/.claude/skills/my-own/SKILL.md", 'mine');
        Installer::update(['claude', 'codex'], []);

        $removed = Installer::clear();

        self::assertSame("My own notes above.\n\nAnd below.\n", file_get_contents("{$this->theme}/AGENTS.md"));
        self::assertSame("# Hand-written notes\n", file_get_contents("{$this->theme}/CLAUDE.md"));
        self::assertSame(['my-own'], array_values(array_diff((array) scandir("{$this->theme}/.claude/skills"), ['.', '..'])));
        self::assertFileDoesNotExist("{$this->theme}/.agents");
        self::assertFileExists("{$this->theme}/.ai/skills/site-deploy/SKILL.md");
        self::assertStringContainsString('# gaffer:ai', (string) file_get_contents("{$this->theme}/.gitignore"));
        self::assertSame([], Installer::clear());
        self::assertCount(4, $removed);
    }

    public function test_unknown_agent_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Installer::update(['claude', 'copilot'], []);
    }

    public function test_signature(): void
    {
        $fn = static fn(int $id, ?string $note = null, array $tags = [], bool ...$flags): ?\Gaffer\Types\Image => null;

        self::assertSame('pick(int $id, ?string $note = null, array $tags = [], bool ...$flags): ?Image', Signature::of(new ReflectionFunction($fn), 'pick'));
    }
}
