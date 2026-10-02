<?php

declare(strict_types=1);

namespace Gaffer\Console\Migrate;

use Gaffer\Gaffer;
use Gaffer\Paths;
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
     * @param list<array{location: Location, before: string, after: string, summary: string}> $plan
     */
    public static function save(string $command, array $plan): string
    {
        if (!is_dir(self::dir()) && !mkdir(self::dir(), 0775, true) && !is_dir(self::dir())) {
            throw new RuntimeException('Could not create ' . self::dir());
        }
        $ignore = dirname(self::dir()) . '/.gitignore';
        if (!is_file($ignore)) {
            file_put_contents($ignore, "# Site content: never commit.\n*\n");
        }

        try {
            $json = json_encode([
                'command' => $command,
                'created' => gmdate('c'),
                'site' => home_url(),
                'gaffer' => Gaffer::version(),
                'locations' => array_map(static fn(array $change): array => [
                    ...$change['location']->to_array(),
                    'before' => $change['before'],
                    'before_sha1' => sha1($change['before']),
                    'after_sha1' => sha1($change['after']),
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
     * @return array{command: string, created: string, locations: list<array{kind: string, id: int, label: string, before: string, before_sha1: string, after_sha1: string}>}
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
            if (!is_array($location) || !in_array($location['kind'] ?? null, ['post', 'widget'], true) || !is_int($location['id'] ?? null)
                || !is_string($location['label'] ?? null) || !is_string($location['before'] ?? null)
                || !is_string($location['before_sha1'] ?? null) || !is_string($location['after_sha1'] ?? null)
                || sha1($location['before']) !== $location['before_sha1']) {
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
