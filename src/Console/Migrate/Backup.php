<?php

declare(strict_types=1);

namespace Gaffer\Console\Migrate;

use Gaffer\Gaffer;
use Gaffer\Paths;
use Gaffer\Storage;
use JsonException;
use RuntimeException;

/**
 * The content of every location before a migration, in uploads/gaffer/gaffer/backups/migrate/
 * (never committed: it's site content; before 2026-11-27 in uploads/gaffer/backups/migrate/, still
 * read). migrate:rollback restores from it.
 */
final class Backup
{
    /** The file's shape; a newer one is refused (written by a newer Gaffer), files without it are 1. */
    public const int FORMAT = 1;

    public static function dir(): string
    {
        return Storage::gaffer_dir('backups/migrate');
    }

    /** Where backups were written before 2026-11-27: read too, never written. */
    private static function dir_before(): string
    {
        return Paths::data() . '/backups/migrate';
    }

    /**
     * Writes the backup and reads it back; throws instead of returning a file that isn't exact.
     *
     * @param list<array{location: Location, before: ?string, after: ?string, summary: string}> $plan
     */
    public static function save(string $command, array $plan): string
    {
        Storage::gaffer_dir('backups/migrate');

        try {
            $json = json_encode([
                'format' => self::FORMAT,
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
     * @return array{command: string, created: string, site: ?string, locations: list<array{kind: string, id: int, label: string, owner?: int, name?: string, before: ?string, before_sha1: ?string, after_sha1: ?string}>}
     */
    public static function load(string $file): array
    {
        $name = basename($file, '.json') . '.json';
        $path = is_file($file) ? $file : (is_file(self::dir() . "/{$name}") ? self::dir() . "/{$name}" : self::dir_before() . "/{$name}");
        $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        if (!is_array($data) || !is_string($data['command'] ?? null) || !is_string($data['created'] ?? null) || !is_array($data['locations'] ?? null)) {
            throw new RuntimeException("{$file} is not a migration backup.");
        }
        if (!is_int($data['format'] ?? 1) || ($data['format'] ?? 1) > self::FORMAT) {
            throw new RuntimeException("{$file} was written by a newer Gaffer (backup format " . json_encode($data['format']) . '): update Gaffer to restore it.');
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

        return ['command' => $data['command'], 'created' => $data['created'], 'site' => is_string($data['site'] ?? null) ? $data['site'] : null, 'locations' => $locations];
    }

    /** @return list<string> backup files, newest first */
    public static function all(): array
    {
        $files = [...glob(self::dir() . '/*.json') ?: [], ...glob(self::dir_before() . '/*.json') ?: []];
        usort($files, static fn(string $a, string $b): int => strcmp(basename($b), basename($a))); // names start with the time

        return $files;
    }
}
