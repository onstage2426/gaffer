<?php

declare(strict_types=1);

namespace Gaffer\Tests;

use Gaffer\Console\Commands\RenderScreenshot;
use Gaffer\Console\Render\BrowserPage;
use InvalidArgumentException;

final class RenderScreenshotTest extends TestCase
{
    public function test_steps_keep_the_command_line_order(): void
    {
        $tokens = ['/', '--fill=#qty=3', '--width=390', '--click', '#add', '--hover=.tab.active', '--click=.drawer [aria-label=Close]'];

        self::assertSame(
            [['fill' => '#qty', 'value' => '3'], ['click' => '#add'], ['hover' => '.tab.active'], ['click' => '.drawer [aria-label=Close]']],
            RenderScreenshot::steps($tokens),
        );
    }

    public function test_fill_splits_at_the_last_equals_sign(): void
    {
        self::assertSame([['fill' => 'input[name=email]', 'value' => 'a@b.nl']], RenderScreenshot::steps(['--fill=input[name=email]=a@b.nl']));
    }

    public function test_fill_without_a_value_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        RenderScreenshot::steps(['--fill=#qty']);
    }

    public function test_local_storage_splits_at_the_first_equals_sign(): void
    {
        self::assertSame(['vat' => 'incl', 'prefs' => '{"a":"b=c"}'], RenderScreenshot::storage(['vat=incl', 'prefs={"a":"b=c"}']));
        $this->expectException(InvalidArgumentException::class);
        RenderScreenshot::storage(['vat']);
    }

    public function test_slug(): void
    {
        self::assertSame('home', BrowserPage::slug('/'));
        self::assertSame('winkel-s-shirt', BrowserPage::slug('/winkel/?s=Shirt'));
    }
}
