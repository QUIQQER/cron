<?php

namespace QUITests\Unit\Cron;

use PHPUnit\Framework\TestCase;
use QUI\Cron\JobRunner;
use QUI\Cron\SystemUpdateRunningException;

class JobRunnerTest extends TestCase
{
    public function testOutputIsDiscardedAndOnlyValidatedReportAndExitCodeDetermineSuccess(): void
    {
        $this->expectOutputString('');
        $Runner = new JobRunner(PHP_BINARY, __DIR__ . '/Fixtures/job-report-worker.php');
        $context = [
            'path' => '/not-used-by-protocol-fixture',
            'token' => 'fixture'
        ];

        self::assertSame(
            [
                'failed' => false,
                'stop' => false
            ],
            $Runner->run(1, '5', true, $context)
        );
        self::assertSame(
            [
                'failed' => true,
                'stop' => true
            ],
            $Runner->run(2, '5', true, $context)
        );

        foreach ([3, 4, 5, 6, 9, 10, 11, 12] as $id) {
            self::assertSame(
                [
                    'failed' => true,
                    'stop' => false
                ],
                $Runner->run($id, '5', true, $context)
            );
        }
    }

    public function testWorkerUpdateGuardIsReportedAsAnInterruption(): void
    {
        $Runner = new JobRunner(PHP_BINARY, __DIR__ . '/Fixtures/job-report-worker.php');
        $context = [
            'path' => '/unused',
            'token' => 'unused'
        ];

        $this->expectException(SystemUpdateRunningException::class);

        try {
            $Runner->run(8, '5', true, $context);
        } finally {
            self::assertSame([], $Runner->getDiagnostics());
        }
    }

    public function testMissingWorkerFailsInsteadOfReportingSuccess(): void
    {
        $Runner = new JobRunner(PHP_BINARY, '/nonexistent-cron-job-worker.php');
        $result = $Runner->run(
            1,
            '5',
            true,
            [
                'path' => '/unused',
                'token' => 'unused'
            ]
        );

        self::assertSame(
            [
                'failed' => true,
                'stop' => false
            ],
            $result
        );

        $diagnostics = $Runner->getDiagnostics();

        self::assertSame('worker_report_missing', $diagnostics['reason']);
        self::assertNotSame(0, $diagnostics['exitCode']);
    }

    public function testDiagnosticReportCannotOverrideObservedExitCodeOrLeakExtraFields(): void
    {
        $Runner = new JobRunner(PHP_BINARY, __DIR__ . '/Fixtures/job-report-worker.php');
        $context = [
            'path' => '/unused',
            'token' => 'unused'
        ];
        $result = $Runner->run(7, '5', true, $context);
        $diagnostics = $Runner->getDiagnostics();
        $encodedDiagnostics = json_encode($diagnostics);

        self::assertTrue($result['failed']);
        self::assertSame('memory_exhausted', $diagnostics['reason']);
        self::assertSame(255, $diagnostics['exitCode']);
        self::assertStringNotContainsString('secret-fixture', $encodedDiagnostics);

        $Runner->run(1, '5', true, $context);

        self::assertSame([], $Runner->getDiagnostics());
    }
}
