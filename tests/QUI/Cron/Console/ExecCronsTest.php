<?php

namespace QUITests\Cron\Console;

use PHPUnit\Framework\TestCase;
use QUI\Cron\Console\ExecCrons;
use RuntimeException;

class ExecCronsTest extends TestCase
{
    public function testExecuteWithUnlockArgumentCallsUnlock(): void
    {
        $Tool = new ExecCronsDouble();
        $Tool->setArgument('--unlock', true);

        $Tool->execute();

        $this->assertSame(1, $Tool->unlockCalls);
        $this->assertSame(0, $Tool->runCalls);
        $this->assertSame(0, $Tool->listCalls);
        $this->assertSame(0, $Tool->listAllCalls);
    }

    public function testCommandReadListsUnlockCommand(): void
    {
        $Tool = new ExecCronsDouble();
        $Tool->throwOnRead = true;

        try {
            $Tool->commandRead();
            $this->fail('Expected commandRead to stop after readInput().');
        } catch (RuntimeException $Exception) {
            $this->assertSame('stop-read', $Exception->getMessage());
        }

        $this->assertStringContainsString(
            'unlock',
            implode("\n", $Tool->output)
        );
        $this->assertStringContainsString(
            'cron execution lock',
            implode("\n", $Tool->output)
        );
    }

    public function testCommandReadUnlockDispatchesUnlock(): void
    {
        $Tool = new ExecCronsDouble();
        $Tool->inputs = ['unlock'];
        $Tool->stopAfterUnlock = true;

        try {
            $Tool->commandRead();
            $this->fail('Expected commandRead to stop after unlock().');
        } catch (RuntimeException $Exception) {
            $this->assertContains(
                $Exception->getMessage(),
                ['stop-unlock', 'missing-input']
            );
        }

        $this->assertSame(1, $Tool->unlockCalls);
    }
}

class ExecCronsDouble extends ExecCrons
{
    public int $unlockCalls = 0;
    public int $runCalls = 0;
    public int $listCalls = 0;
    public int $listAllCalls = 0;
    public bool $throwOnRead = false;
    public bool $stopAfterUnlock = false;

    /** @var array<int, string> */
    public array $inputs = [];

    /** @var array<int, string> */
    public array $output = [];

    public function run(): void
    {
        $this->runCalls++;
    }

    public function listCrons(): void
    {
        $this->listCalls++;
    }

    public function listAllCrons(): void
    {
        $this->listAllCalls++;
    }

    public function unlock(): void
    {
        $this->unlockCalls++;

        if ($this->stopAfterUnlock) {
            throw new RuntimeException('stop-unlock');
        }
    }

    public function writeLn(string $msg = '', bool|string $color = false, bool|string $bg = false): void
    {
        $this->output[] = $msg;
    }

    public function readInput(): string
    {
        if ($this->throwOnRead) {
            throw new RuntimeException('stop-read');
        }

        if (!count($this->inputs)) {
            throw new RuntimeException('missing-input');
        }

        return array_shift($this->inputs);
    }

    public function resetColor(): void
    {
    }
}
