<?php

namespace QUI\Cron;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Symfony owns process execution; a private bounded report keeps arbitrary output out of the protocol.
 */
final class WorkerProcess
{
    /**
     * @param list<string> $arguments
     * @param array<string, mixed> $input
     * @return array{exitCode: int, report: string}
     */
    public function run(
        string $binary,
        string $worker,
        array $arguments = [],
        array $input = []
    ): array {
        $reportFile = tempnam(sys_get_temp_dir(), 'quiqqer-cron-report-');

        if ($reportFile === false) {
            throw new RuntimeException('Cannot create cron worker report.');
        }

        try {
            $command = [
                $binary,
                '-d',
                'display_errors=0',
                '-d',
                'memory_limit=' . ini_get('memory_limit'),
                '-d',
                'date.timezone=' . date_default_timezone_get(),
                $worker,
                ...$arguments
            ];

            $Process = new Process(
                $command,
                env: ['QUIQQER_CRON_REPORT' => $reportFile],
                input: json_encode($input, JSON_THROW_ON_ERROR),
                timeout: null
            );

            // No output buffers that could themselves exhaust the supervisor's memory.
            $Process->disableOutput();
            $exitCode = $Process->run();
            $report = file_get_contents($reportFile, length: 8193);

            return [
                'exitCode' => $exitCode,
                'report' => $report === false ? '' : $report
            ];
        } finally {
            unlink($reportFile);
        }
    }
}
