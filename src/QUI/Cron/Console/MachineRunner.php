<?php

namespace QUI\Cron\Console;

use QUI\Cron\ExecutionResult;
use Throwable;

/** Isolate bootstrap and job output from the public JSON channel. */
final class MachineRunner
{
    /** @param list<string> $arguments */
    public function run(array $arguments, string $worker): ExecutionResult
    {
        $mode = 'skip';
        $timeout = '300';
        $seen = [];

        foreach ($arguments as $argument) {
            [$name, $value] = array_pad(explode('=', $argument, 2), 2, null);

            if (isset($seen[$name])) {
                return new ExecutionResult('invalid_arguments');
            }

            $seen[$name] = true;

            if ($name === '--json' && $value === null) {
                continue;
            }

            if ($name === '--lock-mode' && in_array($value, ['skip', 'wait'], true)) {
                $mode = $value;
                continue;
            }

            if ($name === '--lock-timeout' && is_string($value) && preg_match('/^\d+(?:\.\d+)?$/D', $value)) {
                $timeout = $value;
                continue;
            }

            return new ExecutionResult('invalid_arguments');
        }

        if (!is_finite((float)$timeout) || (float)$timeout > 86400) {
            return new ExecutionResult('invalid_arguments');
        }

        try {
            $Process = proc_open([
                PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=0',
                $worker, $mode, $timeout
            ], [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', '/dev/null', 'w'],
                2 => ['file', '/dev/null', 'w'],
                3 => ['pipe', 'w']
            ], $pipes);

            if (!is_resource($Process)) {
                return new ExecutionResult('execution_failed', started: null);
            }

            // Raw output never leaves the subprocess. Only the bounded private report is read.
            try {
                $report = fgets($pipes[3], 8193);
            } finally {
                fclose($pipes[3]);
                $exitCode = proc_close($Process);
            }

            if ($report === false || !str_ends_with($report, "\n")) {
                return new ExecutionResult('execution_failed', started: null);
            }

            $data = json_decode($report, true, flags: JSON_THROW_ON_ERROR);

            if (
                !is_array($data) || ($data['contract_version'] ?? null) !== 1 ||
                !is_string($data['status'] ?? null) || !array_key_exists('started', $data) ||
                ($data['started'] !== null && !is_bool($data['started']))
            ) {
                return new ExecutionResult('execution_failed', started: null);
            }

            foreach (['scheduled', 'executed', 'skipped', 'failed'] as $field) {
                if (!is_int($data[$field] ?? null)) {
                    return new ExecutionResult('execution_failed', started: null);
                }
            }

            // Rebuild from allowlisted fields; never forward arbitrary worker JSON or exception text.
            $Result = new ExecutionResult(
                $data['status'],
                $data['scheduled'],
                $data['executed'],
                $data['skipped'],
                $data['failed'],
                $data['started']
            );

            return $Result->exitCode() === $exitCode ? $Result : new ExecutionResult('execution_failed', started: null);
        } catch (Throwable) {
            return new ExecutionResult('execution_failed', started: null);
        }
    }
}
