<?php

namespace QUITests\Unit\Cron\Console;

use PHPUnit\Framework\TestCase;
use QUI\Cron\Console\MachineRunner;

class MachineRunnerTest extends TestCase
{
    private const WORKER = __DIR__ . '/Fixtures/machine-worker.php';

    public function testJobOutputNeverReachesPublicStreams(): void
    {
        $this->expectOutputString('');
        $Result = (new MachineRunner())->run(['--json'], self::WORKER);

        self::assertSame('executed', $Result->status);
        self::assertSame(1, $Result->executed);
        self::assertStringNotContainsString('secret', json_encode($Result));
    }

    public function testWorkerCanReportPartialFailure(): void
    {
        $Result = (new MachineRunner())->run(['--lock-timeout=4'], self::WORKER);
        self::assertSame('completed_with_errors', $Result->status);
        self::assertSame(1, $Result->exitCode());
        self::assertSame(1, $Result->failed);
    }

    public function testPrematureExitFatalErrorMalformedReportAndWrongExitCodeFailClosed(): void
    {
        foreach ([1, 2, 3, 5] as $timeout) {
            $Result = (new MachineRunner())->run(['--lock-timeout=' . $timeout], self::WORKER);
            self::assertSame('execution_failed', $Result->status);
            self::assertNull($Result->started);
            self::assertStringNotContainsString('secret', json_encode($Result));
        }
    }

    public function testInvalidArgumentsAreRejectedBeforeBootstrap(): void
    {
        foreach (
            [
            ['--force'], ['--lock-mode=force'], ['--lock-timeout=-1'], ['--lock-timeout=INF'],
            ['--lock-timeout=90000'], ['--lock-timeout'], ['--json=1'], ['--json', '--json'],
            ['--lock-mode=wait', '--lock-mode=skip'], ['--lock-timeout=1e3']
            ] as $arguments
        ) {
            $Result = (new MachineRunner())->run($arguments, '/missing-worker');
            self::assertSame('invalid_arguments', $Result->status);
        }
    }

    public function testMissingBootstrapWorkerReportsFailure(): void
    {
        self::assertSame('execution_failed', (new MachineRunner())->run([], '/missing-worker')->status);
    }

    public function testOfficialEntrypointPrintsOnlyJsonAndSafeDiagnosticOnInvalidInput(): void
    {
        $Process = proc_open([
            PHP_BINARY, dirname(__DIR__, 5) . '/bin/cron-run.php', '--secret-password=DO_NOT_PRINT'
        ], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($Process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        self::assertSame(2, proc_close($Process));
        self::assertSame('invalid_arguments', json_decode($stdout, true, flags: JSON_THROW_ON_ERROR)['status']);
        self::assertSame("Cron cycle: invalid_arguments\n", $stderr);
        self::assertStringNotContainsString('DO_NOT_PRINT', $stdout . $stderr);
    }
}
