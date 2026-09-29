<?php

namespace QUI\Cron;

/**
 * Run one callback in a fresh PHP process; never fall back to in-process execution.
 */
class JobRunner
{
    public function __construct(
        private string $phpBinary,
        private string $worker
    ) {
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

        $data = json_decode($result['report'], true);

        if (
            strlen($result['report']) > 8192
            || !is_array($data)
            || !is_bool($data['failed'] ?? null)
            || !is_bool($data['stop'] ?? null)
            || $result['exitCode'] !== ($data['failed'] ? 1 : 0)
        ) {
            return [
                'failed' => true,
                'stop' => false
            ];
        }

        return [
            'failed' => $data['failed'],
            'stop' => $data['stop']
        ];
    }
}
