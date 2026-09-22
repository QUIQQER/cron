<?php

namespace QUITests\Integration\Cron\Fixtures;

use QUI\Cron\Manager;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Lock\Store\FlockStore;

/** Exercise the real database/callback path without selecting installed production jobs. */
class IsolatedCycleManager extends Manager
{
    public function __construct(private int $cronId)
    {
    }

    public function getList(): array
    {
        $entry = $this->getCronById($this->cronId);

        return $entry === false ? [] : [$entry];
    }

    protected function createExecutionLock(): LockInterface
    {
        return (new LockFactory(new FlockStore()))->createLock('phpunit-cron-cycle-' . $this->cronId, null);
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
