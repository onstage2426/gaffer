<?php

declare(strict_types=1);

namespace Gaffer\Console\Upgrade\Rules;

use Gaffer\Console\Upgrade\Change;
use Gaffer\Console\Upgrade\Php;
use Gaffer\Console\Upgrade\Rule;
use Gaffer\Console\Upgrade\Theme;
use PhpParser\Node;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;

/**
 * CHANGELOG 2026-11-25: in-canvas previews are gone: `theme.block_previews` and the
 * `assets/css/editor.css` Vite entry go. The stylesheet itself and an editor-only font hook are
 * the theme's to delete (by hand: what else uses them isn't certain).
 *
 * @internal
 */
final class EditorStylesheet extends Rule
{
    private const string ENTRY = '/\s*["\']assets\/css\/editor\.css["\']\s*,?(?=\s*[\]\}\n])/';

    #[\Override]
    public function id(): string
    {
        return '2026-11-25-editor-stylesheet';
    }

    #[\Override]
    public function describe(): string
    {
        return 'config/theme.php block_previews and the assets/css/editor.css Vite entry removed';
    }

    #[\Override]
    public function changes(Theme $theme): array
    {
        $changes = [];
        $config = $theme->read('config/theme.php');
        if ($config !== null && str_contains($config, 'block_previews')) {
            $after = Php::edit($config, new class extends NodeVisitorAbstract {
                #[\Override]
                public function leaveNode(Node $node): ?int
                {
                    return $node instanceof Node\ArrayItem && $node->key instanceof Node\Scalar\String_ && $node->key->value === 'block_previews'
                        ? NodeVisitor::REMOVE_NODE : null;
                }
            });
            if ($after !== null && $after !== $config) {
                $changes[] = new Change('config/theme.php', $config, $after);
            }
        }
        foreach (['vite.config.js', 'vite.config.mjs', 'vite.config.ts'] as $path) {
            $code = $theme->read($path);
            if ($code !== null && preg_match(self::ENTRY, $code) === 1) {
                $changes[] = new Change($path, $code, (string) preg_replace(self::ENTRY, '', $code, 1));
            }
        }

        return $changes;
    }

    #[\Override]
    public function by_hand(Theme $theme): array
    {
        return $theme->read('assets/css/editor.css') !== null
            ? ['assets/css/editor.css isn\'t loaded any more: delete it (and an enqueue_block_assets hook that only served the editor), keeping a theme.css partial app.css imports.']
            : [];
    }
}
