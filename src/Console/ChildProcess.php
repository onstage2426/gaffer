<?php

declare(strict_types=1);

namespace Gaffer\Console;

/**
 * Runs a command to its end with a time limit, reading stdout and stderr together (one full
 * pipe can't block the other: a child that writes a lot to stderr would otherwise wait
 * forever for a reader of stdout).
 *
 * @internal
 */
final class ChildProcess
{
    /**
     * @param list<string> $command
     * @return array{stdout: string, stderr: string, code: int, timed_out: bool}|null null when it couldn't start
     */
    public static function run(array $command, ?string $cwd, int $timeout): ?array
    {
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
        if ($process === false) {
            return null;
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $out = ['', ''];
        $open = [1 => $pipes[1], 2 => $pipes[2]];
        $deadline = microtime(true) + $timeout;
        $timed_out = false;
        while ($open !== []) {
            $left = $deadline - microtime(true);
            if ($left <= 0) {
                $timed_out = true;
                break;
            }
            $read = array_values($open);
            $write = $except = null;
            if (stream_select($read, $write, $except, (int) $left, (int) (fmod($left, 1) * 1e6)) === false) {
                break;
            }
            foreach ($open as $i => $pipe) {
                $chunk = (string) fread($pipe, 65536);
                $out[$i - 1] .= $chunk;
                if ($chunk === '' && feof($pipe)) {
                    fclose($pipe);
                    unset($open[$i]);
                }
            }
        }
        foreach ($open as $pipe) {
            fclose($pipe);
        }
        if ($timed_out) {
            proc_terminate($process);
            usleep(200_000);
            if (proc_get_status($process)['running']) {
                proc_terminate($process, 9);
            }
        }
        $code = proc_close($process);

        return ['stdout' => $out[0], 'stderr' => $out[1], 'code' => $code, 'timed_out' => $timed_out];
    }
}
