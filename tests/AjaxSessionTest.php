<?php

declare(strict_types=1);

namespace Gaffer\Tests;

use Gaffer\Console\Ajax\Session;
use Gaffer\Console\Commands\AjaxCall;
use Gaffer\Paths;
use InvalidArgumentException;

final class AjaxSessionTest extends TestCase
{
    private string $theme;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->theme = sys_get_temp_dir() . '/gaffer-session-' . bin2hex(random_bytes(4));
        mkdir($this->theme);
        Paths::set_base($this->theme);
    }

    #[\Override]
    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->theme));
    }

    public function test_cookies_are_kept_updated_and_removed_like_a_browser_does(): void
    {
        $session = Session::load('cart-test');
        $session->update([
            ['name' => 'a', 'value' => '1', 'expires' => null],
            ['name' => 'b', 'value' => '2', 'expires' => 2000],
            ['name' => 'c', 'value' => '3', 'expires' => null],
        ], now: 1000);
        $session->update([
            ['name' => 'a', 'value' => '9', 'expires' => null],
            ['name' => 'c', 'value' => 'deleted', 'expires' => null],
        ], now: 1000);
        $session->save();

        self::assertSame(['a' => '9', 'b' => '2'], Session::load('cart-test')->cookies(now: 1000));
        self::assertSame(['a' => '9'], Session::load('cart-test')->cookies(now: 3000), 'expired cookies are dropped');

        Session::load('cart-test')->forget();
        self::assertFalse(Session::exists('cart-test'));
    }

    public function test_woocommerce_customers_come_from_the_session_cookie(): void
    {
        $session = Session::load('wc');
        $session->update([
            ['name' => 'wp_woocommerce_session_abc', 'value' => 't_83fa%7C%7C1793%7C%7C1792%7C%7Chash', 'expires' => null],
            ['name' => 'woocommerce_items_in_cart', 'value' => '1', 'expires' => null],
        ]);

        self::assertSame(['t_83fa'], $session->woocommerce_customers());
    }

    public function test_a_session_name_is_letters_digits_and_dashes(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Session::load('../x');
    }

    public function test_params_become_a_query_string_with_array_keys(): void
    {
        self::assertSame('product=65&attributes%5Bpa_size%5D=x+l&q=a%3Db', AjaxCall::params(['product=65', 'attributes[pa_size]=x l', 'q=a=b']));
    }

    public function test_a_param_without_a_value_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AjaxCall::params(['product']);
    }
}
