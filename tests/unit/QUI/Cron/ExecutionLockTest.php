<?php

namespace QUITests\Unit\Cron;

use PHPUnit\Framework\TestCase;
use QUI\Cron\ExecutionLock;
use QUITests\Unit\Cron\Fixtures\ResultManager;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

require_once __DIR__ . '/Fixtures/ResultManager.php';

class ExecutionLockTest extends TestCase
{
    private string $directory;

    private array $workers = [];

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/quiqqer-cron-lock-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        foreach ($this->workers as [$Process, $pipes]) {
            fclose($pipes[0]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($Process);
        }

        foreach (glob($this->directory . '/locks/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->directory . '/locks');
        rmdir($this->directory);
    }

    private function lock(): ExecutionLock
    {
        $key = 'quiqqer-cron-' . hash('sha256', $this->directory . '/app/');
        $Factory = new LockFactory(new FlockStore($this->directory . '/locks'));

        return new ExecutionLock(
            $Factory->createLock($key, 0.01),
            $this->directory . '/locks/' . $key . '.worker.lock'
        );
    }

    public function testSurvivingWorkerKeepsLockAfterSupervisorIsKilled(): void
    {
        $Owner = $this->startOwner('child');
        self::assertTrue(proc_terminate($Owner, 9));

        // Readiness is emitted by the child after it acquired its shared guard.
        $Contender = $this->lock();
        self::assertFalse($Contender->acquire());
        touch($this->directory . '/release-child');
        $deadline = microtime(true) + 5;

        while (!$Contender->acquire() && microtime(true) < $deadline) {
            usleep(10000);
        }

        self::assertTrue($Contender->isAcquired());
        $Contender->release();
        unlink($this->directory . '/release-child');
    }

    public function testDelayedWorkerCannotJoinSuccessorCycle(): void
    {
        $First = $this->lock();
        self::assertTrue($First->acquire());
        $oldContext = $First->getWorkerContext();
        $First->release();

        $Next = $this->lock();
        self::assertTrue($Next->acquire());

        try {
            ExecutionLock::holdWorkerLock($oldContext['path'], $oldContext['token']);
            self::fail('An old worker must not join a new cycle.');
        } catch (\RuntimeException $Error) {
            self::assertStringContainsString('no longer belongs', $Error->getMessage());
        } finally {
            $Next->release();
        }

        self::assertTrue($this->lock()->acquire());
    }

    private function startOwner(string $mode = 'hold'): mixed
    {
        $Process = proc_open(
            [
                PHP_BINARY,
                __DIR__ . '/Fixtures/lock-worker.php',
                $this->directory,
                $mode
            ],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w']
            ],
            $pipes
        );

        self::assertIsResource($Process);
        $this->workers[] = [$Process, $pipes];
        stream_set_timeout($pipes[1], 5);
        self::assertSame("READY\n", fgets($pipes[1]));

        return $Process;
    }

    public function testOtherProcessCannotAcquireOrReleaseActiveLockEvenAfterTimeout(): void
    {
        $this->startOwner();
        $Contender = $this->lock();
        self::assertFalse($Contender->acquire());
        $Contender->release();
        usleep(50000);
        self::assertFalse($Contender->acquire());

        $Manager = new ResultManager($Contender);
        self::assertSame('already_running', $Manager->executeWithResult()->status);
        $Manager->execute(true);
        self::assertSame(0, $Manager->listReads);
    }

    public function testWaitingProcessRunsOnlyAfterOwnerReleases(): void
    {
        $this->startOwner('timed');
        $Manager = new ResultManager($this->lock());
        $Manager->entries = [
            [
                'id' => 1,
                'active' => 1,
                'title' => 'Fixture',
                'createDate' => '2020-01-01'
            ]
        ];

        $Result = $Manager->executeWithResult('wait', 3);

        self::assertSame('executed', $Result->status);
        self::assertSame([1], $Manager->calls);
    }

    public function testWaitDeadlineDoesNotStealLock(): void
    {
        $this->startOwner();
        $Manager = new ResultManager($this->lock());
        self::assertSame('lock_timeout', $Manager->executeWithResult('wait', 0.03)->status);
        self::assertSame(0, $Manager->listReads);
        self::assertFalse($this->lock()->acquire());
    }

    public function testProcessDeathAutomaticallyReleasesLock(): void
    {
        $Owner = $this->startOwner();
        self::assertTrue(proc_terminate($Owner, 9));
        $Manager = new ResultManager($this->lock());
        self::assertSame('executed', $Manager->executeWithResult('wait', 3)->status);
    }

    public function testFormerOwnerCannotReleaseSuccessorLock(): void
    {
        $First = $this->lock();
        $Second = $this->lock();
        self::assertTrue($First->acquire());
        $First->release();
        self::assertTrue($Second->acquire());
        $First->release();
        self::assertFalse($this->lock()->acquire());
        $Second->release();
    }

    public function testLiveLocalOwnerDoesNotLoseLockWhenConfiguredTtlPasses(): void
    {
        $First = $this->lock();
        self::assertTrue($First->acquire());
        usleep(50000);
        self::assertTrue($First->isAcquired());
        self::assertFalse($this->lock()->acquire());
        $First->release();
    }
}
