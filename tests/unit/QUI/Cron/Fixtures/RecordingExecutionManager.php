<?php

namespace QUITests\Unit\Cron\Fixtures;

use DateTimeImmutable;
use DateTimeInterface;
use QUI\Cron\Manager;
use RuntimeException;

class RecordingExecutionManager extends Manager
{
    /** @var array<int, int> */
    public array $executedCronIds = [];

    /**
     * @param array<int, bool> $updateStates
     * @param array<int, array<string, mixed>> $availableCrons
     * @param array<int, int> $failingCronIds
     */
    public function __construct(
        private array $updateStates = [],
        private readonly ?int $stopAfterCronId = null,
        private readonly bool $cliExecution = true,
        private readonly array $availableCrons = [],
        private readonly bool $cronDue = true,
        private readonly array $failingCronIds = []
    ) {
    }

    public function getAvailableCrons(): array
    {
        return $this->availableCrons;
    }

    protected function isCliExecution(): bool
    {
        return $this->cliExecution;
    }

    protected function isSystemUpdateRunning(): bool
    {
        return array_shift($this->updateStates) ?? false;
    }

    protected function shouldExecuteCron(
        array $entry,
        DateTimeInterface $lastExecutionDate
    ): bool {
        return $this->cronDue;
    }

    public function executeCron(int $cronId): static
    {
        if (in_array($cronId, $this->failingCronIds, true)) {
            throw new RuntimeException('Cron execution fixture failed');
        }

        $this->executedCronIds[] = $cronId;

        if ($cronId === $this->stopAfterCronId) {
            $this->stopAfterCurrentCron();
        }

        return $this;
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     */
    public function executeEntries(array $entries): void
    {
        $this->executeCronList($entries, new DateTimeImmutable('+1 hour'));
    }

    protected function executeScheduledCron(int $cronId): void
    {
        $this->executeCron($cronId);
    }
}
