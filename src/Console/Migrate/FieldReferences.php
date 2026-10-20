<?php

declare(strict_types=1);

namespace Gaffer\Console\Migrate;

/**
 * Sorts the stored references of a fields/ target for migrate:fields (pure: unit-tested).
 * A reference is rewritten to the derived key only when it is the target's own: stored on
 * that post type (or a revision of one), that taxonomy, or that options page (with its
 * post_id prefix, per language), and under the name of the field its key belongs to
 * ("vragen_0_vraag" for a repeater's sub field). Everything else is left alone and
 * reported: references outside the target, under another name (an old field name), and
 * references in the target to keys no field has.
 *
 * @internal
 */
final class FieldReferences
{
    /**
     * @param string $target "post_type/product"
     * @param array<string, array{path: string, type: string, pattern: string, title: string}> $old the UI groups' fields, by key
     * @param array<string, array{path: string, type: string, pattern: string, key: string}> $new the file's fields, by path
     * @param list<string> $prefixes options pages: the option name prefixes ("options", "options_nl"); else []
     */
    public function __construct(
        private readonly string $target,
        private readonly array $old,
        private readonly array $new,
        private readonly array $prefixes = [],
    ) {}

    /**
     * Data fields by name path ("vragen.vraag") with the pattern of their stored name:
     * a repeater's sub field is "{repeater}_{row}_{sub}", a group's "{group}_{sub}".
     * Tabs and messages have no name.
     *
     * @param list<array<string, mixed>> $fields
     * @return array<string, array{path: string, type: string, pattern: string, key: string}>
     */
    public static function paths(array $fields, string $path = '', string $pattern = ''): array
    {
        $found = [];
        foreach ($fields as $field) {
            if (!is_string($field['name'] ?? null) || $field['name'] === '') {
                continue;
            }
            $own = $path . $field['name'];
            $own_pattern = $pattern . preg_quote($field['name'], '/');
            $found[$own] = ['path' => $own, 'type' => (string) ($field['type'] ?? ''), 'pattern' => $own_pattern, 'key' => (string) ($field['key'] ?? '')];
            if (is_array($field['sub_fields'] ?? null)) {
                $child = match ($field['type'] ?? null) {
                    'repeater' => $own_pattern . '_\d+_',
                    default => $own_pattern . '_',
                };
                $found += self::paths($field['sub_fields'], "{$own}.", $child);
            }
        }

        return $found;
    }

    /**
     * @param list<Reference> $references every row holding one of the old keys, anywhere
     * @return array{
     *     rewrite: list<array{reference: Reference, to: string, path: string, post_id: int|string, field: string}>,
     *     problems: array<string, list<Reference>>,
     *     outside: list<Reference>,
     *     other_names: list<array{Reference, string}>,
     * }
     */
    public function classify(array $references): array
    {
        $sorted = ['rewrite' => [], 'problems' => [], 'outside' => [], 'other_names' => []];

        foreach ($references as $reference) {
            $old = $this->old[$reference->key] ?? null;
            $stored = $this->stored($reference);
            if ($old === null || $stored === null) {
                $sorted['outside'][] = $reference;
                continue;
            }
            [$post_id, $name] = $stored;
            if (preg_match('/^' . $old['pattern'] . '$/', $name) !== 1) {
                $sorted['other_names'][] = [$reference, $old['path']];
                continue;
            }

            $new = $this->new[$old['path']] ?? null;
            $problem = match (true) {
                $new === null => "\"{$old['path']}\" of \"{$old['title']}\" isn't in fields/{$this->target}.php: add it, or delete its values",
                $new['type'] !== $old['type'] => "\"{$old['path']}\" of \"{$old['title']}\" is a {$old['type']} field, fields/{$this->target}.php makes it a {$new['type']}",
                default => null,
            };
            if ($problem !== null) {
                $sorted['problems'][$problem][] = $reference;
                continue;
            }
            $sorted['rewrite'][] = ['reference' => $reference, 'to' => $new['key'], 'path' => $old['path'], 'post_id' => $post_id, 'field' => explode('.', $old['path'])[0]];
        }

        return $sorted;
    }

    /**
     * References in the target whose stored name is one of the file's fields but whose key
     * no field has (old or derived): a broken key, e.g. from an import.
     *
     * @param list<Reference> $references rows in the target holding a key that isn't an old or derived one
     * @return list<Reference>
     */
    public function broken(array $references): array
    {
        $broken = [];
        foreach ($references as $reference) {
            $stored = $this->stored($reference);
            if ($stored !== null && array_any($this->new, static fn(array $field): bool => preg_match('/^' . $field['pattern'] . '$/', $stored[1]) === 1)) {
                $broken[] = $reference;
            }
        }

        return $broken;
    }

    /**
     * The ACF post_id and the stored field name of a reference in this target; null when
     * it's stored elsewhere (another post type, taxonomy, options page or language prefix).
     *
     * @return array{int|string, string}|null
     */
    private function stored(Reference $reference): ?array
    {
        [$param, $value] = explode('/', $this->target, 2);
        $name = substr($reference->name, 1); // without the "_" of a reference

        return match (true) {
            $param === 'post_type' && $reference->location->kind === 'postmeta' && $reference->owner_type === $value && $reference->owner !== null => [$reference->owner, $name],
            $param === 'taxonomy' && $reference->location->kind === 'termmeta' && $reference->owner_type === $value && $reference->owner !== null => ["term_{$reference->owner}", $name],
            $param === 'options_page' && $reference->location->kind === 'option' => $this->option_name($name),
            default => null,
        };
    }

    /** @return array{string, string}|null */
    private function option_name(string $name): ?array
    {
        // The longest prefix first: "options_nl_titel" is options_nl's, not options' "nl_titel".
        $prefixes = $this->prefixes;
        usort($prefixes, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
        foreach ($prefixes as $prefix) {
            if (str_starts_with($name, "{$prefix}_")) {
                return [$prefix, substr($name, strlen($prefix) + 1)];
            }
        }

        return null;
    }
}
