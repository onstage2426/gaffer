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
        $tokens = ['/', '--fill=#qty=3', '--width=390', '--click', '#add', '--scroll=400', '--select=select[name=sort]=price:asc', '--hover=.tab.active', '--click=.drawer [aria-label=Close]'];

        self::assertSame(
            [['fill' => '#qty', 'value' => '3'], ['click' => '#add'], ['scroll' => '400'], ['select' => 'select[name=sort]', 'value' => 'price:asc'], ['hover' => '.tab.active'], ['click' => '.drawer [aria-label=Close]']],
            RenderScreenshot::steps($tokens),
        );
    }

    public function test_fill_splits_at_the_first_equals_sign_outside_brackets_and_quotes(): void
    {
        self::assertSame([['fill' => 'input[name=email]', 'value' => 'a@b.nl']], RenderScreenshot::steps(['--fill=input[name=email]=a@b.nl']));
        self::assertSame(['fill' => 'input[name="a=b"]', 'value' => 'x=y'], RenderScreenshot::fill('input[name="a=b"]=x=y'));
        self::assertSame(['fill' => "input[value='a]=b']", 'value' => '3'], RenderScreenshot::fill("input[value='a]=b']=3"));
        self::assertSame(['fill' => '#qty', 'value' => 'a=b'], RenderScreenshot::fill('#qty=a=b'));
        self::assertSame(['fill' => '#qty', 'value' => ''], RenderScreenshot::fill('#qty='));
        self::assertSame(['fill' => '#a\\=b', 'value' => '1'], RenderScreenshot::fill('#a\\=b=1'));
        self::assertSame(['fill' => '[data-x=1] input', 'value' => "O'Brien"], RenderScreenshot::fill("[data-x=1] input=O'Brien"));
    }

    public function test_fill_needs_an_equals_sign_outside_the_selector(): void
    {
        $this->expectException(InvalidArgumentException::class);

        RenderScreenshot::fill('input[name="a=b"]');
    }

    public function test_steps_as_json(): void
    {
        self::assertSame(
            [['click' => '.menu'], ['fill' => 'input[name="q"]', 'value' => 'a=b'], ['hover' => 'nav a'], ['scroll' => '#faq']],
            RenderScreenshot::json_steps('[{"click":".menu"},{"fill":"input[name=\\"q\\"]","value":"a=b"},{"hover":"nav a"},{"scroll":"#faq"}]'),
        );
        $this->expectException(InvalidArgumentException::class);
        RenderScreenshot::json_steps('[{"press":"x"}]');
    }

    public function test_widths(): void
    {
        self::assertSame([390, 1440], BrowserPage::widths('390,1440'));
        self::assertSame(1440, BrowserPage::width('1440'));
        foreach (['390,1440', 'wide', '100', ''] as $bad) {
            try {
                BrowserPage::width($bad);
                self::fail("accepted {$bad}");
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString($bad === '390,1440' ? 'one width' : 'between 200 and 3840', $e->getMessage());
            }
        }
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
        self::assertMatchesRegularExpression('/^winkel-s-shirt-[0-9a-f]{8}$/', BrowserPage::slug('/winkel/?s=Shirt'));
        self::assertNotSame(BrowserPage::slug('/a-b/'), BrowserPage::slug('/a/b/'));
        self::assertLessThanOrEqual(69, strlen(BrowserPage::slug('/' . str_repeat('long-path/', 40))));
    }
}
