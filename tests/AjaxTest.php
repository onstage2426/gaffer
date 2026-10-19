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
        spl_autoload_unregister($autoload);
    }
}
