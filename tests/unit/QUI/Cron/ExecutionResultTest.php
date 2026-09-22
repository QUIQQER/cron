<?php

namespace QUITests\Unit\Cron;

use PHPUnit\Framework\TestCase;
use QUI\Cron\ExecutionResult;
use QUITests\Unit\Cron\Fixtures\ResultManager;
use Symfony\Component\Lock\LockInterface;

require_once __DIR__ . '/Fixtures/ResultManager.php';

class ExecutionResultTest extends TestCase
{
    private function manager(): ResultManager
    {
        $Lock = $this->createMock(LockInterface::class);
        $Lock->method('acquire')->willReturn(true);
        $Lock->expects(self::once())->method('release');

        return new ResultManager($Lock);
    }

    private function entries(): array
    {
        return array_map(static fn(int $id) => [
            'id' => $id, 'active' => 1, 'title' => 'Fixture', 'createDate' => '2020-01-01', 'lastexec' => null
        ], [1, 2, 3]);
    }

    public function testSuccessfulCycleAndCounters(): void
    {
        $Manager = $this->manager();
        $Manager->entries = $this->entries();
        $Manager->entries[1]['due'] = false;
        $Manager->entries[2]['active'] = 0;

        self::assertSame([
            'contract_version' => 1, 'status' => 'executed', 'scheduled' => 1,
            'executed' => 1, 'skipped' => 0, 'failed' => 0, 'started' => true
        ], $Manager->executeWithResult()->jsonSerialize());
        self::assertSame([1], $Manager->calls);
        self::assertSame(1, $Manager->markerClears);
    }

    public function testNoDueJobsIsAnExecutedCycle(): void
    {
        $Manager = $this->manager();
        $Manager->entries = $this->entries();

        foreach ($Manager->entries as &$entry) {
            $entry['due'] = false;
        }

        $Result = $Manager->executeWithResult();
        self::assertSame('executed', $Result->status);
        self::assertTrue($Result->started);
        self::assertSame(0, $Result->scheduled);
        self::assertSame([], $Manager->calls);
    }

    public function testPartialFailuresIncludeErrorsAndDoNotStopOtherJobs(): void
    {
        $Manager = $this->manager();
        $Manager->entries = $this->entries();
        $Manager->failures = [1];
        $Manager->cliOnlyIds = [3];
        $Result = $Manager->executeWithResult();

        self::assertSame('completed_with_errors', $Result->status);
        self::assertSame([3, 1, 1, 1], [$Result->scheduled, $Result->executed, $Result->skipped, $Result->failed]);
        self::assertSame([1, 2], $Manager->calls);
        self::assertStringNotContainsString('secret', json_encode($Result));
    }

    public function testAllFailedJobsAreNotReportedAsSuccess(): void
    {
        $Manager = $this->manager();
        $Manager->entries = $this->entries();
        $Manager->failures = [1, 2, 3];
        $Result = $Manager->executeWithResult();

        self::assertSame('completed_with_errors', $Result->status);
        self::assertSame(3, $Result->failed);
        self::assertSame(0, $Result->executed);
    }

    public function testInvalidScheduleCountsAsFailure(): void
    {
        $Manager = $this->manager();
        $Manager->entries = $this->entries();
        $Manager->entries[0]['lastexec'] = 'invalid date';
        $Result = $Manager->executeWithResult();

        self::assertSame('completed_with_errors', $Result->status);
        self::assertSame(1, $Result->failed);
        self::assertSame([2, 3], $Manager->calls);
    }

    public function testUpdateStopsBeforeAcquiringLock(): void
    {
        $Lock = $this->createMock(LockInterface::class);
        $Lock->expects(self::never())->method('acquire');
        $Lock->expects(self::never())->method('release');
        $Manager = new ResultManager($Lock);
        $Manager->updates = [true];

        self::assertSame('system_update_running', $Manager->executeWithResult()->status);
        self::assertSame(0, $Manager->listReads);
    }

    public function testUpdateIsCheckedAgainAfterAcquisition(): void
    {
        $Manager = $this->manager();
        $Manager->updates = [false, true];

        self::assertSame('system_update_running', $Manager->executeWithResult()->status);
        self::assertSame(0, $Manager->markerWrites);
    }

    public function testUpdateBetweenJobsReportsInterruptedCycle(): void
    {
        $Manager = $this->manager();
        $Manager->entries = $this->entries();
        $Manager->updates = [false, false, false, true];
        $Result = $Manager->executeWithResult();

        self::assertSame('execution_interrupted', $Result->status);
        self::assertSame([3, 1, 2, 0], [$Result->scheduled, $Result->executed, $Result->skipped, $Result->failed]);
        self::assertSame([1], $Manager->calls);
    }

    public function testRequestedStopCountsRemainingDueJobsAsSkipped(): void
    {
        $Manager = $this->manager();
        $Manager->entries = $this->entries();
        $Manager->stopAfter = 1;
        $Result = $Manager->executeWithResult();

        self::assertSame('execution_interrupted', $Result->status);
        self::assertSame(2, $Result->skipped);
    }

    public function testContentionSkipsWithoutReadingJobsOrReleasingOtherOwner(): void
    {
        $Lock = $this->createMock(LockInterface::class);
        $Lock->method('acquire')->willReturn(false);
        $Lock->expects(self::never())->method('release');
        $Manager = new ResultManager($Lock);
        $Result = $Manager->executeWithResult();

        self::assertSame('already_running', $Result->status);
        self::assertFalse($Result->started);
        self::assertSame(0, $Manager->listReads);
        self::assertSame(0, $Manager->markerClears);
    }

    public function testWaitTimeoutDoesNotExecuteJobs(): void
    {
        $Lock = $this->createMock(LockInterface::class);
        $Lock->method('acquire')->willReturn(false);
        $Lock->expects(self::never())->method('release');
        $Manager = new ResultManager($Lock);

        self::assertSame('lock_timeout', $Manager->executeWithResult('wait', 0.01)->status);
        self::assertSame(0, $Manager->listReads);
    }

    public function testWaitRetriesAndRunsItsOwnCycle(): void
    {
        $Lock = $this->createMock(LockInterface::class);
        $Lock->expects(self::exactly(2))->method('acquire')->willReturnOnConsecutiveCalls(false, true);
        $Lock->expects(self::once())->method('release');
        $Manager = new ResultManager($Lock);
        $Manager->entries = $this->entries();

        self::assertSame('executed', $Manager->executeWithResult('wait', 1)->status);
        self::assertSame([1, 2, 3], $Manager->calls);
    }

    public function testLegacyMarkerIsRespectedAndNeverRemovedByContender(): void
    {
        $Manager = $this->manager();
        $Manager->legacyLocked = true;

        self::assertSame('already_running', $Manager->executeWithResult()->status);
        self::assertSame(0, $Manager->markerClears);
    }

    public function testAcquireErrorsAreLockFailures(): void
    {
        $Lock = $this->createMock(LockInterface::class);
        $Lock->method('acquire')->willThrowException(new \RuntimeException('secret backend'));
        $Lock->expects(self::never())->method('release');
        $Manager = new ResultManager($Lock);

        self::assertSame('lock_failed', $Manager->executeWithResult()->status);
    }

    public function testMarkerWriteAndCleanupFailuresAreLockFailures(): void
    {
        $Manager = $this->manager();
        $Manager->markerFails = true;
        self::assertSame('lock_failed', $Manager->executeWithResult()->status);
        self::assertSame(0, $Manager->listReads);

        $Other = $this->manager();
        $Other->clearFails = true;
        self::assertSame('lock_failed', $Other->executeWithResult()->status);
    }

    public function testReleaseFailureIsNotSuccess(): void
    {
        $Lock = $this->createMock(LockInterface::class);
        $Lock->method('acquire')->willReturn(true);
        $Lock->method('release')->willThrowException(new \RuntimeException('secret unlock failure'));
        self::assertSame('lock_failed', (new ResultManager($Lock))->executeWithResult()->status);
    }

    public function testInitializationFailureReleasesLockAndHidesDetails(): void
    {
        $Manager = $this->manager();
        $Manager->initializationFails = true;
        $Result = $Manager->executeWithResult();

        self::assertSame('execution_failed', $Result->status);
        self::assertFalse($Result->started);
        self::assertSame(1, $Manager->markerClears);
        self::assertStringNotContainsString('secret', json_encode($Result));
    }

    public function testUpdateCheckFailureFailsClosed(): void
    {
        $Lock = $this->createMock(LockInterface::class);
        $Lock->expects(self::never())->method('acquire');
        $Manager = new ResultManager($Lock);
        $Manager->updateCheckFails = true;

        self::assertSame('execution_failed', $Manager->executeWithResult()->status);
        self::assertSame(0, $Manager->listReads);
    }

    public function testLegacyForceCannotBypassContention(): void
    {
        $Lock = $this->createMock(LockInterface::class);
        $Lock->method('acquire')->willReturn(false);
        $Manager = new ResultManager($Lock);
        $Manager->execute(true);

        self::assertSame(0, $Manager->listReads);
    }

    public function testLegacyExecutionStillPropagatesTechnicalExecutionExceptions(): void
    {
        $Manager = $this->manager();
        $Manager->initializationFails = true;
        $this->expectException(\RuntimeException::class);
        $Manager->execute();
    }

    public function testReentrantExecutionDoesNotResetOuterCounters(): void
    {
        $Manager = $this->manager();
        $Manager->entries = $this->entries();
        $Manager->reenter = true;

        self::assertSame(3, $Manager->executeWithResult()->executed);
    }

    public function testInvalidOptionsNeverAcquireALock(): void
    {
        $Lock = $this->createMock(LockInterface::class);
        $Lock->expects(self::never())->method('acquire');
        $Manager = new ResultManager($Lock);

        foreach ([['force', 1], ['wait', -1], ['wait', INF], ['wait', NAN]] as [$mode, $timeout]) {
            self::assertSame('invalid_arguments', $Manager->executeWithResult($mode, $timeout)->status);
        }
    }

    public function testExitCodesAreStableAndOnlyExecutionIsZero(): void
    {
        self::assertSame([
            'executed' => 0, 'completed_with_errors' => 1, 'invalid_arguments' => 2,
            'already_running' => 3, 'system_update_running' => 4, 'lock_timeout' => 5,
            'lock_failed' => 6, 'execution_failed' => 7, 'execution_interrupted' => 8
        ], ExecutionResult::EXIT_CODES);
    }
}
