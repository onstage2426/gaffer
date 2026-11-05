<?php

declare(strict_types=1);

namespace Gaffer\Tests;

use Gaffer\Console\OverlayObjectCache;

/** A staged copy reads live's object cache, keeps its writes, and drops the keys it wrote from live's. */
final class OverlayObjectCacheTest extends TestCase
{
    public function test_reads_live_writes_stay_here_and_written_keys_leave_live(): void
    {
        $live = new FakeObjectCache(['default:a' => 1, 'options:alloptions' => ['x' => '1']]);
        $copy = new OverlayObjectCache($live);

        self::assertSame(1, $copy->get('a'), 'what live has cached');
        self::assertSame(['x' => '1'], $copy->get('alloptions', 'options'));

        $copy->set('template', '/themes/blueprint-stage/x.php', 'woocommerce');
        self::assertSame('/themes/blueprint-stage/x.php', $copy->get('template', 'woocommerce'));
        self::assertArrayNotHasKey('woocommerce:template', $live->data, 'the copy\'s value never reaches live');

        $copy->set('alloptions', ['x' => '2'], 'options');
        self::assertSame(['x' => '2'], $copy->get('alloptions', 'options'));
        self::assertArrayNotHasKey('options:alloptions', $live->data, 'a key the copy wrote is dropped: live reads the database again');

        self::assertFalse($copy->add('a', 2), 'add sees live\'s key');
        self::assertTrue($copy->add('b', 2));
        self::assertTrue($copy->delete('a'));
        self::assertFalse($copy->get('a', '', false, $found));
        self::assertFalse($found);
        self::assertArrayNotHasKey('default:a', $live->data);
    }

    public function test_a_flush_never_reaches_live(): void
    {
        $live = new FakeObjectCache(['default:a' => 1, 'posts:p' => 2]);
        $copy = new OverlayObjectCache($live);
        $copy->set('mine', 3);

        $copy->flush();

        self::assertFalse($copy->get('a'), 'flushed for the copy');
        self::assertFalse($copy->get('mine'));
        self::assertSame(['default:a' => 1, 'posts:p' => 2], $live->data, 'live keeps its cache');
        self::assertSame(0, $live->flushes);
    }

    public function test_incr_add_replace_and_blogs(): void
    {
        $live = new FakeObjectCache(['default:n' => 5]);
        $copy = new OverlayObjectCache($live);

        self::assertSame(6, $copy->incr('n'));
        self::assertSame(4, $copy->decr('n', 2));
        self::assertFalse($copy->incr('missing'));
        self::assertFalse($copy->replace('missing', 1));

        $copy->add_global_groups(['users']);
        $copy->set('k', 'blog 0');
        $copy->set('u', 'global', 'users');
        $copy->switch_to_blog(2);
        self::assertFalse($copy->get('k'), 'another site\'s key');
        self::assertSame('global', $copy->get('u', 'users'), 'global groups are shared');
        self::assertSame(['users'], $live->global_groups);
        self::assertSame(2, $live->blog);
    }
}

/** The drop-in's WP_Object_Cache as far as the overlay calls it: keys as "group:key". */
final class FakeObjectCache
{
    public int $flushes = 0;

    /** @var list<string> */
    public array $global_groups = [];

    public int $blog = 0;

    /** @param array<string, mixed> $data */
    public function __construct(public array $data)
    {
    }

    public function get(int|string $key, string $group = '', bool $force = false, mixed &$found = null): mixed
    {
        $id = ($group !== '' ? $group : 'default') . ":{$key}";
        $found = array_key_exists($id, $this->data);

        return $found ? $this->data[$id] : false;
    }

    public function delete(int|string $key, string $group = ''): bool
    {
        unset($this->data[($group !== '' ? $group : 'default') . ":{$key}"]);

        return true;
    }

    public function flush(): bool
    {
        $this->flushes++;
        $this->data = [];

        return true;
    }

    /** @param string|list<string> $groups */
    public function add_global_groups(string|array $groups): void
    {
        $this->global_groups = [...$this->global_groups, ...(array) $groups];
    }

    public function switch_to_blog(int $blog_id): void
    {
        $this->blog = $blog_id;
    }
}
