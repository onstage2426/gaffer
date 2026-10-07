<?php

declare(strict_types=1);

namespace Gaffer\Tests;

use Gaffer\Ajax;
use Gaffer\Paths;

final class AjaxTest extends TestCase
{
    public function test_actions_and_their_classes_are_autoloaded(): void
    {
        $theme = sys_get_temp_dir() . '/gaffer-ajax-' . bin2hex(random_bytes(4));
        mkdir("{$theme}/ajax/Ping", 0755, true);
        mkdir("{$theme}/ajax/Broken", 0755, true);
        Paths::set_base($theme);
        file_put_contents("{$theme}/ajax/Ping/Ping.php", "<?php\nnamespace Theme\\Ajax\\Ping;\nfinal class Ping extends \\Gaffer\\AjaxAction { use Pong; public function run(): void {} }\n");
        file_put_contents("{$theme}/ajax/Ping/Pong.php", "<?php\nnamespace Theme\\Ajax\\Ping;\ntrait Pong {}\n");
        file_put_contents("{$theme}/ajax/Broken/Broken.php", "<?php\nnamespace Theme\\Ajax\\Broken;\nfinal class Other {}\n");

        self::assertSame(['Broken', 'Ping'], Ajax::actions());
        self::assertSame('Theme\\Ajax\\Ping\\Ping', Ajax::action_class('Ping'));
        self::assertTrue(trait_exists('Theme\\Ajax\\Ping\\Pong', false));
        self::assertNull(Ajax::action_class('Broken'));
        self::assertNull(Ajax::action_class('Missing'));
    }
}
