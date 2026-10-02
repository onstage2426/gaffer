<?php

declare(strict_types=1);

namespace Gaffer\Tests;

use Gaffer\Console\Templates;
use Gaffer\View;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class TemplatesTest extends TestCase
{
    /** @param array<string, string> $templates */
    private static function env(array $templates): void
    {
        self::reset(View::class, 'env', null);
        View::set_env(new Environment(new ArrayLoader($templates)));
    }

    public function test_variables_read_from_the_caller(): void
    {
        self::env(['t.twig' => <<<'TWIG'
            {% set heading = title|upper %}
            {% for item in items %}{{ loop.index }} {{ item.name }} {{ prefix }}{% endfor %}
            {{ heading }} {{ subtitle ?? '' }} {{ note|default('x') }}
            {% if extra is defined %}{{ extra }}{% endif %}
            {{ nav_primary }}
            TWIG]);

        $variables = Templates::variables(Templates::parse('t.twig'), ['nav_primary']);

        self::assertSame([
            'extra' => false,   // read outside the "is defined" test too
            'items' => false,
            'note' => true,
            'prefix' => false,
            'subtitle' => true,
            'title' => false,
        ], $variables);
    }

    public function test_references_and_isolation(): void
    {
        self::env(['t.twig' => <<<'TWIG'
            {{ include('a.twig', { x: 1 }, with_context = false) }}
            {{ include('b.twig') }}
            {{ include('c/' ~ name) }}
            {% include 'd.twig' with { y: 2 } only %}
            {{ source('icons/e.svg') }}
            TWIG]);

        $refs = Templates::references(Templates::parse('t.twig'));

        self::assertSame(
            [['a.twig', 'include()', true], ['b.twig', 'include()', false], [null, 'include()', false], ['d.twig', '{% include %}', true], ['icons/e.svg', 'source()', true]],
            array_map(static fn(array $r): array => [$r['template'], $r['kind'], $r['isolated']], $refs),
        );
        self::assertSame([1, 2, 3, 4, 5], array_column($refs, 'line'));
    }

    public function test_a_template_that_does_not_compile_is_null(): void
    {
        self::env(['t.twig' => '{% if %}']);

        self::assertNull(Templates::parse('t.twig'));
    }
}
