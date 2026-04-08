<?php

namespace QUITests\Cron;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QUI\Cron\Manager;

class ManagerTest extends TestCase
{
    private function createManager(DateTimeImmutable $currentTime): Manager
    {
        return new class ($currentTime) extends Manager {
            protected DateTimeImmutable $currentTime;

            public function __construct(DateTimeImmutable $currentTime)
            {
                $this->currentTime = $currentTime;
            }

            protected function getCurrentDateTime(): DateTimeImmutable
            {
                return $this->currentTime;
            }

            /**
             * @param array<string, mixed> $entry
             */
            public function isCronDue(array $entry, DateTimeImmutable $lastExecutionDate): bool
            {
                return $this->shouldExecuteCron($entry, $lastExecutionDate);
            }
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function createEntry(): array
    {
        return [
            'id' => 60,
            'title' => 'Daily cron',
            'min' => '0',
            'hour' => '22',
            'day' => '*',
            'month' => '*',
            'dayOfWeek' => '*',
            'createDate' => '2025-06-30 15:00:00',
            'lastexec' => null
        ];
    }

    #[Test]
    public function cronWithoutLastExecutionDoesNotRunBeforeScheduledMinute(): void
    {
        $Manager = $this->createManager(
            new DateTimeImmutable('2025-06-30 16:26:00')
        );
        $entry = $this->createEntry();

        $this->assertFalse(
            $Manager->isCronDue(
                $entry,
                new DateTimeImmutable($entry['createDate'])
            )
        );
    }

    #[Test]
    public function cronWithoutLastExecutionRunsAtScheduledMinute(): void
    {
        $Manager = $this->createManager(
            new DateTimeImmutable('2025-06-30 22:00:00')
        );
        $entry = $this->createEntry();

        $this->assertTrue(
            $Manager->isCronDue(
                $entry,
                new DateTimeImmutable($entry['createDate'])
            )
        );
    }

    #[Test]
    public function cronWithLastExecutionDoesNotRunBeforeNextScheduledMinute(): void
    {
        $Manager = $this->createManager(
            new DateTimeImmutable('2025-07-01 21:55:00')
        );
        $entry = $this->createEntry();

        $this->assertFalse(
            $Manager->isCronDue(
                $entry,
                new DateTimeImmutable('2025-06-30 22:00:00')
            )
        );
    }

    #[Test]
    public function cronWithLastExecutionRunsIfScheduledMinuteWasMissed(): void
    {
        $Manager = $this->createManager(
            new DateTimeImmutable('2025-07-01 22:03:00')
        );
        $entry = $this->createEntry();

        $this->assertTrue(
            $Manager->isCronDue(
                $entry,
                new DateTimeImmutable('2025-06-30 22:00:00')
            )
        );
    }

    #[Test]
    public function cronWithLastExecutionDoesNotRunTwiceForSameSchedule(): void
    {
        $Manager = $this->createManager(
            new DateTimeImmutable('2025-07-01 22:03:00')
        );
        $entry = $this->createEntry();

        $this->assertFalse(
            $Manager->isCronDue(
                $entry,
                new DateTimeImmutable('2025-07-01 22:00:00')
            )
        );
    }
}
