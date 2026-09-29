<?php

namespace QUITests\Integration\Cron\Fixtures;

use QUI\Cron\ExecutionLock;
use QUI\Cron\JobRunner;
use QUI\Cron\Manager;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Lock\Store\FlockStore;

/** Exercise the real database/callback path without selecting installed production jobs. */
class IsolatedCycleManager extends Manager
{
    public function __construct(
        private int | array $cronId,
        private bool $cli = true
    ) {
    }

    public function getList(): array
    {
        $entries = array_map($this->getCronById(...), (array)$this->cronId);

        return array_values(array_filter($entries));
    }

    protected function createExecutionLock(): LockInterface
    {
        $key = 'phpunit-cron-cycle-' . implode('-', (array)$this->cronId);
        $Factory = new LockFactory(new FlockStore());

        return new ExecutionLock(
            $Factory->createLock($key, null),
            sys_get_temp_dir() . '/' . $key . '.worker.lock'
        );
    }

    protected function createJobRunner(): JobRunner
    {
        return new JobRunner(PHP_BINARY, __DIR__ . '/job-worker.php');
    }

    protected function isCliExecution(): bool
    {
        return $this->cli;
    }

    protected function isLegacyExecutionLocked(): bool
    {
        return false;
    }

    protected function setLegacyExecutionLock(int $seconds): void
    {
    }

    protected function clearLegacyExecutionLock(): void
    {
    }
}
