<?php

declare(strict_types=1);

namespace Gaffer\Console\Migrate;

use Gaffer\Gaffer;
use Gaffer\Paths;
use Gaffer\Storage;
use JsonException;
use RuntimeException;

/**
 * The content of every location before a migration, in storage/backups/migrate/
 * (never committed: it's site content). migrate:rollback restores from it.
 */
final class Backup
{
    public static function dir(): string
    {
        return Paths::storage() . '/backups/migrate';
    }

    /**
     * Writes the backup and reads it back; throws instead of returning a file that isn't exact.
     *
     * @param list<array{location: Location, before: ?string, after: ?string, summary: string}> $plan
     */
    public static function save(string $command, array $plan): string
    {
        Storage::private_dir('backups/migrate');

        try {
            $json = json_encode([
                'command' => $command,
                'created' => gmdate('c'),
                'site' => home_url(),
                'gaffer' => Gaffer::version(),
                'locations' => array_map(static fn(array $change): array => [
                    ...$change['location']->to_array(),
                    // null: the row doesn't exist (before a restore that inserts it, after a delete).
                    'before' => $change['before'],
                    'before_sha1' => $change['before'] !== null ? sha1($change['before']) : null,
                    'after_sha1' => $change['after'] !== null ? sha1($change['after']) : null,
                ], $plan),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("Could not encode the backup ({$e->getMessage()}); nothing was written.", previous: $e);
        }

        $name = gmdate('Ymd-His') . '-' . strtr((string) strtok($command, ' '), ':', '-');
        $file = self::dir() . "/{$name}.json";
        for ($i = 2; file_exists($file); $i++) {
            $file = self::dir() . "/{$name}-{$i}.json";
        }

        if (file_put_contents($file, $json, LOCK_EX) !== strlen($json) || file_get_contents($file) !== $json) {
            throw new RuntimeException("Could not write the backup {$file}; nothing was written.");
        }

        return $file;
    }

    /**
     * @return array{command: string, created: string, locations: list<array{kind: string, id: int, label: string, owner?: int, name?: string, before: ?string, before_sha1: ?string, after_sha1: ?string}>}
     */
    public static function load(string $file): array
    {
        $path = is_file($file) ? $file : self::dir() . '/' . basename($file, '.json') . '.json';
        $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        if (!is_array($data) || !is_string($data['command'] ?? null) || !is_string($data['created'] ?? null) || !is_array($data['locations'] ?? null)) {
            throw new RuntimeException("{$file} is not a migration backup.");
        }

        $locations = [];
        foreach ($data['locations'] as $location) {
            if (!is_array($location) || !in_array($location['kind'] ?? null, Location::KINDS, true) || !is_int($location['id'] ?? null)
                || !is_string($location['label'] ?? null) || !array_key_exists('before', $location) || !array_key_exists('after_sha1', $location)
                || !in_array(gettype($location['before']), ['string', 'NULL'], true) || !in_array(gettype($location['after_sha1']), ['string', 'NULL'], true)
                || (isset($location['owner']) && !is_int($location['owner'])) || (isset($location['name']) && !is_string($location['name']))
                || ($location['before'] !== null ? sha1($location['before']) : null) !== ($location['before_sha1'] ?? null)) {
                throw new RuntimeException("{$file} is damaged (a location doesn't match its checksum).");
            }
            $locations[] = $location;
        }

        return ['command' => $data['command'], 'created' => $data['created'], 'locations' => $locations];
    }

    /** @return list<string> backup files, newest first */
    public static function all(): array
    {
        $files = glob(self::dir() . '/*.json') ?: [];
        rsort($files);

        return $files;
    }
}
