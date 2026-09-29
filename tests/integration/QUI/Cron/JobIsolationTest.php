<?php

namespace QUITests\Integration\Cron;

use Monolog\Handler\TestHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\Cron\Manager;
use QUI\Log\Logger;
use QUITests\Integration\Cron\Fixtures\IsolatedCycleManager;
use ReflectionProperty;

require_once __DIR__ . '/Fixtures/IsolatedCycleManager.php';

class JobIsolationTest extends TestCase
{
    private const TITLE = 'phpunit-cron-job-isolation';

    private array $ids = [];

    private array $files = [];

    private mixed $previousUser;

    private TestHandler $Handler;

    protected function setUp(): void
    {
        self::cleanup();

        $this->Handler = new TestHandler();
        Logger::getLogger()->pushHandler($this->Handler);

        $Session = new ReflectionProperty(QUI::getUsers(), 'Session');
        $this->previousUser = $Session->getValue(QUI::getUsers());
        $Session->setValue(QUI::getUsers(), QUI::getUsers()->getSystemUser());
    }

    protected function tearDown(): void
    {
        Logger::getLogger()->popHandler();
        self::cleanup();

        $Session = new ReflectionProperty(QUI::getUsers(), 'Session');
        $Session->setValue(QUI::getUsers(), $this->previousUser);

        foreach ($this->files as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
    }

    public static function failures(): iterable
    {
        yield 'memory exhaustion' => ['oom'];
        yield 'early exit with success code' => ['exit'];
        yield 'PHP error' => ['error'];
        yield 'missing callback' => ['missing'];
        yield 'missing project' => ['project'];
        yield 'killed worker' => ['signal'];
    }

    #[DataProvider('failures')]
    public function testFailedJobCannotPreventNextJobOrCreateSuccessHistory(string $mode): void
    {
        if (
            $mode === 'signal'
            && !function_exists('posix_kill')
        ) {
            self::markTestSkipped('POSIX signals are not available.');
        }

        $this->addJob(['mode' => $mode], $mode === 'missing');
        $file = $this->addJob(['limit' => 25]);
        $Manager = new IsolatedCycleManager($this->ids);
        $Result = $Manager->executeWithResult();

        self::assertSame('completed_with_errors', $Result->status);

        $records = array_filter(
            $this->Handler->getRecords(),
            fn ($record) => ($record['context']['cronId'] ?? null) === $this->ids[0]
        );
        $records = array_values($records);

        self::assertCount(1, $records);

        $record = $records[0];
        $context = $record['context'];
        $encodedContext = json_encode($context);
        $logFilename = $record['extra']['quiqqer']['filename']
            ?? $context['filename']
            ?? null;

        $expectedReason = match ($mode) {
            'oom' => 'memory_exhausted',
            'exit' => 'worker_exited',
            'missing' => 'callback_not_callable',
            'project' => 'project_not_found',
            'signal' => 'worker_signaled',
            default => 'exception'
        };

        self::assertSame('cron', $logFilename);
        self::assertSame($expectedReason, $context['reason']);
        self::assertArrayHasKey('callback', $context);
        self::assertArrayHasKey('exitCode', $context);
        self::assertStringNotContainsString('probeFile', $encodedContext);
        self::assertStringNotContainsString('secret-fixture', $encodedContext);

        if (in_array($mode, ['oom', 'error', 'project'], true)) {
            self::assertArrayHasKey('sourceFile', $context);
            self::assertGreaterThan(0, $context['sourceLine']);
        }

        if ($mode === 'error') {
            self::assertSame('Error', $context['exceptionType']);
        }

        if ($mode === 'project') {
            self::assertSame(804, $context['exceptionCode']);
        }

        if ($mode === 'signal') {
            self::assertSame(9, $context['signal']);
            self::assertSame(137, $context['exitCode']);
        }

        self::assertSame(
            [2, 1, 1, 0],
            [
                $Result->scheduled,
                $Result->executed,
                $Result->failed,
                $Result->skipped
            ]
        );

        $data = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(25, $data['params']['limit']);
        self::assertSame('5', $data['user']);
        self::assertNotSame(getmypid(), $data['pid']);
        self::assertSame(0, $this->historyCount($this->ids[0]));
        self::assertSame(1, $this->historyCount($this->ids[1]));
        $historyUser = QUI::getDataBaseConnection()->fetchOne(
            'SELECT uid FROM ' . QUI\Utils\Doctrine::quoteIdentifier(Manager::tableHistory()) . ' WHERE cronid = ?',
            [$this->ids[1]]
        );

        self::assertSame('5', (string)$historyUser);

        $CronManager = new Manager();

        self::assertSame(
            '2000-01-01 00:00:00',
            $CronManager->getCronById($this->ids[0])['lastexec']
        );
        self::assertNotSame(
            '2000-01-01 00:00:00',
            $CronManager->getCronById($this->ids[1])['lastexec']
        );
    }

    public static function stopModes(): iterable
    {
        yield 'normal stop' => [''];
        yield 'stop followed by error' => ['error'];
    }

    #[DataProvider('stopModes')]
    public function testStopRequestReturnsToSupervisorEvenIfJobThrows(string $mode): void
    {
        $this->addJob([
            'stop' => true,
            'mode' => $mode
        ]);

        $file = $this->addJob([]);
        $Manager = new IsolatedCycleManager($this->ids);
        $Result = $Manager->executeWithResult();

        self::assertSame('execution_interrupted', $Result->status);
        self::assertSame(1, $Result->skipped);
        self::assertSame($mode === 'error' ? 1 : 0, $Result->failed);
        self::assertSame('', file_get_contents($file));
        self::assertSame(0, $this->historyCount($this->ids[1]));
    }

    public function testWebOriginAlsoIsolatesCallbacks(): void
    {
        $this->addJob(['mode' => 'oom']);
        $this->addJob([]);
        $Manager = new IsolatedCycleManager($this->ids, false);
        $Result = $Manager->executeWithResult();

        self::assertSame('completed_with_errors', $Result->status);
        self::assertSame(1, $Result->executed);
    }

    private function addJob(array $params, bool $missing = false): string
    {
        $file = tempnam(sys_get_temp_dir(), 'cron-job-probe-');
        $this->files[] = $file;
        $params['probeFile'] = $file;
        $storedParams = [];

        foreach ($params as $name => $value) {
            $storedParams[] = [
                'name' => $name,
                'value' => $value
            ];
        }

        $callback = $missing
            ? 'missing-isolation-callback'
            : '\\QUITests\\Integration\\Cron\\Fixtures\\ExecutableCron::execute';

        QUI::getDataBaseConnection()->insert(
            QUI\Utils\Doctrine::quoteIdentifier(Manager::table()),
            [
                'title' => self::TITLE,
                'active' => 1,
                'exec' => $callback,
                'min' => '*',
                'hour' => '*',
                'day' => '*',
                'month' => '*',
                'dayOfWeek' => '*',
                'lastexec' => '2000-01-01 00:00:00',
                'params' => json_encode($storedParams, JSON_THROW_ON_ERROR)
            ]
        );

        $Query = QUI::getQueryBuilder();
        $this->ids[] = (int)$Query
            ->select('id')
            ->from(QUI\Utils\Doctrine::quoteIdentifier(Manager::table()))
            ->where('title = :title')
            ->setParameter('title', self::TITLE)
            ->orderBy('id', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        return $file;
    }

    private function historyCount(int $id): int
    {
        return (int)QUI::getDataBaseConnection()->fetchOne(
            'SELECT COUNT(*) FROM ' . QUI\Utils\Doctrine::quoteIdentifier(Manager::tableHistory()) . ' WHERE cronid = ?',
            [$id]
        );
    }

    private static function cleanup(): void
    {
        $Connection = QUI::getDataBaseConnection();
        $ids = $Connection->fetchFirstColumn(
            'SELECT id FROM ' . QUI\Utils\Doctrine::quoteIdentifier(Manager::table()) . ' WHERE title = ?',
            [self::TITLE]
        );

        foreach ($ids as $id) {
            $Connection->delete(
                QUI\Utils\Doctrine::quoteIdentifier(Manager::tableHistory()),
                ['cronid' => $id]
            );
            $Connection->delete(
                QUI\Utils\Doctrine::quoteIdentifier(Manager::table()),
                ['id' => $id]
            );
        }
    }
}
