<?php

declare(strict_types=1);

namespace Gaffer\Console\Upgrade\Rules;

use Gaffer\Console\Upgrade\Rule;
use Gaffer\Console\Upgrade\Theme;

/**
 * CHANGELOG 2026-11-27: Forms\FormAction is Gaffer's own (final, internal). A subclass with code
 * of its own is by hand (an empty one is 2026-11-23-form-action-folder's to remove).
 *
 * @internal
 */
final class FormActionFinal extends Rule
{
    #[\Override]
    public function id(): string
    {
        return '2026-11-27-form-action-final';
    }

    #[\Override]
    public function describe(): string
    {
        return 'Forms\FormAction is final: a theme\'s own form action extends AjaxAction';
    }

    #[\Override]
    public function changes(Theme $theme): array
    {
        return [];
    }

    #[\Override]
    public function by_hand(Theme $theme): array
    {
        $todo = [];
        foreach ($theme->files(['app', 'inc'], ['php']) as $path) {
            $code = (string) $theme->read($path);
            if (preg_match('/extends\s+\\\\?(?:Gaffer\\\\Forms\\\\)?FormAction\b/', $code) === 1 && !FormActionFolder::only_repeats($code)) {
                $todo[] = "{$path} extends Forms\\FormAction, which is final now: extend Gaffer\\AjaxAction and call Forms\\Spam::check() first (CHANGELOG 2026-11-27).";
            }
        }

        return $todo;
    }
}
