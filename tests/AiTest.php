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
