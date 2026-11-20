<?php

declare(strict_types=1);

namespace Gaffer\Tests;

use Gaffer\Search\Archive;

final class ArchiveInitialTest extends TestCase
{
    public function test_the_initial_results_leave_the_cards_out_and_survive_bad_utf8(): void
    {
        $results = ['query' => "bad \xB1", 'total' => 1, 'results' => [['id' => 7, 'card' => '<li>card</li>']], 'facets' => []];
        $archive = new Archive(true, '/search.php', 'T', '', 1, [new \Twig\Markup('<li>card</li>', 'UTF-8')], $results, [], '', 'card');

        $json = (string) $archive->initial();
        $data = json_decode($json, true);

        self::assertSame([['id' => 7]], $data['results']);
        self::assertSame("bad \u{FFFD}", $data['query']);
        self::assertStringNotContainsString('<', $json);
    }
}
