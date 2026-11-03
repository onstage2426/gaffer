<?php

declare(strict_types=1);

namespace Gaffer\Search;

use Gaffer\Config;
use Gaffer\Types\Post;

/**
 * The theme's indexes (config/search.php `indexes`) as the engine's definitions: Gaffer's own
 * keys (the card and its variants, the theme's `document` method) taken out, a `document`
 * callable that adds the theme's fields and renders the cards into each document, and a `version`
 * that changes with the definition, the card's templates and the files of those methods.
 *
 * @internal
 */
final class Indexes
{
    /** Keys Gaffer handles itself; every other key goes to the engine as it is. */
    public const array OWN_KEYS = ['cards', 'card_context', 'card_variants', 'card_variant', 'document'];

    /** Bumped when Gaffer renders cards differently, so indexes rebuild. */
    private const string RENDERING = '2';

    /** @return array<string, array<string, mixed>> the config's indexes */
    public static function all(): array
    {
        $indexes = [];
        foreach ((array) Config::get('search.indexes') as $name => $definition) {
            if (is_array($definition)) {
                $indexes[(string) $name] = $definition;
            }
        }

        return $indexes;
    }

    /** @return array<string, mixed>|null */
    public static function get(string $index): ?array
    {
        return self::all()[$index] ?? null;
    }

    /**
     * @param array<string, array<string, mixed>> $indexes
     * @return array<string, array<string, mixed>>
     */
    public static function definitions(array $indexes): array
    {
        $definitions = [];
        foreach ($indexes as $name => $definition) {
            $engine = array_diff_key($definition, array_flip(self::OWN_KEYS));
            $fields = $definition['document'] ?? null;
            if (self::templates($definition) !== [] || is_callable($fields)) {
                $engine['document'] = static function (array $document, string $index) use ($fields): array {
                    $id = (int) ($document['id'] ?? 0);
                    $post = is_callable($fields) && $id > 0 ? Post::from($id) : null;

                    return [...$document, ...($post !== null ? (array) $fields($post, $document) : []), ...Cards::fields($index, $id)];
                };
            }
            $engine['version'] = self::version($definition);
            $definitions[$name] = $engine;
        }

        return $definitions;
    }

    /**
     * The document field with the card this visitor gets in this view (a key of `cards`; null:
     * the first, the default): "card" or "card_{view}", with "_{variant}" when the index's
     * card_variant names one of its card_variants. Null for an index without cards or that view.
     */
    public static function card_field(string $index, ?string $view = null): ?string
    {
        $definition = self::get($index) ?? [];
        $templates = self::templates($definition);
        $view ??= array_key_first($templates);
        if ($view === null || !isset($templates[$view])) {
            return null;
        }

        return self::field($definition, $view, self::variant($definition));
    }

    /**
     * The card templates of an index (`cards`: view => template), the default first.
     *
     * @param array<string, mixed> $definition
     * @return array<string, string>
     */
    public static function templates(array $definition): array
    {
        $templates = [];
        foreach (is_array($definition['cards'] ?? null) ? $definition['cards'] : [] as $view => $template) {
            if (is_string($template) && is_string($view) && $view !== '') {
                $templates[$view] = $template;
            }
        }

        return $templates;
    }

    /**
     * The document field of a view's card for a variant (or none): "card" for the default view,
     * "card_{view}" for the others, with "_{variant}".
     *
     * @param array<string, mixed> $definition
     */
    public static function field(array $definition, string $view, ?string $variant): string
    {
        return 'card' . ($view !== array_key_first(self::templates($definition)) ? "_{$view}" : '') . ($variant !== null ? "_{$variant}" : '');
    }

    /**
     * The visitor's card variant, or null for the default card.
     *
     * @param array<string, mixed> $definition
     */
    public static function variant(array $definition): ?string
    {
        $pick = $definition['card_variant'] ?? null;
        $variant = is_callable($pick) ? $pick() : null;

        return is_string($variant) && isset(((array) ($definition['card_variants'] ?? []))[$variant]) ? $variant : null;
    }

    /**
     * Changes with the definition, the card's templates and the files of the methods that shape
     * a document (card_context, card_variants, document: what the documents hold; card_variant
     * only picks one).
     *
     * @param array<string, mixed> $definition
     */
    private static function version(array $definition): string
    {
        $cards = array_map(Cards::version(...), self::templates($definition));
        $plain = array_diff_key($definition, array_flip(['card_context', 'card_variant', 'document']));
        $plain['card_variants'] = array_keys((array) ($definition['card_variants'] ?? []));
        $methods = [$definition['card_context'] ?? null, $definition['document'] ?? null, ...array_values((array) ($definition['card_variants'] ?? []))];

        return md5((string) json_encode([self::RENDERING, $plain, $cards, Cards::code_version($methods)], JSON_PARTIAL_OUTPUT_ON_ERROR));
    }
}
