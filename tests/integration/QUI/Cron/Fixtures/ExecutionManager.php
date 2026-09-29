<?php

namespace QUITests\Integration\Cron\Fixtures;

use DateTimeInterface;
use QUI\Cron\Manager;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Lock\Store\FlockStore;

class ExecutionManager extends Manager
{
    /** @var array<int, array<string, mixed>> */
    public array $receivedEntries = [];

    public int $getListCalls = 0;

    /**
     * @param array<int, array<string, mixed>> $entries
     */
    public function __construct(
        private readonly array $entries,
        private readonly bool $updateRunning = false
    ) {
    }

    public function getList(): array
    {
        $this->getListCalls++;

        return $this->entries;
    }

    protected function isSystemUpdateRunning(): bool
    {
        return $this->updateRunning;
    }

    protected function createExecutionLock(): LockInterface
    {
        $Factory = new LockFactory(new FlockStore());

        return $Factory->createLock('phpunit-cron-execution-manager-' . getmypid(), null);
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

    protected function executeCronList(array $activeList, DateTimeInterface $EndTime): void
    {
        $this->receivedEntries = $activeList;
    }
}
