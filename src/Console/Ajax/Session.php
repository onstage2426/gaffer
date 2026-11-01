<?php

declare(strict_types=1);

namespace Gaffer\Console\Ajax;

use Gaffer\Storage;
use InvalidArgumentException;

/**
 * A visitor's cookies kept under a name between CLI calls (ajax:call --session), so a
 * sequence of ajax actions runs as one visitor: the WooCommerce session and cart, a form's
 * state. render:screenshot and doctor:render take the same name. One site, so a cookie is
 * its name and value; expired ones are dropped. Stored in storage/sessions/{name}.json.
 *
 * @internal
 */
final class Session
{
    /** @param array<string, array{value: string, expires: ?int}> $cookies */
    private function __construct(
        public readonly string $name,
        private array $cookies,
    ) {}

    /** @throws InvalidArgumentException on a name that isn't letters, digits and dashes */
    public static function load(string $name): self
    {
        $data = is_file($file = self::file($name)) ? json_decode((string) file_get_contents($file), true) : null;
        $cookies = [];
        foreach (is_array($data['cookies'] ?? null) ? $data['cookies'] : [] as $cookie => $value) {
            if (is_string($cookie) && is_string($value['value'] ?? null)) {
                $cookies[$cookie] = ['value' => $value['value'], 'expires' => is_int($value['expires'] ?? null) ? $value['expires'] : null];
            }
        }

        return new self($name, $cookies);
    }

    public static function exists(string $name): bool
    {
        return is_file(self::file($name));
    }

    /**
     * Cookies a response set. An empty value, "deleted" or an expiry in the past removes one,
     * as a browser does.
     *
     * @param list<array{name: string, value: string, expires: ?int}> $cookies
     */
    public function update(array $cookies, ?int $now = null): void
    {
        $now ??= time();
        foreach ($cookies as $cookie) {
            if ($cookie['value'] === '' || $cookie['value'] === 'deleted' || ($cookie['expires'] !== null && $cookie['expires'] <= $now)) {
                unset($this->cookies[$cookie['name']]);
            } else {
                $this->cookies[$cookie['name']] = ['value' => $cookie['value'], 'expires' => $cookie['expires']];
            }
        }
    }

    /** @return array<string, string> the cookies not expired yet, by name */
    public function cookies(?int $now = null): array
    {
        $now ??= time();
        $live = array_filter($this->cookies, static fn(array $c): bool => $c['expires'] === null || $c['expires'] > $now);

        return array_map(static fn(array $c): string => $c['value'], $live);
    }

    /**
     * The WooCommerce session IDs in this visitor's cookies (wp_woocommerce_session_*:
     * "customer_id|expiration|expiring|hash"), for ajax:end to delete.
     *
     * @return list<string>
     */
    public function woocommerce_customers(): array
    {
        $ids = [];
        foreach ($this->cookies() as $cookie => $value) {
            if (str_starts_with($cookie, 'wp_woocommerce_session_')) {
                $id = (string) preg_split('/\|\|?/', urldecode($value))[0];
                if ($id !== '') {
                    $ids[] = $id;
                }
            }
        }

        return $ids;
    }

    public function save(): void
    {
        file_put_contents(self::file($this->name), (string) json_encode(['cookies' => $this->cookies], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }

    public function forget(): void
    {
        if (is_file($file = self::file($this->name))) {
            unlink($file);
        }
    }

    private static function file(string $name): string
    {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9-]*$/', $name)) {
            throw new InvalidArgumentException("A session name is letters, digits and dashes, not \"{$name}\"");
        }

        return Storage::private_dir('sessions') . "/{$name}.json";
    }
}
