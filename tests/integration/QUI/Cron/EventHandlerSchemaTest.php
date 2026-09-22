<?php

namespace QUITests\Integration\Cron;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\Cron\EventHandler;
use ReflectionMethod;

class EventHandlerSchemaTest extends TestCase
{
    private const TABLE_NAME = 'phpunit_cron_schema';
    private const HISTORY_TABLE_NAME = 'phpunit_cron_history_schema';

    protected function setUp(): void
    {
        parent::setUp();

        $Connection = QUI::getDataBaseConnection();
        $Connection->executeStatement('DROP TABLE IF EXISTS ' . self::TABLE_NAME);
        $Connection->executeStatement('DROP TABLE IF EXISTS ' . self::HISTORY_TABLE_NAME);
        $Connection->executeStatement(
            'CREATE TABLE ' . self::TABLE_NAME . ' (title VARCHAR(10) NULL)'
        );
    }

    protected function tearDown(): void
    {
        QUI::getDataBaseConnection()->executeStatement(
            'DROP TABLE IF EXISTS ' . self::TABLE_NAME
        );
        QUI::getDataBaseConnection()->executeStatement(
            'DROP TABLE IF EXISTS ' . self::HISTORY_TABLE_NAME
        );

        parent::tearDown();
    }

    #[Test]
    public function stringColumnLengthIsUpdatedAndMissingColumnIsIgnored(): void
    {
        $Method = new ReflectionMethod(EventHandler::class, 'ensureStringColumnLength');

        $Method->invoke(null, self::TABLE_NAME, 'missing_column', 1000);
        $Method->invoke(null, self::TABLE_NAME, 'title', 1000);

        $Column = QUI::getSchemaManager()
            ->introspectTable(self::TABLE_NAME)
            ->getColumn('title');

        self::assertSame(1000, $Column->getLength());

        $Method->invoke(null, self::TABLE_NAME, 'title', 1000);
    }

    #[Test]
    public function legacyCronHistoryPrimaryKeyIsMigratedWithoutDataLoss(): void
    {
        $connection = QUI::getDataBaseConnection();
        $connection->executeStatement(
            'CREATE TABLE ' . self::HISTORY_TABLE_NAME . ' ('
            . 'cronid INT NOT NULL, '
            . 'uid VARCHAR(50) NOT NULL, '
            . 'lastexec DATETIME NOT NULL, '
            . 'finish DATETIME NULL, '
            . 'PRIMARY KEY (cronid, lastexec)'
            . ')'
        );
        $connection->insert(self::HISTORY_TABLE_NAME, [
            'cronid' => 7,
            'uid' => 'first-user',
            'lastexec' => '2026-01-01 10:00:00',
            'finish' => '2026-01-01 10:01:00'
        ]);
        $connection->insert(self::HISTORY_TABLE_NAME, [
            'cronid' => 7,
            'uid' => 'second-user',
            'lastexec' => '2026-01-02 10:00:00',
            'finish' => '2026-01-02 10:01:00'
        ]);

        $method = new ReflectionMethod(EventHandler::class, 'migrateLegacyCronHistoryPrimaryKey');

        self::assertTrue($method->invoke(null, self::HISTORY_TABLE_NAME));

        $table = QUI::getSchemaManager()->introspectTable(self::HISTORY_TABLE_NAME);
        $primaryKey = $table->getPrimaryKeyConstraint();
        $primaryColumns = array_map(
            static fn($columnName): string => $columnName->getIdentifier()->getValue(),
            $primaryKey?->getColumnNames() ?? []
        );

        self::assertTrue($table->hasColumn('id'));
        self::assertTrue($table->getColumn('id')->getAutoincrement());
        self::assertSame(['id'], $primaryColumns);

        $rows = $connection->createQueryBuilder()
            ->select('id', 'cronid', 'uid', 'lastexec', 'finish')
            ->from(self::HISTORY_TABLE_NAME)
            ->orderBy('lastexec')
            ->executeQuery()
            ->fetchAllAssociative();

        self::assertCount(2, $rows);
        self::assertSame(['first-user', 'second-user'], array_column($rows, 'uid'));
        self::assertCount(2, array_unique(array_column($rows, 'id')));
        self::assertFalse($method->invoke(null, self::HISTORY_TABLE_NAME));
    }

    #[Test]
    public function existingCronHistoryIdIsChangedToAutoIncrementPrimaryKey(): void
    {
        $connection = QUI::getDataBaseConnection();
        $connection->executeStatement(
            'CREATE TABLE ' . self::HISTORY_TABLE_NAME . ' ('
            . 'id INT NOT NULL, '
            . 'cronid INT NOT NULL, '
            . 'uid VARCHAR(50) NOT NULL, '
            . 'lastexec DATETIME NOT NULL, '
            . 'finish DATETIME NULL, '
            . 'PRIMARY KEY (cronid, lastexec)'
            . ')'
        );
        $connection->insert(self::HISTORY_TABLE_NAME, [
            'id' => 100,
            'cronid' => 7,
            'uid' => 'first-user',
            'lastexec' => '2026-01-01 10:00:00',
            'finish' => '2026-01-01 10:01:00'
        ]);
        $connection->insert(self::HISTORY_TABLE_NAME, [
            'id' => 200,
            'cronid' => 7,
            'uid' => 'second-user',
            'lastexec' => '2026-01-02 10:00:00',
            'finish' => '2026-01-02 10:01:00'
        ]);

        $method = new ReflectionMethod(EventHandler::class, 'migrateLegacyCronHistoryPrimaryKey');

        self::assertTrue($method->invoke(null, self::HISTORY_TABLE_NAME));

        $table = QUI::getSchemaManager()->introspectTable(self::HISTORY_TABLE_NAME);
        $primaryKey = $table->getPrimaryKeyConstraint();
        $primaryColumns = array_map(
            static fn($columnName): string => $columnName->getIdentifier()->getValue(),
            $primaryKey?->getColumnNames() ?? []
        );
        $ids = $connection->createQueryBuilder()
            ->select('id')
            ->from(self::HISTORY_TABLE_NAME)
            ->orderBy('id')
            ->executeQuery()
            ->fetchFirstColumn();

        self::assertTrue($table->getColumn('id')->getAutoincrement());
        self::assertSame(['id'], $primaryColumns);
        self::assertSame([100, 200], array_map('intval', $ids));
        self::assertFalse($method->invoke(null, self::HISTORY_TABLE_NAME));
    }
}
