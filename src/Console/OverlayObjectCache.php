<?php

declare(strict_types=1);

namespace Gaffer\Console;

/**
 * The live site's persistent object cache for a staged copy of the theme: the copy reads what
 * live has cached (so it sees what live sees, not stale values in the database a persistent cache
 * never reads: one site's copy lost six product attributes to a pre-Redis
 * `wc_attribute_taxonomies` transient). What the copy writes stays in this process (its values,
 * e.g. WooCommerce's template paths without the theme in the key, never reach live's cache), and
 * the key is deleted from live's cache, so live reads its source again: an option the copy's
 * build updated in the database isn't hidden from live behind an old cached value. A flush
 * never reaches live's cache. Wraps the drop-in's `$wp_object_cache` (WordPress::as_this_theme()).
 *
 * @internal
 */
final class OverlayObjectCache
{
    /** @var array<string, mixed> key => value written in this process */
    private array $written = [];

    /** @var array<string, true> keys deleted in this process */
    private array $deleted = [];

    /** @var array<string, true> groups flushed in this process ('' = all) */
    private array $flushed = [];

    /** @var array<string, true> */
    private array $global_groups = [];

    private int $blog = 0;

    public function __construct(private readonly object $inner)
    {
    }

    /** Puts the overlay in front of the drop-in's cache, once. */
    public static function install(): void
    {
        $cache = $GLOBALS['wp_object_cache'] ?? null;
        if (is_object($cache) && !$cache instanceof self && \wp_using_ext_object_cache()) {
            $overlay = new self($cache);
            $overlay->blog = function_exists('get_current_blog_id') ? \get_current_blog_id() : 0;
            // Core added its global groups (users, site-options, …) before the overlay existed:
            // WP_Object_Cache keeps them as name => true, drop-ins often as a list of names.
            $read = \Closure::bind(static fn(object $c): mixed => $c->global_groups ?? null, null, $cache::class);
            $groups = $read($cache);
            foreach (is_array($groups) ? $groups : [] as $name => $value) {
                $overlay->global_groups[is_string($name) && $value === true ? $name : (string) $value] = true;
            }
            $GLOBALS['wp_object_cache'] = $overlay;
        }
    }

    public function get(int|string $key, mixed $group = '', mixed $force = false, mixed &$found = null): mixed
    {
        $id = $this->id($key, $group);
        if (array_key_exists($id, $this->written)) {
            $found = true;
            return is_object($this->written[$id]) ? clone $this->written[$id] : $this->written[$id];
        }
        if (isset($this->deleted[$id]) || isset($this->flushed['']) || isset($this->flushed[$this->group($group)])) {
            $found = false;
            return false;
        }
        if (!method_exists($this->inner, 'get')) {
            $found = false;
            return false;
        }

        return $this->inner->get($key, $group, $force, $found);
    }

    /**
     * @param array<int|string> $keys
     * @return array<int|string, mixed>
     */
    public function get_multiple(array $keys, mixed $group = '', mixed $force = false): array
    {
        $values = [];
        foreach ($keys as $key) {
            $values[$key] = $this->get($key, $group, $force);
        }

        return $values;
    }

    public function set(int|string $key, mixed $data, mixed $group = '', mixed $expire = 0): bool
    {
        $id = $this->id($key, $group);
        $this->written[$id] = is_object($data) ? clone $data : $data;
        unset($this->deleted[$id]);
        $this->forget($key, $group);

        return true;
    }

    /** Drops the key from live's cache: live reads it from its source next time. */
    private function forget(int|string $key, mixed $group): void
    {
        if (method_exists($this->inner, 'delete')) {
            $this->inner->delete($key, $group);
        }
    }

    /**
     * @param array<int|string, mixed> $data
     * @return array<int|string, bool>
     */
    public function set_multiple(array $data, mixed $group = '', mixed $expire = 0): array
    {
        $done = [];
        foreach ($data as $key => $value) {
            $done[$key] = $this->set($key, $value, $group, $expire);
        }

        return $done;
    }

    public function add(int|string $key, mixed $data, mixed $group = '', mixed $expire = 0): bool
    {
        $this->get($key, $group, false, $found);

        return !$found && $this->set($key, $data, $group, $expire);
    }

    /**
     * @param array<int|string, mixed> $data
     * @return array<int|string, bool>
     */
    public function add_multiple(array $data, mixed $group = '', mixed $expire = 0): array
    {
        $done = [];
        foreach ($data as $key => $value) {
            $done[$key] = $this->add($key, $value, $group, $expire);
        }

        return $done;
    }

    public function replace(int|string $key, mixed $data, mixed $group = '', mixed $expire = 0): bool
    {
        $this->get($key, $group, false, $found);

        return $found && $this->set($key, $data, $group, $expire);
    }

    public function delete(int|string $key, mixed $group = '', bool $deprecated = false): bool
    {
        $id = $this->id($key, $group);
        $this->get($key, $group, false, $found);
        unset($this->written[$id]);
        $this->deleted[$id] = true;
        $this->forget($key, $group);

        return (bool) $found;
    }

    /**
     * @param array<int|string> $keys
     * @return array<int|string, bool>
     */
    public function delete_multiple(array $keys, mixed $group = ''): array
    {
        $done = [];
        foreach ($keys as $key) {
            $done[$key] = $this->delete($key, $group);
        }

        return $done;
    }

    /**
     * A counter this process wrote counts on here. Live's counter (a plugin's cache generation)
     * counts on in live's cache: that's what live does with it, and deleting it instead would
     * restart it at 1, bringing back entries cached under an old generation.
     */
    public function incr(int|string $key, mixed $offset = 1, mixed $group = ''): int|false
    {
        $id = $this->id($key, $group);
        if (array_key_exists($id, $this->written)) {
            $value = max(0, (is_numeric($this->written[$id]) ? (int) $this->written[$id] : 0) + (int) $offset);
            $this->written[$id] = $value;

            return $value;
        }
        if (isset($this->deleted[$id]) || isset($this->flushed['']) || isset($this->flushed[$this->group($group)]) || !method_exists($this->inner, 'incr')) {
            return false;
        }

        return $this->inner->incr($key, (int) $offset, $group);
    }

    public function decr(int|string $key, mixed $offset = 1, mixed $group = ''): int|false
    {
        $id = $this->id($key, $group);
        if (!array_key_exists($id, $this->written) && method_exists($this->inner, 'decr')
            && !isset($this->deleted[$id]) && !isset($this->flushed['']) && !isset($this->flushed[$this->group($group)])) {
            return $this->inner->decr($key, (int) $offset, $group);
        }

        return $this->incr($key, -(int) $offset, $group);
    }

    public function flush(): bool
    {
        $this->written = [];
        $this->deleted = [];
        $this->flushed = ['' => true];

        return true;
    }

    public function flush_group(mixed $group): bool
    {
        $group = $this->group($group);
        foreach (array_keys($this->written) as $id) {
            if (str_starts_with($id, $this->prefix($group))) {
                unset($this->written[$id]);
            }
        }
        $this->flushed[$group] = true;

        return true;
    }

    /** Forgets what this process read (the drop-in's memory); what it wrote stays. */
    public function flush_runtime(): bool
    {
        if (method_exists($this->inner, 'flush_runtime')) {
            $this->inner->flush_runtime();
        }

        return true;
    }

    /** @param string|list<string> $groups */
    public function add_global_groups(string|array $groups): void
    {
        foreach ((array) $groups as $group) {
            $this->global_groups[$group] = true;
        }
        if (method_exists($this->inner, 'add_global_groups')) {
            $this->inner->add_global_groups($groups);
        }
    }

    public function switch_to_blog(int $blog_id): void
    {
        $this->blog = $blog_id;
        if (method_exists($this->inner, 'switch_to_blog')) {
            $this->inner->switch_to_blog($blog_id);
        }
    }

    /**
     * Everything else (stats, add_non_persistent_groups, close, a drop-in's own methods) as the
     * drop-in has it.
     *
     * @param array<mixed> $arguments
     */
    public function __call(string $name, array $arguments): mixed
    {
        return $this->inner->$name(...$arguments);
    }

    public function __get(string $name): mixed
    {
        return $this->inner->$name;
    }

    public function __set(string $name, mixed $value): void
    {
        $this->inner->$name = $value;
    }

    public function __isset(string $name): bool
    {
        return isset($this->inner->$name);
    }

    /** WordPress's own cache takes any group (null from a plugin): '' and null are 'default'. */
    private function group(mixed $group): string
    {
        $group = is_scalar($group) ? (string) $group : '';

        return $group !== '' ? $group : 'default';
    }

    private function prefix(string $group): string
    {
        return (isset($this->global_groups[$group]) ? '' : $this->blog) . ":{$group}:";
    }

    private function id(int|string $key, mixed $group): string
    {
        $group = $this->group($group);

        return $this->prefix($group) . $key;
    }
}
