<?php

declare(strict_types=1);

namespace Gaffer\Ai;

use FilesystemIterator;
use Gaffer\Paths;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Writes the generated guidelines and skills into the theme for the selected
 * agents, and keeps the theme's .gitignore in sync with what it writes.
 */
final class Installer
{
    private const string START = '<!-- gaffer:start -->';
    private const string END = '<!-- gaffer:end -->';

    /** Marks a skill directory as generated (so stale ones can be removed). */
    private const string SKILL_MARKER = '.gaffer-generated';

    /** Gaffer's entry in an agent's MCP config. */
    private const string MCP_SERVER = 'gaffer';

    /**
     * Writes the output for these agents and removes the output of every other agent.
     *
     * @param list<string> $agent_names
     * @param list<string> $plugins
     * @param array{command: string, args: list<string>}|null $mcp how to start Gaffer's MCP server; null = not available
     * @return list<string> what was written or removed ("wrote AGENTS.md"), paths relative to the theme
     */
    public static function update(array $agent_names, array $plugins, ?array $mcp = null): array
    {
        $agents = self::agents($agent_names);
        $written = [];

        $kept = ' (kept its existing content above the generated block: move it to .ai/guidelines/)';

        $written[] = 'wrote AGENTS.md' . (self::write_marked('AGENTS.md', Guidelines::build($plugins)) ? $kept : '');

        // AGENTS_DEV.md: hand-written notes for developing this theme itself (committed).
        $import = "@AGENTS.md\n" . (is_file(Paths::base('AGENTS_DEV.md')) ? "@AGENTS_DEV.md\n" : '');

        foreach ($agents as $agent) {
            if ($agent->file !== null) {
                $written[] = "wrote {$agent->file}" . (self::write_marked($agent->file, $import) ? $kept : '');
            }
        }

        $skills = self::skills($plugins);
        foreach ($agents as $agent) {
            if ($agent->skills !== null) {
                self::write_skills($agent->skills, $skills);
                $written[] = "wrote {$agent->skills}/ (" . count($skills) . ' skills)';
            }
        }

        foreach ($agents as $agent) {
            if ($agent->mcp !== null) {
                array_push($written, ...($mcp !== null ? self::write_mcp($agent->mcp, $mcp) : self::remove_mcp($agent->mcp)));
            }
        }

        // Deselected agents: their files would drop out of the .gitignore block and become committable.
        foreach (array_diff_key(Agent::all(), array_flip($agent_names)) as $agent) {
            array_push($written, ...self::remove_agent($agent));
        }

        self::gitignore($agents, $mcp !== null);

        return $written;
    }

    /**
     * Removes all generated output: AGENTS.md's block and every agent's files and
     * skills. Keeps .ai/, config/ai.php, the .gitignore block and hand-written content.
     *
     * @return list<string> what was removed, paths relative to the theme
     */
    public static function clear(): array
    {
        $removed = self::remove_marked('AGENTS.md');
        foreach (Agent::all() as $agent) {
            array_push($removed, ...self::remove_agent($agent));
        }

        return $removed;
    }

    /** @return list<string> */
    private static function remove_agent(Agent $agent): array
    {
        $removed = [
            ...($agent->file !== null ? self::remove_marked($agent->file) : []),
            ...($agent->mcp !== null ? self::remove_mcp($agent->mcp) : []),
        ];

        $target = $agent->skills !== null ? Paths::base($agent->skills) : null;
        $markers = $target !== null ? glob("{$target}/*/" . self::SKILL_MARKER) ?: [] : [];
        if ($target === null || $markers === []) {
            return $removed;
        }

        foreach ($markers as $marker) {
            self::remove(dirname($marker));
        }
        $removed[] = "removed {$agent->skills}/ (" . count($markers) . ' skills)';

        // Empty directories left behind (.claude/skills, then .claude), never the theme itself.
        for ($dir = $target; $dir !== Paths::base() && is_dir($dir) && (scandir($dir) ?: []) === ['.', '..']; $dir = dirname($dir)) {
            rmdir($dir);
        }

        return $removed;
    }

    /**
     * @param list<string> $names
     * @return list<Agent>
     */
    public static function agents(array $names): array
    {
        $all = Agent::all();
        $unknown = array_diff($names, array_keys($all));
        if ($unknown !== []) {
            throw new InvalidArgumentException('Unknown agent(s): ' . implode(', ', $unknown) . '. Known: ' . implode(', ', array_keys($all)) . '.');
        }

        return array_map(static fn(string $name): Agent => $all[$name], $names);
    }

    /**
     * Skill directories by name: Gaffer's (plus those of active plugins), overridden
     * or extended by the theme's .ai/skills/.
     *
     * @param list<string> $plugins
     * @return array<string, string>
     */
    public static function skills(array $plugins): array
    {
        $gaffer = self::gaffer_skills();
        $roots = [$gaffer, ...array_map(static fn(string $p): string => "{$gaffer}/plugins/{$p}", $plugins), Paths::base('.ai/skills')];

        $skills = [];
        foreach ($roots as $root) {
            foreach (glob("{$root}/*/SKILL.md") ?: [] as $file) {
                $skills[basename(dirname($file))] = dirname($file);
            }
        }
        ksort($skills);

        return $skills;
    }

    /** Gaffer's own skills; plugin skills are in plugins/{plugin}/. */
    public static function gaffer_skills(): string
    {
        return dirname(__DIR__, 2) . '/resources/ai/skills';
    }

    /** @param array<string, string> $skills */
    private static function write_skills(string $relative, array $skills): void
    {
        $target = Paths::base($relative);
        if (!is_dir($target)) {
            mkdir($target, 0755, true);
        }

        // Remove generated skills that no longer exist; leave the user's own alone.
        foreach (glob("{$target}/*/" . self::SKILL_MARKER) ?: [] as $marker) {
            if (!isset($skills[basename(dirname($marker))])) {
                self::remove(dirname($marker));
            }
        }

        foreach ($skills as $name => $source) {
            $dir = "{$target}/{$name}";
            if (is_dir($dir) && !is_file("{$dir}/" . self::SKILL_MARKER)) {
                continue; // a hand-made skill with the same name wins
            }
            self::remove($dir);
            self::copy($source, $dir);
            file_put_contents("{$dir}/" . self::SKILL_MARKER, "Generated by `php gaffer ai:update`; changes are overwritten.\n");
        }
    }

    /**
     * Writes content between the gaffer markers; anything outside them is kept.
     * A file without markers keeps its content and gets the block appended.
     *
     * @return bool whether the file had content of its own without markers
     */
    private static function write_marked(string $relative, string $content): bool
    {
        $file = Paths::base($relative);
        $block = self::START . "\n" . rtrim($content) . "\n" . self::END;
        $existing = is_file($file) ? (string) file_get_contents($file) : '';

        $start = strpos($existing, self::START);
        $end = strpos($existing, self::END);

        $marked = $start !== false && $end !== false && $end > $start;
        $result = match (true) {
            $marked => substr($existing, 0, $start) . $block . substr($existing, $end + strlen(self::END)),
            trim($existing) === '' => $block . "\n",
            default => rtrim($existing) . "\n\n" . $block . "\n",
        };

        file_put_contents($file, $result);

        return !$marked && trim($existing) !== '';
    }

    /**
     * Removes the gaffer block from a file; deletes the file when nothing else is left.
     *
     * @return list<string>
     */
    private static function remove_marked(string $relative): array
    {
        $file = Paths::base($relative);
        $existing = is_file($file) ? (string) file_get_contents($file) : '';

        $start = strpos($existing, self::START);
        $end = strpos($existing, self::END);
        if ($start === false || $end === false || $end < $start) {
            return [];
        }

        $rest = implode("\n\n", array_filter([
            rtrim(substr($existing, 0, $start)),
            ltrim(substr($existing, $end + strlen(self::END))),
        ], static fn(string $part): bool => $part !== ''));

        if (trim($rest) === '') {
            unlink($file);
            return ["removed {$relative}"];
        }

        file_put_contents($file, rtrim($rest) . "\n");

        return ["removed the generated block from {$relative} (kept the rest)"];
    }

    /**
     * Sets Gaffer's server in an agent's MCP config, keeping every other server.
     *
     * @param array{command: string, args: list<string>} $launch
     * @return list<string>
     */
    private static function write_mcp(string $relative, array $launch): array
    {
        $config = self::read_mcp($relative);
        if ($config === null) {
            return ["skipped {$relative} (not valid JSON: fix or delete it)"];
        }
        $config['mcpServers'] = [...(is_array($config['mcpServers'] ?? null) ? $config['mcpServers'] : []), self::MCP_SERVER => ['type' => 'stdio', ...$launch]];
        file_put_contents(Paths::base($relative), json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

        return ["wrote {$relative} (MCP server \"" . self::MCP_SERVER . '")'];
    }

    /**
     * Removes Gaffer's server from an agent's MCP config; deletes the file when nothing else is left.
     *
     * @return list<string>
     */
    private static function remove_mcp(string $relative): array
    {
        $config = self::read_mcp($relative);
        if (!isset($config['mcpServers']) || !is_array($config['mcpServers']) || !isset($config['mcpServers'][self::MCP_SERVER])) {
            return [];
        }
        unset($config['mcpServers'][self::MCP_SERVER]);
        if ($config['mcpServers'] === []) {
            unset($config['mcpServers']);
        }

        if ($config === []) {
            unlink(Paths::base($relative));
            return ["removed {$relative}"];
        }
        file_put_contents(Paths::base($relative), json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

        return ["removed the \"" . self::MCP_SERVER . "\" server from {$relative} (kept the rest)"];
    }

    /** @return array<string, mixed>|null the config ([] when there's no file), null when it isn't valid JSON */
    private static function read_mcp(string $relative): ?array
    {
        $file = Paths::base($relative);
        if (!is_file($file)) {
            return [];
        }
        $config = json_decode((string) file_get_contents($file), true);

        return is_array($config) ? $config : null;
    }

    /**
     * The generated paths, between markers in the theme's .gitignore.
     *
     * @param list<Agent> $agents
     */
    private static function gitignore(array $agents, bool $mcp): void
    {
        $file = Paths::base('.gitignore');
        $paths = ['/AGENTS.md'];
        foreach ($agents as $agent) {
            foreach ($agent->generated($mcp) as $path) {
                $paths[] = "/{$path}";
            }
        }

        $block = "# gaffer:ai (generated by `php gaffer ai:update`, never commit)\n" . implode("\n", array_unique($paths)) . "\n# gaffer:ai:end";
        $existing = is_file($file) ? (string) file_get_contents($file) : '';

        $result = preg_match('/^# gaffer:ai .*?^# gaffer:ai:end$/ms', $existing)
            ? (string) preg_replace('/^# gaffer:ai .*?^# gaffer:ai:end$/ms', $block, $existing)
            : rtrim($existing) . ($existing === '' ? '' : "\n\n") . $block . "\n";

        file_put_contents($file, $result);
    }

    private static function copy(string $from, string $to): void
    {
        mkdir($to, 0755, true);
        /** @var iterable<SplFileInfo> $items */
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($items as $item) {
            $path = $to . substr($item->getPathname(), strlen($from));
            $item->isDir() ? mkdir($path, 0755, true) : copy($item->getPathname(), $path);
        }
    }

    private static function remove(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        /** @var iterable<SplFileInfo> $items */
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
