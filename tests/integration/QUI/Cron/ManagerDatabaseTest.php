<?php

namespace QUITests\Integration\Cron;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\Cron\Manager;
use QUI\Interfaces\Users\User;
use ReflectionProperty;
use QUITests\Integration\Cron\Fixtures\DatabaseManager;
use QUITests\Integration\Cron\Fixtures\DisappearingDefinitionManager;
use QUITests\Integration\Cron\Fixtures\ExecutableCron;
use QUITests\Integration\Cron\Fixtures\ExecutionManager;
use QUITests\Integration\Cron\Fixtures\IsolatedCycleManager;

require_once __DIR__ . '/Fixtures/DatabaseManager.php';
require_once __DIR__ . '/Fixtures/DisappearingDefinitionManager.php';
require_once __DIR__ . '/Fixtures/ExecutableCron.php';
require_once __DIR__ . '/Fixtures/ExecutionManager.php';
require_once __DIR__ . '/Fixtures/IsolatedCycleManager.php';

class ManagerDatabaseTest extends TestCase
{
    private const FIXTURE_TITLE = 'phpunit-cron-manager-pagination';
    private const FIXTURE_EXEC = '\\QUITests\\Integration\\Cron\\Fixtures\\ExecutableCron::execute';

    private int $cronId;

    private ?User $previousSessionUser = null;

    /** @var array<int, int> */
    private array $historyIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousSessionUser = self::replaceSessionUser(QUI::getUsers()->getSystemUser());
        ExecutableCron::$calls = [];
        self::cleanupFixtures();

        $Connection = QUI::getDataBaseConnection();
        $Connection->insert(QUI\Utils\Doctrine::quoteIdentifier(Manager::table()), [
            'active' => 0,
            'exec' => self::FIXTURE_EXEC,
            'title' => self::FIXTURE_TITLE,
            'min' => 0,
            'hour' => 0,
            'day' => '*',
            'month' => '*',
            'dayOfWeek' => '*',
            'params' => '[]'
        ]);

        $QueryBuilder = QUI::getQueryBuilder();
        $this->cronId = (int)$QueryBuilder
            ->select('id')
            ->from(QUI\Utils\Doctrine::quoteIdentifier(Manager::table()))
            ->where($QueryBuilder->expr()->eq('title', ':title'))
            ->setParameter('title', self::FIXTURE_TITLE)
            ->executeQuery()
            ->fetchOne();

        for ($second = 1; $second <= 5; $second++) {
            $Connection->insert(QUI\Utils\Doctrine::quoteIdentifier(Manager::tableHistory()), [
                'cronid' => $this->cronId,
                'uid' => '0',
                'lastexec' => sprintf('2099-01-01 00:00:%02d', $second),
                'finish' => sprintf('2099-01-01 00:00:%02d', $second)
            ]);

            $QueryBuilder = QUI::getQueryBuilder();
            $this->historyIds[] = (int)$QueryBuilder
                ->select('id')
                ->from(QUI\Utils\Doctrine::quoteIdentifier(Manager::tableHistory()))
                ->where($QueryBuilder->expr()->eq('cronid', ':cronId'))
                ->andWhere($QueryBuilder->expr()->eq('lastexec', ':lastExecution'))
                ->setParameter('cronId', $this->cronId)
                ->setParameter('lastExecution', sprintf('2099-01-01 00:00:%02d', $second))
                ->executeQuery()
                ->fetchOne();
        }
    }

    protected function tearDown(): void
    {
        self::cleanupFixtures();

        if ($this->previousSessionUser !== null) {
            self::replaceSessionUser($this->previousSessionUser);
        }

        parent::tearDown();
    }

    public static function tearDownAfterClass(): void
    {
        self::cleanupFixtures();
    }

    #[Test]
    public function historyPaginationUsesPageSizeForOffset(): void
    {
        $Manager = new Manager();
        $secondPage = $Manager->getHistoryList([
            'page' => 2,
            'perPage' => 2
        ]);

        self::assertSame(
            [$this->historyIds[2], $this->historyIds[1]],
            array_map(
                static fn(array $entry): int => (int)$entry['id'],
                $secondPage
            )
        );
    }

    #[Test]
    public function cronAndHistoryRecordsCanBeRead(): void
    {
        $Manager = new Manager();
        $cron = $Manager->getCronById($this->cronId);

        self::assertIsArray($cron);
        self::assertSame(self::FIXTURE_TITLE, $cron['title']);
        self::assertFalse($Manager->getCronById(PHP_INT_MAX));
        self::assertContains($this->cronId, array_map(
            static fn(array $entry): int => (int)$entry['id'],
            $Manager->getList()
        ));
        self::assertGreaterThanOrEqual(5, $Manager->getHistoryCount());

        $history = $Manager->getHistoryList(['page' => 1, 'perPage' => 1]);

        self::assertSame(self::FIXTURE_TITLE, $history[0]['cronTitle']);
        self::assertSame((new QUI\Users\Nobody())->getUsername(), $history[0]['username']);
    }

    #[Test]
    public function cronCanBeActivatedAndDeactivated(): void
    {
        $Manager = new Manager();

        $Manager->activateCron($this->cronId);
        self::assertSame(1, $this->getFixtureActiveState());

        $Manager->deactivateCron($this->cronId);
        self::assertSame(0, $this->getFixtureActiveState());
    }

    #[Test]
    public function cronCanBeAddedEditedAndDeleted(): void
    {
        self::cleanupFixtures();

        $Manager = $this->createDatabaseManager();
        $Manager->add(self::FIXTURE_TITLE, '5', '4', '*', '*', '1', [[
            'name' => 'limit',
            'value' => 10
        ]]);

        $cron = $this->findFixtureCron();

        self::assertIsArray($cron);
        self::assertSame('1', (string)$cron['active']);
        self::assertSame('5', (string)$cron['min']);
        self::assertSame('4', (string)$cron['hour']);

        $cronId = (int)$cron['id'];
        $Manager->edit($cronId, self::FIXTURE_TITLE, '15', '6', '*', '*', '2', [[
            'name' => 'limit',
            'value' => 20
        ]]);

        $editedCron = $Manager->getCronById($cronId);

        self::assertIsArray($editedCron);
        self::assertSame('15', (string)$editedCron['min']);
        self::assertSame('6', (string)$editedCron['hour']);
        self::assertSame('2', (string)$editedCron['dayOfWeek']);
        self::assertSame([[
            'name' => 'limit',
            'value' => 20
        ]], json_decode($editedCron['params'], true));

        $Manager->deleteCronIds([$cronId]);

        self::assertFalse($Manager->getCronById($cronId));
    }

    #[Test]
    public function editRejectsInvalidCronExpression(): void
    {
        $Manager = $this->createDatabaseManager();

        $this->expectException(QUI\Exception::class);
        $Manager->edit($this->cronId, self::FIXTURE_TITLE, 'invalid', '*', '*', '*', '*');
    }

    #[Test]
    public function addRejectsUnknownCronDefinition(): void
    {
        $Manager = $this->createDatabaseManager();

        $this->expectException(QUI\Exception::class);
        $this->expectExceptionCode(1001);
        $Manager->add('missing-cron', '0', '0', '*', '*', '*');
    }

    #[Test]
    public function editRejectsUnknownCronDefinition(): void
    {
        $Manager = $this->createDatabaseManager();

        $this->expectException(QUI\Exception::class);
        $this->expectExceptionCode(1002);
        $Manager->edit($this->cronId, 'missing-cron', '0', '0', '*', '*', '*');
    }

    #[Test]
    public function callableCronCanBeExecutedWithStoredParameters(): void
    {
        $this->updateFixtureParams('[{"name":"limit","value":25}]');
        $Manager = new Manager();

        self::assertSame($Manager, $Manager->executeCron($this->cronId));
        self::assertCount(1, ExecutableCron::$calls);
        self::assertSame(['limit' => 25], ExecutableCron::$calls[0]['params']);
        self::assertSame($Manager, ExecutableCron::$calls[0]['manager']);

        $cron = $Manager->getCronById($this->cronId);

        self::assertIsArray($cron);
        self::assertNotEmpty($cron['lastexec']);
        self::assertSame(6, $this->countFixtureHistory());
    }

    #[Test]
    public function nonCallableCronIsNotAddedToHistory(): void
    {
        $this->updateFixtureExec('not-a-callable');
        $Manager = new Manager();

        self::assertSame($Manager, $Manager->executeCron($this->cronId));
        self::assertSame(5, $this->countFixtureHistory());
    }

    #[Test]
    public function completeCycleReportsRealCallbackAndHistoryThenResetsCounters(): void
    {
        $this->makeFixtureDue();
        $Manager = new IsolatedCycleManager($this->cronId);
        $Result = $Manager->executeWithResult();

        self::assertSame('executed', $Result->status);
        self::assertSame(1, $Result->executed);
        self::assertSame(1, $Result->scheduled);
        self::assertCount(1, ExecutableCron::$calls);
        self::assertSame(6, $this->countFixtureHistory());

        $Next = $Manager->executeWithResult();
        self::assertSame('executed', $Next->status);
        self::assertSame(0, $Next->scheduled);
        self::assertSame(0, $Next->executed);
    }

    #[Test]
    public function completeCycleCountsNonCallableCronAsFailed(): void
    {
        $this->makeFixtureDue();
        $this->updateFixtureExec('not-a-callable');
        $Result = (new IsolatedCycleManager($this->cronId))->executeWithResult();

        self::assertSame('completed_with_errors', $Result->status);
        self::assertSame(1, $Result->failed);
        self::assertSame(0, $Result->executed);
        self::assertSame(5, $this->countFixtureHistory());
    }

    private function makeFixtureDue(): void
    {
        QUI::getDataBaseConnection()->update(
            QUI\Utils\Doctrine::quoteIdentifier(Manager::table()),
            ['active' => 1, 'lastexec' => '2000-01-01 00:00:00'],
            ['id' => $this->cronId]
        );
    }

    #[Test]
    public function missingCronCannotBeExecuted(): void
    {
        $Manager = new Manager();

        $this->expectException(QUI\Exception::class);
        $Manager->executeCron(PHP_INT_MAX);
    }

    #[Test]
    public function forcedExecutionPassesOnlyActiveCronsToExecutionList(): void
    {
        $activeEntry = ['id' => 1, 'active' => 1, 'title' => 'Active'];
        $inactiveEntry = ['id' => 2, 'active' => 0, 'title' => 'Inactive'];
        $Manager = new ExecutionManager([$activeEntry, $inactiveEntry]);

        $Manager->execute(true);

        self::assertSame(1, $Manager->getListCalls);
        self::assertSame([$activeEntry], array_values($Manager->receivedEntries));
    }

    #[Test]
    public function executionDoesNotReadCronListDuringSystemUpdate(): void
    {
        $Manager = new ExecutionManager([], true);

        $Manager->execute(true);

        self::assertSame(0, $Manager->getListCalls);
        self::assertSame([], $Manager->receivedEntries);
    }

    #[Test]
    public function historyResolvesKnownUserName(): void
    {
        $SystemUser = QUI::getUsers()->getSystemUser();
        QUI::getDataBaseConnection()->update(
            QUI\Utils\Doctrine::quoteIdentifier(Manager::tableHistory()),
            ['uid' => $SystemUser->getUUID()],
            ['id' => $this->historyIds[4]]
        );

        $history = (new Manager())->getHistoryList(['page' => 1, 'perPage' => 1]);

        self::assertSame($SystemUser->getName(), $history[0]['username']);
    }

    #[Test]
    public function addRejectsDefinitionThatDisappearsAfterExistenceCheck(): void
    {
        $Manager = new DisappearingDefinitionManager();

        $this->expectException(QUI\Exception::class);
        $this->expectExceptionCode(1001);

        $Manager->add('disappearing-add-fixture', '0', '0', '*', '*', '*');
    }

    #[Test]
    public function editRejectsDefinitionThatDisappearsAfterExistenceCheck(): void
    {
        $Manager = new DisappearingDefinitionManager();

        $this->expectException(QUI\Exception::class);
        $this->expectExceptionCode(1002);

        $Manager->edit($this->cronId, 'disappearing-edit-fixture', '0', '0', '*', '*', '*');
    }

    #[Test]
    public function cronWithExecAndParamsExistsMatchesIdenticalParameters(): void
    {
        $this->updateFixtureParams('{"project":"example","language":"de"}');

        $Manager = new Manager();

        self::assertTrue($Manager->cronWithExecAndParamsExists(self::FIXTURE_EXEC, [
            'project' => 'example',
            'language' => 'de'
        ]));
    }

    #[Test]
    public function cronWithExecAndParamsExistsRejectsAdditionalParameters(): void
    {
        $Manager = new Manager();

        self::assertFalse($Manager->cronWithExecAndParamsExists(self::FIXTURE_EXEC, [
            'project' => 'example'
        ]));
    }

    #[Test]
    public function cronWithExecAndParamsExistsRejectsChangedValues(): void
    {
        $this->updateFixtureParams('{"project":"example"}');

        $Manager = new Manager();

        self::assertFalse($Manager->cronWithExecAndParamsExists(self::FIXTURE_EXEC, [
            'project' => 'other'
        ]));
    }

    #[Test]
    public function cronWithExecAndParamsExistsIgnoresInvalidStoredJson(): void
    {
        $this->updateFixtureParams('{invalid');

        $Manager = new Manager();

        self::assertFalse($Manager->cronWithExecAndParamsExists(self::FIXTURE_EXEC));
    }

    private function updateFixtureParams(string $params): void
    {
        QUI::getDataBaseConnection()->update(
            QUI\Utils\Doctrine::quoteIdentifier(Manager::table()),
            ['params' => $params],
            ['id' => $this->cronId]
        );
    }

    private function updateFixtureExec(string $exec): void
    {
        QUI::getDataBaseConnection()->update(
            QUI\Utils\Doctrine::quoteIdentifier(Manager::table()),
            ['exec' => $exec],
            ['id' => $this->cronId]
        );
    }

    private function getFixtureActiveState(): int
    {
        $cron = (new Manager())->getCronById($this->cronId);

        self::assertIsArray($cron);

        return (int)$cron['active'];
    }

    private function countFixtureHistory(): int
    {
        $QueryBuilder = QUI::getQueryBuilder();

        return (int)$QueryBuilder
            ->select('COUNT(id)')
            ->from(QUI\Utils\Doctrine::quoteIdentifier(Manager::tableHistory()))
            ->where($QueryBuilder->expr()->eq('cronid', ':cronId'))
            ->setParameter('cronId', $this->cronId)
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * @return array<string, mixed>|false
     */
    private function findFixtureCron(): array | false
    {
        $QueryBuilder = QUI::getQueryBuilder();

        return $QueryBuilder
            ->select('*')
            ->from(QUI\Utils\Doctrine::quoteIdentifier(Manager::table()))
            ->where($QueryBuilder->expr()->eq('title', ':title'))
            ->setParameter('title', self::FIXTURE_TITLE)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();
    }

    private function createDatabaseManager(): DatabaseManager
    {
        return new DatabaseManager([[
            'title' => self::FIXTURE_TITLE,
            'description' => 'PHPUnit database manager fixture',
            'exec' => self::FIXTURE_EXEC,
            'required' => false,
            'cliOnly' => false,
            'params' => [],
            'autocreate' => []
        ]]);
    }

    private static function replaceSessionUser(User $User): ?User
    {
        $Users = QUI::getUsers();
        $Property = new ReflectionProperty($Users, 'Session');
        $Property->setAccessible(true);

        $PreviousUser = $Property->getValue($Users);
        $Property->setValue($Users, $User);

        return $PreviousUser instanceof User ? $PreviousUser : null;
    }

    private static function cleanupFixtures(): void
    {
        $QueryBuilder = QUI::getQueryBuilder();
        $cronIds = $QueryBuilder
            ->select('id')
            ->from(QUI\Utils\Doctrine::quoteIdentifier(Manager::table()))
            ->where($QueryBuilder->expr()->eq('title', ':title'))
            ->setParameter('title', self::FIXTURE_TITLE)
            ->executeQuery()
            ->fetchFirstColumn();

        $Connection = QUI::getDataBaseConnection();

        foreach ($cronIds as $cronId) {
            $Connection->delete(
                QUI\Utils\Doctrine::quoteIdentifier(Manager::tableHistory()),
                ['cronid' => $cronId]
            );
            $Connection->delete(
                QUI\Utils\Doctrine::quoteIdentifier(Manager::table()),
                ['id' => $cronId]
            );
        }
    }
}
