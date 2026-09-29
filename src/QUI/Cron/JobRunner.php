<?php

namespace QUI\Cron;

/**
 * Run one callback in a fresh PHP process; never fall back to in-process execution.
 */
class JobRunner
{
    /**
     * @var array<string, mixed>
     */
    private array $diagnostics = [];

    public function __construct(
        private string $phpBinary,
        private string $worker
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getDiagnostics(): array
    {
        return $this->diagnostics;
    }

    /**
     * @param array{path: string, token: string} $lockContext
     * @return array{failed: bool, stop: bool}
     */
    public function run(
        int $cronId,
        string $userId,
        bool $cliExecution,
        array $lockContext
    ): array {
        $this->diagnostics = [];

        $Worker = new WorkerProcess();
        $result = $Worker->run(
            $this->phpBinary,
            $this->worker,
            input: [
                'id' => $cronId,
                'user' => $userId,
                'cli' => $cliExecution,
                'lock' => $lockContext
            ]
        );

        $report = $result['report'];
        $exitCode = $result['exitCode'];
        $reportIsTooLarge = strlen($report) > 8192;
        $failure = [
            'failed' => true,
            'stop' => false
        ];

        $this->diagnostics = [
            'reason' => $report === ''
                ? 'worker_report_missing'
                : 'worker_report_invalid',
            'phase' => 'worker',
            'exitCode' => $exitCode
        ];

        if ($reportIsTooLarge) {
            return $failure;
        }

        $data = json_decode($report, true);

        if (
            !is_array($data)
            || !is_bool($data['failed'] ?? null)
            || !is_bool($data['stop'] ?? null)
        ) {
            return $failure;
        }

        if (is_array($data['diagnostics'] ?? null)) {
            $workerDiagnostics = Diagnostics::filter($data['diagnostics']);
            $this->diagnostics = array_replace(
                $this->diagnostics,
                $workerDiagnostics
            );

            // The exit code is observed by the supervisor, never trusted from the report.
            $this->diagnostics['exitCode'] = $exitCode;
        }

        $expectedExitCode = $data['failed'] ? 1 : 0;

        if ($exitCode !== $expectedExitCode) {
            return $failure;
        }

        if (
            $data['failed']
            && empty($data['diagnostics'])
        ) {
            $this->diagnostics['reason'] = 'job_failed';
        }

        if (!$data['failed']) {
            $this->diagnostics = [];
        }

        return [
            'failed' => $data['failed'],
            'stop' => $data['stop']
        ];
    }
}
