<?php

declare(strict_types=1);

namespace Gaffer\Console\Upgrade\Rules;

use Gaffer\Console\Upgrade\Change;
use Gaffer\Console\Upgrade\Rule;
use Gaffer\Console\Upgrade\Theme;

/**
 * CHANGELOG 2026-11-27: functions.php loads vendor/autoload.php only when ajax.php or the CLI
 * hasn't already. Loaded twice in one request across `update`'s switch of the vendor link, it
 * would come from two real folders and Composer's class would be declared twice (a fatal,
 * measured on ajax requests during switches). @internal
 */
final class AutoloadOnce extends Rule
{
    private const string LINE = '/^(?:require|require_once)\s*\(?\s*__DIR__\s*\.\s*([\'"])\/vendor\/autoload\.php\1\s*\)?\s*;[ \t]*$/m';

    #[\Override]
    public function id(): string
    {
        return '2026-11-27-autoload-once';
    }

    #[\Override]
    public function describe(): string
    {
        return 'functions.php: vendor/autoload.php only when Gaffer isn\'t loaded yet (ajax.php and the CLI load it first)';
    }

    #[\Override]
    public function changes(Theme $theme): array
    {
        $code = $theme->read('functions.php');
        if ($code === null || !str_contains($code, 'Gaffer::boot(') || !preg_match(self::LINE, $code, $m)) {
            return [];
        }
        $q = $m[1];
        $after = (string) preg_replace(self::LINE, "// ajax.php and the CLI load it first (php gaffer update may have switched vendor/ since)\nif (!class_exists(\\Gaffer\\Gaffer::class, false)) {\n    require __DIR__ . {$q}/vendor/autoload.php{$q};\n}", $code, 1);

        return [new Change('functions.php', $code, $after)];
    }

    #[\Override]
    public function by_hand(Theme $theme): array
    {
        $code = (string) $theme->read('functions.php');
        if (str_contains($code, 'vendor/autoload.php') && !str_contains($code, 'Gaffer\Gaffer::class, false)') && !preg_match(self::LINE, $code)) {
            return ['functions.php loads vendor/autoload.php in a way the rule doesn\'t know: load it only when `class_exists(\Gaffer\Gaffer::class, false)` is false (CHANGELOG 2026-11-27).'];
        }

        return [];
    }
}
