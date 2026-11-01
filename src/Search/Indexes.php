<?php

declare(strict_types=1);

namespace Gaffer\Search;

use Gaffer\Config;

/**
 * The theme's indexes (config/search.php `indexes`) as the engine's definitions: Gaffer's own
 * keys (the card and its variants) taken out, a `document` callable that renders the cards into
 * each document and a `version` that changes with the definition and the card's templates added.
 *
 * @internal
 */
final class Indexes
{
    /** Keys Gaffer handles itself; every other key goes to the engine as it is. */
    public const array OWN_KEYS = ['card', 'card_context', 'card_variants', 'card_variant'];

    /** Bumped when Gaffer renders cards differently, so indexes rebuild. */
    private const string RENDERING = '1';

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
            if (is_string($definition['card'] ?? null)) {
                $engine['document'] = static fn(array $document, string $index): array => [...$document, ...Cards::fields($index, (int) ($document['id'] ?? 0))];
            }
            $engine['version'] = self::version($definition);
            $definitions[$name] = $engine;
        }

        return $definitions;
    }

    /**
     * The document field with the card this visitor gets: "card", or "card_{variant}" when the
     * index's card_variant names one of its card_variants. Null for an index without a card.
     */
    public static function card_field(string $index): ?string
    {
        $definition = self::get($index);
        if (!is_string($definition['card'] ?? null)) {
            return null;
        }
        $variant = self::variant($definition);

        return $variant !== null ? "card_{$variant}" : 'card';
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

    /** @param array<string, mixed> $definition */
    private static function version(array $definition): string
    {
        $card = is_string($definition['card'] ?? null) ? $definition['card'] : null;
        $plain = array_diff_key($definition, array_flip(['card_context', 'card_variant']));
        $plain['card_variants'] = array_keys((array) ($definition['card_variants'] ?? []));

        return md5((string) json_encode([self::RENDERING, $plain, $card !== null ? Cards::version($card) : null], JSON_PARTIAL_OUTPUT_ON_ERROR));
    }
}
