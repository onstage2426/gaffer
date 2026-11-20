<?php

declare(strict_types=1);

namespace Gaffer\Console\Render;

use Gaffer\Console\Ajax\Session;
use InvalidArgumentException;

/**
 * Who a render is for: logged in as a user, with products in the WooCommerce cart, or with
 * an ajax:call session's cookies. Stored as data in snapshots (`{user, cart, session}`), so
 * render:diff renders the same way, and turned into render:page's options per render: the
 * session as its jar's path in this theme's storage, which a staged copy's render reads too.
 *
 * @internal
 */
final readonly class Visitor
{
    /** @param list<string> $cart "ID" or "ID:quantity" */
    private function __construct(
        public ?int $user = null,
        public array $cart = [],
        public ?string $session = null,
    ) {}

    public static function anonymous(): self
    {
        return new self();
    }

    /**
     * From command-line options.
     *
     * @param array<mixed> $cart
     * @throws InvalidArgumentException on a malformed user or cart item, or an unknown session
     */
    public static function from(mixed $user, array $cart = [], mixed $session = null): self
    {
        $id = null;
        if ($user !== null) {
            $id = is_int($user) ? $user : (is_string($user) && ctype_digit($user) ? (int) $user : 0);
            if ($id <= 0) {
                throw new InvalidArgumentException('--user takes a user ID, not "' . (is_scalar($user) ? (string) $user : gettype($user)) . '"');
            }
        }
        $items = [];
        foreach ($cart as $item) {
            $item = is_scalar($item) ? (string) $item : '';
            if (!preg_match('/^\d+(?::\d+)?$/', $item)) {
                throw new InvalidArgumentException("--cart takes a product ID or ID:quantity, not \"{$item}\"");
            }
            $items[] = $item;
        }
        if ($session !== null) {
            $session = is_string($session) ? $session : '';
            if (!Session::exists($session)) {
                throw new InvalidArgumentException("No session {$session}: start one with php gaffer ajax:call <action> --session={$session}");
            }
        }

        return new self($id, $items, $session);
    }

    /**
     * From a snapshot's `visitor` (whatever it holds: a snapshot is read before it's used).
     *
     * @param array<mixed> $data
     */
    public static function from_array(array $data): self
    {
        $user = is_int($data['user'] ?? null) && $data['user'] > 0 ? $data['user'] : null;
        $cart = array_values(array_filter(is_array($data['cart'] ?? null) ? $data['cart'] : [], static fn(mixed $item): bool => is_string($item) && preg_match('/^\d+(?::\d+)?$/', $item) === 1));
        $session = is_string($data['session'] ?? null) ? $data['session'] : null;

        return new self($user, $cart, $session);
    }

    /** @return array{user: ?int, cart: list<string>, session: ?string} */
    public function to_array(): array
    {
        return ['user' => $this->user, 'cart' => $this->cart, 'session' => $this->session];
    }

    public function is_anonymous(): bool
    {
        return $this->user === null && $this->cart === [] && $this->session === null;
    }

    /** As the command line that would render the same way, e.g. "--user=3 --cart=65:2". */
    public function label(): string
    {
        return implode(' ', [
            ...($this->user !== null ? ["--user={$this->user}"] : []),
            ...array_map(static fn(string $item): string => "--cart={$item}", $this->cart),
            ...($this->session !== null ? ["--session={$this->session}"] : []),
        ]);
    }

    /**
     * render:page's options for this visitor. The session is passed as its jar's path, resolved
     * here: the render may run in a staged copy, whose own storage has no such jar.
     *
     * @return list<string>
     */
    public function arguments(): array
    {
        return [
            ...($this->user !== null ? ["--user={$this->user}"] : []),
            ...array_map(static fn(string $item): string => "--cart={$item}", $this->cart),
            ...($this->session !== null ? ['--session-file=' . Session::file($this->session)] : []),
        ];
    }

    /**
     * The session's cookies (for the browser too), none without one.
     *
     * @return array<string, string>
     */
    public function cookies(): array
    {
        return $this->session !== null ? Session::load($this->session)->cookies() : [];
    }
}
