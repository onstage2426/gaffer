<?php

declare(strict_types=1);

namespace Gaffer\Console\Upgrade\Rules;

use Gaffer\Console\Upgrade\Change;
use Gaffer\Console\Upgrade\Php;
use Gaffer\Console\Upgrade\Rule;
use Gaffer\Console\Upgrade\Theme;
use PhpParser\Node;
use PhpParser\NodeFinder;

/** CHANGELOG 2026-11-23: Gaffer answers forms itself; an app/Ajax/Form/ that only repeats it goes. @internal */
final class FormActionFolder extends Rule
{
    private const string PATH = 'app/Ajax/Form/Form.php';

    #[\Override]
    public function id(): string
    {
        return '2026-11-23-form-action';
    }

    #[\Override]
    public function describe(): string
    {
        return 'app/Ajax/Form/: removed when it only repeats Gaffer\'s form action';
    }

    #[\Override]
    public function changes(Theme $theme): array
    {
        $code = $theme->read(self::PATH);
        if ($code === null || !self::only_repeats($code)) {
            return [];
        }

        return [new Change(self::PATH, $code, null)];
    }

    /** One class, extending Gaffer\Forms\FormAction, with nothing in it. */
    public static function only_repeats(string $code): bool
    {
        $stmts = Php::parse($code);
        if ($stmts === null) {
            return false;
        }
        $classes = new NodeFinder()->findInstanceOf($stmts, Node\Stmt\ClassLike::class);
        if (count($classes) !== 1 || !$classes[0] instanceof Node\Stmt\Class_ || $classes[0]->extends === null) {
            return false;
        }
        $parent = $classes[0]->extends->getAttribute('resolvedName');

        return $parent instanceof Node\Name && $parent->toString() === 'Gaffer\\Forms\\FormAction' && $classes[0]->stmts === [];
    }
}
