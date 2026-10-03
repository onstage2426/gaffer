<?php

declare(strict_types=1);

namespace Gaffer\Ai;

/**
 * An AI coding agent and where it reads guidelines and skills. Every agent
 * reads the shared AGENTS.md, directly or through its own file that imports it.
 * Paths follow Laravel Boost's adapters.
 */
final readonly class Agent
{
    /**
     * @param string|null $file   Own guidelines file (written as an `@AGENTS.md` import); null = reads AGENTS.md itself
     * @param string|null $skills Skills directory (Agent Skills format: {name}/SKILL.md)
     * @param list<string> $markers Files/directories in a project that show the agent is used
     * @param string|null $mcp    Project MCP config (JSON with "mcpServers") that gets Gaffer's server; null = not supported here
     */
    public function __construct(
        public string $name,
        public string $label,
        public ?string $file,
        public ?string $skills,
        public string $command,
        public array $markers,
        public ?string $mcp = null,
    ) {}

    /** @return array<string, Agent> */
    public static function all(): array
    {
        return [
            'claude' => new self('claude', 'Claude Code', 'CLAUDE.md', '.claude/skills', 'claude', ['.claude', 'CLAUDE.md'], '.mcp.json'),
            'codex' => new self('codex', 'OpenAI Codex', null, '.agents/skills', 'codex', ['.codex']),
            'grok' => new self('grok', 'Grok', null, '.grok/skills', 'grok', ['.grok']),
        ];
    }

    /**
     * Its command is installed, or the project already has its files.
     */
    public function detected(string $root): bool
    {
        foreach ($this->markers as $marker) {
            if (file_exists("{$root}/{$marker}")) {
                return true;
            }
        }

        return trim((string) shell_exec('command -v ' . escapeshellarg($this->command) . ' 2>/dev/null')) !== '';
    }

    /**
     * Paths this agent's output adds to the theme (all generated, all gitignored).
     *
     * @return list<string>
     */
    public function generated(bool $mcp): array
    {
        return array_values(array_filter([$this->file, $this->skills !== null ? "{$this->skills}/" : null, $mcp ? $this->mcp : null]));
    }
}
