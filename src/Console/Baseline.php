<?php

declare(strict_types=1);

namespace Gaffer\Console;

use Gaffer\Paths;
use RuntimeException;

/**
 * Warnings doctor already knows about (doctor-baseline.json in the theme root,
 * committed): `doctor --baseline` writes the current ones, later runs hide them so
 * new warnings stand out. A warning matches by check, file and message (not line:
 * lines shift); each entry has a count. Errors are never baselined.
 *
 * @phpstan-type Entry array{check: string, file: ?string, message: string, count: int}
 */
final class Baseline
{
    public const string FILE = 'doctor-baseline.json';

    /** @param list<Entry> $entries */
    public function __construct(
        public readonly bool $wp,
        public readonly array $entries,
    ) {}

    public static function file(): string
    {
        return Paths::base(self::FILE);
    }

    public static function load(): ?self
    {
        if (!is_file(self::file())) {
            return null;
        }
        $data = json_decode((string) file_get_contents(self::file()), true);
        if (!is_array($data) || !is_array($data['warnings'] ?? null)) {
            throw new RuntimeException(self::FILE . " isn't a baseline Gaffer can read: write it again with php gaffer doctor --baseline");
        }

        $entries = [];
        foreach ($data['warnings'] as $entry) {
            $entries[] = [
                'check' => (string) ($entry['check'] ?? ''),
                'file' => is_string($entry['file'] ?? null) ? $entry['file'] : null,
                'message' => (string) ($entry['message'] ?? ''),
                'count' => max(1, (int) ($entry['count'] ?? 1)),
            ];
        }

        return new self((bool) ($data['wp'] ?? false), $entries);
    }

    /**
     * @param list<array{level: string, check: string, message: string, file: ?string, line: ?int, hint: ?string}> $findings
     */
    public static function from(array $findings, bool $wp): self
    {
        $entries = [];
        foreach ($findings as $f) {
            if ($f['level'] !== 'warning') {
                continue;
            }
            $key = self::key($f);
            $entries[$key] ??= ['check' => $f['check'], 'file' => $f['file'], 'message' => $f['message'], 'count' => 0];
            $entries[$key]['count']++;
        }
        ksort($entries);

        return new self($wp, array_values($entries));
    }

    public function save(): void
    {
        $json = json_encode(['wp' => $this->wp, 'warnings' => $this->entries], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false || file_put_contents(self::file(), $json . "\n") === false) {
            throw new RuntimeException('Could not write ' . self::file());
        }
    }

    public function count(): int
    {
        return array_sum(array_column($this->entries, 'count'));
    }

    /**
     * Splits warnings into new ones and known ones; also how many known ones no longer occur.
     *
     * @param list<array{level: string, check: string, message: string, file: ?string, line: ?int, hint: ?string}> $findings
     * @return array{findings: list<array{level: string, check: string, message: string, file: ?string, line: ?int, hint: ?string}>, known: int, gone: int}
     */
    public function apply(array $findings): array
    {
        $left = [];
        foreach ($this->entries as $entry) {
            $left[self::key($entry)] = $entry['count'];
        }

        $kept = [];
        $known = 0;
        foreach ($findings as $f) {
            $key = self::key($f);
            if ($f['level'] === 'warning' && ($left[$key] ?? 0) > 0) {
                $left[$key]--;
                $known++;
                continue;
            }
            $kept[] = $f;
        }

        return ['findings' => $kept, 'known' => $known, 'gone' => array_sum($left)];
    }

    /** @param array{check: string, file: ?string, message: string} $finding */
    private static function key(array $finding): string
    {
        return "{$finding['check']}\0{$finding['file']}\0{$finding['message']}";
    }
}
