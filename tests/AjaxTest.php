<?php

declare(strict_types=1);

namespace Gaffer\Tests;

use Gaffer\Ajax;
use Gaffer\Paths;

final class AjaxTest extends TestCase
{
    public function test_actions_are_the_classes_in_app_ajax(): void
    {
        $theme = sys_get_temp_dir() . '/gaffer-ajax-' . bin2hex(random_bytes(4));
        mkdir("{$theme}/app/Ajax/Ping", 0755, true);
        mkdir("{$theme}/app/Ajax/Broken", 0755, true);
        Paths::set_base($theme);
        // The theme's Composer autoloader: Theme\ → app/.
        spl_autoload_register($autoload = static function (string $class) use ($theme): void {
            $file = "{$theme}/app/" . str_replace('\\', '/', substr($class, strlen('Theme\\'))) . '.php';
            if (str_starts_with($class, 'Theme\\') && is_file($file)) {
                require_once $file;
            }
        });
        file_put_contents("{$theme}/app/Ajax/Ping/Ping.php", "<?php\nnamespace Theme\\Ajax\\Ping;\nfinal class Ping extends \\Gaffer\\AjaxAction { use Pong; public function run(): void {} }\n");
        file_put_contents("{$theme}/app/Ajax/Ping/Pong.php", "<?php\nnamespace Theme\\Ajax\\Ping;\ntrait Pong {}\n");
        file_put_contents("{$theme}/app/Ajax/Broken/Broken.php", "<?php\nnamespace Theme\\Ajax\\Broken;\nfinal class Other {}\n");

        self::assertSame(['Broken', 'Ping'], Ajax::actions());
        self::assertSame('Theme\\Ajax\\Ping\\Ping', Ajax::action_class('Ping'));
        self::assertTrue(trait_exists('Theme\\Ajax\\Ping\\Pong', false));
        self::assertNull(Ajax::action_class('Broken'));
        self::assertNull(Ajax::action_class('Missing'));
        // Forms post to Form: Gaffer's own unless the theme has the file.
        self::assertSame(\Gaffer\Forms\FormAction::class, Ajax::action_class('Form'));
        mkdir("{$theme}/app/Ajax/Form", 0755, true);
        file_put_contents("{$theme}/app/Ajax/Form/Form.php", "<?php\nnamespace Theme\\Ajax\\Form;\nclass Form extends \\Gaffer\\Forms\\FormAction {}\n");
        self::assertSame('Theme\\Ajax\\Form\\Form', Ajax::action_class('Form'));

        // doctor --wp calls the GET actions that need no argument
        foreach ([
            'Count' => "public const string METHOD = 'GET'; public function run(int \$x = 1): void {}",
            'Hint' => "public const string METHOD = 'GET'; public function run(int \$id): void {}",
            'Sheet' => "public const string METHOD = 'GET'; public function run(\\Gaffer\\Types\\Post \$post, ?\\Gaffer\\Types\\Term \$term = null): void {}",
            'Sized' => "public const string METHOD = 'GET'; public function run(\\Gaffer\\Types\\Post \$post, string \$size): void {}",
        ] as $name => $body) {
            mkdir("{$theme}/app/Ajax/{$name}");
            file_put_contents("{$theme}/app/Ajax/{$name}/{$name}.php", "<?php\nnamespace Theme\\Ajax\\{$name};\nfinal class {$name} extends \\Gaffer\\AjaxAction { {$body} }\n");
        }
        // Ping is POST, Hint needs an id, Sized a string; Sheet gets a post (its term is optional)
        self::assertSame(['Count' => [], 'Sheet' => ['post' => \Gaffer\Types\Post::class]], \Gaffer\Console\Checks\ContractsCheck::callable_actions());
        spl_autoload_unregister($autoload);
    }
}
