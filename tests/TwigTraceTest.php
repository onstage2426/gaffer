<?php

declare(strict_types=1);

namespace Gaffer\Tests;

use Gaffer\Console\Commands\TwigTrace;
use Gaffer\Console\Templates;
use Gaffer\Gaffer;
use Gaffer\Paths;
use Gaffer\View;

final class TwigTraceTest extends TestCase
{
    public function test_renders_includes_computed_names_and_variables(): void
    {
        $theme = sys_get_temp_dir() . '/gaffer-trace-' . bin2hex(random_bytes(4));
        mkdir("{$theme}/views/cards", 0755, true);
        mkdir("{$theme}/app/Ajax/More", 0755, true);
        file_put_contents("{$theme}/views/cards/product.twig", "{{ post.title }} {{ include('price.twig', { amount: post.price }, with_context = false) }}");
        file_put_contents("{$theme}/views/price.twig", '{{ amount ?? 0 }}');
        file_put_contents("{$theme}/views/list.twig", "{% for post in posts %}\n{{ include('cards/product.twig', { post: post }, with_context = false) }}{% endfor %}");
        file_put_contents("{$theme}/page.php", "<?php\nView::render('list.twig', []);");
        file_put_contents("{$theme}/app/Ajax/More/More.php", "<?php\n\nView::render(\"cards/{\$type}.twig\", []);");
        Paths::set_base($theme);
        self::reset(View::class, 'env', null);
        self::reset(Gaffer::class, 'twig', false);
        Gaffer::twig();

        $trace = TwigTrace::trace('cards/product.twig', Templates::all());

        self::assertSame([['file' => 'app/Ajax/More/More.php', 'line' => 3, 'pattern' => 'cards/*.twig']], $trace['rendered_by']);
        self::assertSame([['template' => 'list.twig', 'line' => 2, 'kind' => 'include()', 'pattern' => null]], $trace['included_by']);
        self::assertSame(['price.twig'], array_column($trace['includes'], 'template'));
        self::assertSame(['post' => false], $trace['variables']);
        self::assertSame([], TwigTrace::trace('list.twig', Templates::all())['included_by']);
        self::assertSame('page.php', TwigTrace::trace('list.twig', Templates::all())['rendered_by'][0]['file']);

        exec('rm -rf ' . escapeshellarg($theme));
    }
}
