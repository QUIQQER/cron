<?php

namespace QUITests\Unit\Cron\Fixtures;

use DateTimeInterface;
use QUI\Cron\Manager;
use RuntimeException;
use Symfony\Component\Lock\LockInterface;

class ResultManager extends Manager
{
    public array $entries = [];
    public array $calls = [];
    public array $updates = [];
    public array $failures = [];
    public array $cliOnlyIds = [];
    public ?int $stopAfter = null;
    public bool $initializationFails = false;
    public bool $updateCheckFails = false;
    public bool $markerFails = false;
    public bool $clearFails = false;
    public bool $legacyLocked = false;
    public bool $reenter = false;
    public int $markerWrites = 0;
    public int $markerClears = 0;
    public int $listReads = 0;

    public function __construct(private LockInterface $Lock)
    {
    }

    protected function createExecutionLock(): LockInterface
    {
        return $this->Lock;
    }

    protected function checkExecutionPermission(): void
    {
    }

    protected function isSystemUpdateRunning(): bool
    {
        if ($this->updateCheckFails) {
            throw new RuntimeException('secret update path');
        }

        return array_shift($this->updates) ?? false;
    }

    protected function isLegacyExecutionLocked(): bool
    {
        return $this->legacyLocked;
    }

    protected function setLegacyExecutionLock(int $seconds): void
    {
        $this->markerWrites++;

        if ($this->markerFails) {
            throw new RuntimeException('secret lock credentials');
        }
    }

    protected function clearLegacyExecutionLock(): void
    {
        $this->markerClears++;

        if ($this->clearFails) {
            throw new RuntimeException('secret unlock credentials');
        }
    }

    public function getList(): array
    {
        $this->listReads++;

        if ($this->initializationFails) {
            throw new RuntimeException('secret database credentials');
        }

        return $this->entries;
    }

    protected function shouldExecuteCron(array $entry, DateTimeInterface $lastExecutionDate): bool
    {
        return $entry['due'] ?? true;
    }

    protected function canExecuteCron(array $entry): bool
    {
        return !in_array($entry['id'], $this->cliOnlyIds, true);
    }

    public function executeCron(int $cronId): static
    {
        $this->calls[] = $cronId;

        if ($this->reenter && $this->executeWithResult()->status !== 'already_running') {
            throw new RuntimeException('Recursive execution was allowed');
        }

        if (in_array($cronId, $this->failures, true)) {
            throw new \Error('secret job payload');
        }

        if ($this->stopAfter === $cronId) {
            $this->stopAfterCurrentCron();
        }

        return $this;
    }
}
