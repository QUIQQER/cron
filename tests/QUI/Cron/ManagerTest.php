<?php

namespace QUITests\Cron;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use QUI\Cron\Manager;

class ManagerTest extends TestCase
{
    private function createManager(): Manager
    {
        return new class () extends Manager {
            /**
             * @param array<string, mixed> $entry
             */
            public function isCronDue(array $entry, DateTimeImmutable $currentTime): bool
            {
                return $this->shouldExecuteCron($entry, $currentTime);
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
            'lastexec' => null
        ];
    }

    public function testCronWithoutLastExecutionDoesNotRunBeforeScheduledMinute(): void
    {
        $Manager = $this->createManager();
        $entry = $this->createEntry();

        $this->assertFalse(
            $Manager->isCronDue(
                $entry,
                new DateTimeImmutable('2025-06-30 16:26:00')
            )
        );
    }

    public function testCronWithoutLastExecutionRunsAtScheduledMinute(): void
    {
        $Manager = $this->createManager();
        $entry = $this->createEntry();

        $this->assertTrue(
            $Manager->isCronDue(
                $entry,
                new DateTimeImmutable('2025-06-30 22:00:00')
            )
        );
    }

    public function testCronDoesNotRunTwiceWithinSameMinute(): void
    {
        $Manager = $this->createManager();
        $entry = $this->createEntry();
        $entry['lastexec'] = '2025-06-30 22:00:05';

        $this->assertFalse(
            $Manager->isCronDue(
                $entry,
                new DateTimeImmutable('2025-06-30 22:00:45')
            )
        );
    }
}
