<?php

declare(strict_types=1);

namespace Gaffer\Tests;

use Gaffer\AjaxAction;
use Gaffer\AjaxArguments;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;

final class AjaxArgumentsTest extends TestCase
{
    private static function action(): AjaxAction
    {
        return new class extends AjaxAction {
            /** @param array<mixed> $tags */
            public function run(
                int $id,
                string $name = 'default',
                float $price = 0.0,
                bool $gift = false,
                array $tags = [],
                ?int $parent = null,
                ?string $note = null,
            ): void {}
        };
    }

    public function test_required_and_defaults(): void
    {
        self::assertSame(['id' => 5], AjaxArguments::resolve(self::action(), ['id' => '5']));
    }

    public function test_missing_required_is_bad_input(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AjaxArguments::resolve(self::action(), ['name' => 'x']);
    }

    public function test_unknown_input_keys_are_ignored(): void
    {
        self::assertSame(['id' => 1], AjaxArguments::resolve(self::action(), ['id' => '1', 'action' => 'X', 'evil' => 'y']));
    }

    /** @return iterable<string, array{string, mixed, mixed}> */
    public static function valid(): iterable
    {
        yield 'int from string' => ['id', '42', 42];
        yield 'int with spaces' => ['id', ' 7 ', 7];
        yield 'negative int' => ['id', '-3', -3];
        yield 'string' => ['name', 'Katoen', 'Katoen'];
        yield 'string from number' => ['name', 5, '5'];
        yield 'empty string stays empty' => ['name', '', ''];
        yield 'float' => ['price', '19.95', 19.95];
        yield 'float from int string' => ['price', '20', 20.0];
        yield 'bool 1' => ['gift', '1', true];
        yield 'bool true' => ['gift', 'true', true];
        yield 'bool on' => ['gift', 'on', true];
        yield 'bool yes' => ['gift', 'yes', true];
        yield 'bool 0' => ['gift', '0', false];
        yield 'bool false' => ['gift', 'false', false];
        yield 'bool off' => ['gift', 'off', false];
        yield 'bool no' => ['gift', 'no', false];
        yield 'bool empty' => ['gift', '', false];
        yield 'array' => ['tags', ['a', 'b'], ['a', 'b']];
        yield 'nullable int empty → null' => ['parent', '', null];
        yield 'nullable int value' => ['parent', '9', 9];
        yield 'nullable string empty stays empty' => ['note', '', ''];
    }

    #[DataProvider('valid')]
    public function test_casts_valid_input(string $key, mixed $value, mixed $expected): void
    {
        $args = AjaxArguments::resolve(self::action(), ['id' => '1', $key => $value]);

        self::assertSame($expected, $args[$key]);
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function invalid(): iterable
    {
        yield 'int from text' => ['id', 'abc'];
        yield 'int from decimal' => ['id', '5.5'];
        yield 'int from empty' => ['id', ''];
        yield 'int from array' => ['id', ['1']];
        yield 'string from array' => ['name', ['x']];
        yield 'float from text' => ['price', 'cheap'];
        yield 'bool from text' => ['gift', 'maybe'];
        yield 'bool from array' => ['gift', ['1']];
        yield 'array from string' => ['tags', 'a,b'];
        yield 'nullable int from text' => ['parent', 'x'];
    }

    #[DataProvider('invalid')]
    public function test_rejects_invalid_input(string $key, mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        AjaxArguments::resolve(self::action(), ['id' => '1', $key => $value]);
    }

    public function test_untyped_parameter_is_a_bug_in_the_action(): void
    {
        $action = new class extends AjaxAction {
            public function run(mixed $anything): void {}
        };

        $this->expectException(LogicException::class);
        AjaxArguments::resolve($action, ['anything' => 'x']);
    }

    public function test_union_and_variadic_parameters_are_reported(): void
    {
        $union = new class extends AjaxAction {
            public function run(int|string $id): void {}
        };
        $variadic = new class extends AjaxAction {
            public function run(string ...$ids): void {}
        };

        self::assertNotNull(AjaxArguments::problem(AjaxArguments::run_method($union)));
        self::assertNotNull(AjaxArguments::problem(AjaxArguments::run_method($variadic)));
        self::assertNull(AjaxArguments::problem(AjaxArguments::run_method(self::action())));
    }

    public function test_action_without_run_is_a_bug(): void
    {
        $action = new class extends AjaxAction {};

        $this->expectException(LogicException::class);
        AjaxArguments::resolve($action, []);
    }
}
