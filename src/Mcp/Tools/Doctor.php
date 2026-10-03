<?php

declare(strict_types=1);

namespace Gaffer\Mcp\Tools;

use Gaffer\Mcp\GafferCli;
use Gaffer\Mcp\Tool;
use WP_Error;

final class Doctor implements Tool
{
    #[\Override]
    public function name(): string
    {
        return 'doctor';
    }

    #[\Override]
    public function label(): string
    {
        return 'Doctor';
    }

    #[\Override]
    public function description(): string
    {
        return 'Runs `php gaffer doctor --json` (with wp=true: `--wp`, WordPress checks plus rendering ~30 URLs with '
            . 'strict variables, takes 10-30 s) and returns its findings: level (error/warning/info), check, message, '
            . 'file, line, hint. Run after changing templates, blocks, ajax actions, types or config; fix errors and '
            . 'look at warnings.';
    }

    #[\Override]
    public function input_schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'wp' => ['type' => 'boolean', 'default' => true, 'description' => 'Also the WordPress checks and the render test (slower)'],
            ],
        ];
    }

    #[\Override]
    public function run(array $input): array|WP_Error
    {
        $output = GafferCli::run(['doctor', '--json', ...(($input['wp'] ?? true) ? ['--wp'] : [])]);
        $findings = json_decode(substr($output, (int) strpos($output, '[')), true);
        if (!is_array($findings)) {
            return new WP_Error('gaffer_doctor', 'doctor did not return JSON: ' . mb_substr($output, 0, 2000));
        }

        $counts = array_count_values(array_column($findings, 'level'));

        return ['errors' => $counts['error'] ?? 0, 'warnings' => $counts['warning'] ?? 0, 'info' => $counts['info'] ?? 0, 'findings' => $findings];
    }

    #[\Override]
    public function available(): bool
    {
        return true;
    }
}
