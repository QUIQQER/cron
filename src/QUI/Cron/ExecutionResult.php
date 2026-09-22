<?php

namespace QUI\Cron;

use InvalidArgumentException;
use JsonSerializable;

/** The public, versioned result of one complete cron cycle. */
final class ExecutionResult implements JsonSerializable
{
    public const EXIT_CODES = [
        'executed' => 0,
        'completed_with_errors' => 1,
        'invalid_arguments' => 2,
        'already_running' => 3,
        'system_update_running' => 4,
        'lock_timeout' => 5,
        'lock_failed' => 6,
        'execution_failed' => 7,
        'execution_interrupted' => 8
    ];

    public function __construct(
        public readonly string $status,
        public readonly int $scheduled = 0,
        public readonly int $executed = 0,
        public readonly int $skipped = 0,
        public readonly int $failed = 0,
        public readonly ?bool $started = false
    ) {
        if (!isset(self::EXIT_CODES[$status])) {
            throw new InvalidArgumentException('Unknown cron execution status.');
        }

        if (min($scheduled, $executed, $skipped, $failed) < 0 || $scheduled !== $executed + $skipped + $failed) {
            throw new InvalidArgumentException('Invalid cron execution counters.');
        }
    }

    public function exitCode(): int
    {
        return self::EXIT_CODES[$this->status];
    }

    /** @return array{contract_version: int, status: string, scheduled: int, executed: int,
     *     skipped: int, failed: int, started: bool|null}
     */
    public function jsonSerialize(): array
    {
        return [
            'contract_version' => 1,
            'status' => $this->status,
            'scheduled' => $this->scheduled,
            'executed' => $this->executed,
            'skipped' => $this->skipped,
            'failed' => $this->failed,
            'started' => $this->started
        ];
    }
}
