<?php

namespace QUITests\Unit\Cron;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\Config;
use QUI\Cron\EventHandler;
use QUI\Cron\Manager;
use QUI\Interfaces\Users\User;
use QUI\Package\Manager as PackageManager;
use QUI\Package\Package;
use QUI\Projects\Project;
use ReflectionProperty;

class EventHandlerTest extends TestCase
{
    private ?PackageManager $previousPackageManager;

    private bool $previousCronWarning;

    private string $updatesFile;

    private bool $updatesFileExisted;

    private string | false $previousUpdatesFileContent = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousPackageManager = QUI::$PackageManager;
        $WarningProperty = new ReflectionProperty(EventHandler::class, 'cronWarning');
        $this->previousCronWarning = (bool)$WarningProperty->getValue();
        $this->updatesFile = QUI::getPackage('quiqqer/cron')->getVarDir() . 'updates';
        $this->updatesFileExisted = file_exists($this->updatesFile);

        if ($this->updatesFileExisted) {
            $this->previousUpdatesFileContent = file_get_contents($this->updatesFile);
        }
    }

    protected function tearDown(): void
    {
        QUI::$PackageManager = $this->previousPackageManager;
        (new ReflectionProperty(EventHandler::class, 'cronWarning'))->setValue(
            null,
            $this->previousCronWarning
        );

        if ($this->updatesFileExisted && $this->previousUpdatesFileContent !== false) {
            file_put_contents($this->updatesFile, $this->previousUpdatesFileContent);
        } elseif (file_exists($this->updatesFile)) {
            unlink($this->updatesFile);
        }

        parent::tearDown();
    }

    #[Test]
    public function adminFooterLoadsStatusExecutionAndWarningScripts(): void
    {
        $Config = $this->createMock(Config::class);
        $Config->expects(self::once())
            ->method('get')
            ->with('settings', 'executeOnAdminLogin')
            ->willReturn(true);
        $this->replacePackageConfig($Config);
        (new ReflectionProperty(EventHandler::class, 'cronWarning'))->setValue(null, true);

        ob_start();
        EventHandler::adminLoadFooter();
        $output = (string)ob_get_clean();

        self::assertStringContainsString('package/quiqqer/cron/bin/UpdateInfo', $output);
        self::assertStringContainsString('executeCronViaAdmin.js', $output);
        self::assertStringContainsString('noRunWarning.js', $output);
    }

    #[Test]
    public function adminFooterReturnsWithoutPackageConfig(): void
    {
        $this->replacePackageConfig(null);

        ob_start();
        EventHandler::adminLoadFooter();
        $output = (string)ob_get_clean();

        self::assertSame('', $output);
    }

    #[Test]
    public function adminFooterReturnsWhenPackageLookupFails(): void
    {
        $PackageManager = $this->createMock(PackageManager::class);
        $PackageManager->expects(self::once())
            ->method('getInstalledPackage')
            ->with('quiqqer/cron')
            ->willThrowException(new QUI\Exception('Package fixture unavailable'));
        QUI::$PackageManager = $PackageManager;

        ob_start();
        EventHandler::adminLoadFooter();
        $output = (string)ob_get_clean();

        self::assertSame('', $output);
    }

    #[Test]
    public function updateEndClearsStoredUpdateInformation(): void
    {
        file_put_contents($this->updatesFile, 'fixture update data');

        EventHandler::updateEnd();

        self::assertFileDoesNotExist($this->updatesFile);
    }

    #[Test]
    public function adminLoadReturnsOutsideAdminContext(): void
    {
        if (defined('ADMIN')) {
            self::markTestSkipped('ADMIN is already defined by the test environment.');
        }

        EventHandler::onAdminLoad();

        self::assertTrue(true);
    }

    #[Test]
    public function packageAndProjectEventsDelegateToEmptyAutocreateList(): void
    {
        $PackageManager = $this->createMock(PackageManager::class);
        $PackageManager->expects(self::exactly(3))
            ->method('getInstalled')
            ->willReturn([]);
        QUI::$PackageManager = $PackageManager;

        $Package = $this->createMock(Package::class);
        $Package->expects(self::exactly(2))
            ->method('getName')
            ->willReturn('vendor/fixture');
        $Project = $this->createMock(Project::class);

        EventHandler::onPackageSetup($Package);
        EventHandler::onPackageInstall($Package);
        EventHandler::onCreateProject($Project);

        self::assertTrue(true);
    }

    #[PreserveGlobalState(false)]
    #[RunInSeparateProcess]
    #[Test]
    public function adminLoadAcceptsRecentCronExecutionForSuperUser(): void
    {
        define('ADMIN', true);
        $Users = QUI::getUsers();
        $SessionProperty = new ReflectionProperty($Users, 'Session');
        $PreviousUser = $SessionProperty->getValue($Users);
        $SuperUsers = $Users->getUsers([
            'where' => ['su' => 1],
            'limit' => 1
        ]);

        if (!isset($SuperUsers[0])) {
            self::markTestSkipped('The test database has no super-user fixture.');
        }

        $SuperUser = $SuperUsers[0];
        $SessionProperty->setValue($Users, $SuperUser);

        $title = 'phpunit-admin-load-recent';
        $Connection = QUI::getDataBaseConnection();
        $Connection->delete(QUI\Utils\Doctrine::quoteIdentifier(Manager::table()), ['title' => $title]);
        $Connection->insert(QUI\Utils\Doctrine::quoteIdentifier(Manager::table()), [
            'active' => 0,
            'exec' => '\\Vendor\\Fixture::execute',
            'title' => $title,
            'min' => 0,
            'hour' => 0,
            'day' => '*',
            'month' => '*',
            'dayOfWeek' => '*',
            'params' => '[]',
            'lastexec' => date('Y-m-d H:i:s')
        ]);

        $Config = $this->createMock(Config::class);
        $Config->expects(self::once())
            ->method('get')
            ->with('settings', 'showAdminMessageIfCronNotRun')
            ->willReturn(true);
        $this->replacePackageConfig($Config);

        try {
            EventHandler::onAdminLoad();
            self::assertTrue(true);
        } finally {
            $Connection->delete(QUI\Utils\Doctrine::quoteIdentifier(Manager::table()), ['title' => $title]);

            if ($PreviousUser instanceof User) {
                $SessionProperty->setValue($Users, $PreviousUser);
            }
        }
    }

    #[PreserveGlobalState(false)]
    #[RunInSeparateProcess]
    #[Test]
    public function cronWarningIsEnabledAfterAdminAttention(): void
    {
        if (!Manager::isQuiqqerInstallerExecuted()) {
            self::markTestSkipped('QUIQQER installation providers are not fully set up.');
        }

        EventHandler::sendAdminInfoCronError();

        self::assertTrue((bool)(new ReflectionProperty(EventHandler::class, 'cronWarning'))->getValue());
    }

    #[Test]
    public function cronPackageSetupChecksAlreadyCurrentSchema(): void
    {
        $SchemaManager = QUI::getSchemaManager();
        $titleLength = $SchemaManager
            ->introspectTable(Manager::table())
            ->getColumn('title')
            ->getLength();
        $historyTable = $SchemaManager->introspectTable(Manager::tableHistory());
        $uidLength = $historyTable->getColumn('uid')->getLength();
        $historyPrimaryKey = $historyTable->getPrimaryKeyConstraint();
        $historyPrimaryColumns = array_map(
            static fn($columnName): string => $columnName->getIdentifier()->getValue(),
            $historyPrimaryKey?->getColumnNames() ?? []
        );
        $historyIdIsCurrent = $historyTable->hasColumn('id')
            && $historyTable->getColumn('id')->getAutoincrement()
            && $historyPrimaryColumns === ['id'];

        if ($titleLength !== 1000 || $uidLength !== 50 || !$historyIdIsCurrent) {
            self::markTestSkipped('Cron schema is not current; the test must not alter production tables.');
        }

        $PackageManager = $this->createMock(PackageManager::class);
        $PackageManager->expects(self::once())
            ->method('getInstalled')
            ->willReturn([]);
        QUI::$PackageManager = $PackageManager;

        $Package = $this->createMock(Package::class);
        $Package->expects(self::once())
            ->method('getName')
            ->willReturn('quiqqer/cron');

        EventHandler::onPackageSetup($Package);

        self::assertTrue(true);
    }

    private function replacePackageConfig(?Config $Config): void
    {
        $Package = $this->createMock(Package::class);
        $Package->expects(self::once())
            ->method('getConfig')
            ->willReturn($Config);

        $PackageManager = $this->createMock(PackageManager::class);
        $PackageManager->expects(self::once())
            ->method('getInstalledPackage')
            ->with('quiqqer/cron')
            ->willReturn($Package);

        QUI::$PackageManager = $PackageManager;
    }
}
