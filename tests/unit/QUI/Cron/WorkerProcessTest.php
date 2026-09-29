<?php

namespace QUITests\Unit\Cron;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class WorkerProcessTest extends TestCase
{
    public static function workerResults(): iterable
    {
        yield 'successful worker' => [1, 0, false, false];
        yield 'failed worker requesting stop' => [2, 1, true, true];
    }

    #[DataProvider('workerResults')]
    public function testRunsWithoutDevNullAndDiscardsWorkerOutput(
        int $jobId,
        int $exitCode,
        bool $failed,
        bool $stop
    ): void {
        $Process = $this->createRestrictedProcess((string)$jobId);
        $Process->run();

        self::assertSame(0, $Process->getExitCode(), $Process->getErrorOutput());
        self::assertSame('', $Process->getErrorOutput());

        $result = json_decode($Process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $report = json_decode($result['report'], true, flags: JSON_THROW_ON_ERROR);

        self::assertSame($exitCode, $result['exitCode']);
        self::assertSame(
            [
                'failed' => $failed,
                'stop' => $stop
            ],
            $report
        );
    }

    public function testMachineRunnerAlsoWorksWithoutDevNull(): void
    {
        $Process = $this->createRestrictedProcess('machine');
        $Process->run();

        self::assertSame(0, $Process->getExitCode(), $Process->getErrorOutput());
        self::assertSame('', $Process->getErrorOutput());

        $output = $Process->getOutput();
        $result = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('executed', $result['status']);
        self::assertSame(1, $result['executed']);
        self::assertStringNotContainsString('secret', $output);
    }

    private function createRestrictedProcess(string $mode): Process
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            self::markTestSkipped('This regression covers Unix open_basedir restrictions.');
        }

        $allowedDirectories = [
            dirname(__DIR__, 7),
            sys_get_temp_dir(),
            dirname(PHP_BINARY)
        ];
        $openBasedir = implode(PATH_SEPARATOR, $allowedDirectories);
        $command = [
            PHP_BINARY,
            '-d',
            'open_basedir=' . $openBasedir,
            __DIR__ . '/Fixtures/restricted-worker-supervisor.php',
            $mode
        ];

        return new Process($command);
    }
}
