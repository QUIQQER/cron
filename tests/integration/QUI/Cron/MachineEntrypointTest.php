<?php

namespace QUITests\Integration\Cron;

use PHPUnit\Framework\TestCase;
use QUI\Cron\ExecutionLock;
use QUI\Cron\Manager;
use QUI\Cron\Console\ExecCrons;
use Symfony\Component\Lock\LockInterface;

class MachineEntrypointTest extends TestCase
{
    private ?LockInterface $Lock = null;

    protected function setUp(): void
    {
        $this->Lock = ExecutionLock::create();

        if (!$this->Lock->acquire()) {
            self::markTestSkipped('A real cron cycle is running.');
        }
    }

    protected function tearDown(): void
    {
        $this->Lock?->release();
    }

    public function testOfficialCliBootstrapsAndRespectsExistingCycle(): void
    {
        [$exitCode, $stdout, $stderr] = $this->runEntrypoint('--lock-mode=skip');

        self::assertSame(3, $exitCode);
        $result = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('already_running', $result['status']);
        self::assertSame(1, $result['contract_version']);
        self::assertSame(0, $result['scheduled']);
        self::assertFalse($result['started']);
        self::assertSame("Cron cycle: already_running\n", $stderr);
    }

    public function testOfficialCliTimeoutDoesNotReleaseExistingCycle(): void
    {
        [$exitCode, $stdout] = $this->runEntrypoint('--lock-mode=wait', '--lock-timeout=0.02');

        self::assertSame(5, $exitCode);
        self::assertSame('lock_timeout', json_decode($stdout, true, flags: JSON_THROW_ON_ERROR)['status']);
        self::assertTrue($this->Lock->isAcquired());
        self::assertFalse(ExecutionLock::create()->acquire());
    }

    public function testLegacyUnlockCannotReleaseActiveProcessWithoutCacheMarker(): void
    {
        try {
            Manager::unlockExecutionLock();
            self::fail('Active lock must not be removed.');
        } catch (\QUI\Exception $Exception) {
            self::assertStringContainsString('Cannot unlock an active cron cycle', $Exception->getMessage());
        }

        self::assertFalse(ExecutionLock::create()->acquire());
    }

    public function testLegacyHttpEntrypointRetainsSuccessResponseForSkippedCycle(): void
    {
        $Process = proc_open([
            PHP_BINARY, __DIR__ . '/Fixtures/legacy-worker.php'
        ], [
            0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'],
            2 => ['pipe', 'w'], 3 => ['pipe', 'w']
        ], $pipes);
        self::assertIsResource($Process);
        $stderr = stream_get_contents($pipes[2]);
        $report = stream_get_contents($pipes[3]);
        fclose($pipes[2]);
        fclose($pipes[3]);

        self::assertSame(0, proc_close($Process), $stderr);
        self::assertSame(200, json_decode($report, true, flags: JSON_THROW_ON_ERROR)['status']);
        self::assertTrue($this->Lock->isAcquired());
    }

    public function testConsoleUnlockAlsoRejectsAnActiveProcess(): void
    {
        $Tool = new class extends ExecCrons {
            public function writeLn(string $msg = '', bool|string $color = false, bool|string $bg = false): void
            {
            }
        };

        $this->expectException(\QUI\Exception::class);
        $this->expectExceptionMessage('Cannot unlock an active cron cycle');
        $Tool->unlock();
    }

    private function runEntrypoint(string ...$arguments): array
    {
        $Process = proc_open([
            PHP_BINARY, dirname(__DIR__, 4) . '/bin/cron-run.php', '--json', ...$arguments
        ], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($Process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($Process), $stdout, $stderr];
    }
}
