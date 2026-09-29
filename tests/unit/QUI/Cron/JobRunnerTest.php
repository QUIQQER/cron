<?php

namespace QUITests\Unit\Cron;

use PHPUnit\Framework\TestCase;
use QUI\Cron\JobRunner;

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

        foreach ([3, 4, 5, 6] as $id) {
            self::assertSame(
                [
                    'failed' => true,
                    'stop' => false
                ],
                $Runner->run($id, '5', true, $context)
            );
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
    }
}
