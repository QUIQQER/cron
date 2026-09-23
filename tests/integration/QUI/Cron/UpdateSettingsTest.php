<?php

namespace QUITests\Integration\Cron;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\Cron\Manager;
use QUI\Cron\UpdateSettings;
use QUI\Interfaces\Users\User;
use QUITests\Integration\Cron\Fixtures\DatabaseManager;
use ReflectionProperty;

require_once __DIR__ . '/Fixtures/DatabaseManager.php';

class UpdateSettingsTest extends TestCase
{
    private UpdateSettings $Settings;
    private Manager $Manager;
    private QUI\Config $Config;
    private string $configFile;
    private ?User $previousUser = null;

    protected function setUp(): void
    {
        $Users = QUI::getUsers();
        $Session = new ReflectionProperty($Users, 'Session');
        $this->previousUser = $Session->getValue($Users);
        $Admin = $this->createMock(QUI\Users\User::class);
        $Admin->method('isSU')->willReturn(true);
        $Session->setValue($Users, $Admin);

        // Production rows stay invisible to this test and are restored by rollback.
        QUI::getDataBaseConnection()->beginTransaction();

        foreach (UpdateSettings::CRONS as $exec) {
            QUI::getDataBaseConnection()->delete(
                QUI\Utils\Doctrine::quoteIdentifier(Manager::table()),
                ['exec' => $exec]
            );
        }

        $this->Manager = new DatabaseManager(
            (new Manager())->getCronsFromFile(dirname(__DIR__, 4) . '/cron.xml')
        );
        $this->Settings = new UpdateSettings($this->Manager);
        $this->configFile = tempnam(sys_get_temp_dir(), 'cron-update-settings-');
        $this->Config = new QUI\Config($this->configFile);
    }

    protected function tearDown(): void
    {
        QUI::getDataBaseConnection()->rollBack();
        (new ReflectionProperty(QUI::getUsers(), 'Session'))->setValue(QUI::getUsers(), $this->previousUser);
        unlink($this->configFile);
    }

    public function testNewInstallationDefaultsAndIdempotentMigration(): void
    {
        $this->Settings->migrate($this->Config);
        self::assertSame([
            'check' => ['active' => true, 'exists' => true],
            'security' => ['active' => true, 'exists' => true],
            'update' => ['active' => false, 'exists' => true]
        ], $this->Settings->getStates());

        $this->Settings->setActive('security', false);
        $this->Settings->setActive('update', true);
        $this->Settings->migrate(new QUI\Config($this->configFile));
        self::assertFalse($this->Settings->getStates()['security']['active']);
        self::assertTrue($this->Settings->getStates()['update']['active']);
        self::assertCount(3, $this->rows());
    }

    /** @return iterable<string, array{bool, bool}> */
    public static function legacyStates(): iterable
    {
        yield 'both off' => [false, false];
        yield 'cron off, flag on' => [false, true];
        yield 'cron on, flag off' => [true, false];
        yield 'both on' => [true, true];
    }

    #[DataProvider('legacyStates')]
    public function testMigrationPreservesEffectiveStateAndSchedule(bool $cronActive, bool $flag): void
    {
        foreach (['check', 'update', 'security'] as $name) {
            $this->Settings->setActive($name, $cronActive);
        }

        foreach (['auto_check', 'auto_update'] as $key) {
            $this->Config->set('update', $key, (int)$flag);
        }

        $before = $this->rows();
        $this->Settings->migrate($this->Config);
        $states = $this->Settings->getStates();
        self::assertSame($cronActive && $flag, $states['check']['active']);
        self::assertSame($cronActive && $flag, $states['update']['active']);
        self::assertSame($cronActive, $states['security']['active']);

        foreach ($this->rows() as $i => $row) {
            unset($row['active'], $before[$i]['active']);
            self::assertSame($before[$i], $row);
        }

        $Config = new QUI\Config($this->configFile);
        self::assertFalse($Config->existValue('update', 'auto_check'));
        self::assertFalse($Config->existValue('update', 'auto_update'));
    }

    public function testMissingLegacyCronsStayInactiveEvenWhenFlagsWereEnabled(): void
    {
        $this->Config->set('update', 'auto_check', 1);
        $this->Config->set('update', 'auto_update', 1);
        $this->Settings->migrate($this->Config);

        foreach ($this->Settings->getStates() as $state) {
            self::assertSame(['active' => false, 'exists' => true], $state);
        }
    }

    public function testCheckboxCreatesMissingJobAndTracksDirectCronChanges(): void
    {
        self::assertSame(['active' => false, 'exists' => false], $this->Settings->getStates()['check']);
        $this->Settings->setActive('check', false);
        $this->Settings->setActive('check', true);
        $rows = $this->rows();
        self::assertCount(1, $rows);
        self::assertSame('0', (string)$rows[0]['min']);
        self::assertSame('1', (string)$rows[0]['hour']);
        self::assertTrue($this->Settings->getStates()['check']['active']);

        $id = (int)$rows[0]['id'];
        $this->Manager->deactivateCron($id);
        self::assertFalse($this->Settings->getStates()['check']['active']);
        $this->Manager->activateCron($id);
        self::assertTrue($this->Settings->getStates()['check']['active']);
        $this->Settings->setActive('check', false);
        self::assertSame('0', (string)$this->Manager->getCronById($id)['active']);
    }

    public function testAllExistingSchedulesAreSwitchedWithoutChangingTheirParameters(): void
    {
        $this->Settings->setActive('security', true);
        $duplicate = $this->rows()[0];
        unset($duplicate['id']);
        $duplicate['min'] = '17';
        $duplicate['params'] = '[{"name":"email","value":"cron@example.test"}]';
        QUI::getDataBaseConnection()->insert(QUI\Utils\Doctrine::quoteIdentifier(Manager::table()), $duplicate);
        $this->Settings->setActive('security', false);
        self::assertCount(2, $this->rows());

        foreach ($this->rows() as $row) {
            self::assertSame('0', (string)$row['active']);
        }

        self::assertSame('17', (string)$this->rows()[1]['min']);
        self::assertSame($duplicate['params'], $this->rows()[1]['params']);
    }

    public function testUnknownSettingIsRejected(): void
    {
        $this->expectException(QUI\Exception::class);
        $this->Settings->setActive('arbitrary-executable', true);
    }

    public function testUnauthenticatedUserCannotSwitchUpdateCrons(): void
    {
        (new ReflectionProperty(QUI::getUsers(), 'Session'))->setValue(QUI::getUsers(), new QUI\Users\Nobody());

        try {
            $this->Settings->setActive('update', true);
            self::fail('An unauthenticated user must not change update settings.');
        } catch (QUI\Permissions\Exception) {
            self::assertSame([], $this->rows());
        }
    }

    public function testSystemCronCannotBeDeletedOrRetargetedButScheduleCanChange(): void
    {
        $this->Settings->setActive('check', true);
        $cron = $this->rows()[0];
        $id = (int)$cron['id'];

        try {
            $this->Manager->deleteCronIds([$id]);
            self::fail('Deleting a system cron must fail.');
        } catch (QUI\Exception) {
            self::assertIsArray($this->Manager->getCronById($id));
        }

        try {
            $this->Manager->edit($id, UpdateSettings::CRONS['update'], '5', '6', '*', '*', '*');
            self::fail('Changing the system task must fail.');
        } catch (QUI\Exception) {
            self::assertSame($cron['exec'], $this->Manager->getCronById($id)['exec']);
        }

        $this->Manager->edit($id, $cron['exec'], '5', '6', '*', '*', '*');
        self::assertSame('5', (string)$this->Manager->getCronById($id)['min']);
    }

    public function testMixedDeletionIsRejectedBeforeDeletingCustomCrons(): void
    {
        $this->Settings->setActive('check', true);
        $system = $this->rows()[0];
        $custom = $system;
        unset($custom['id']);
        $custom['exec'] = '\\PHPUnit\\CustomCron::execute';
        $custom['title'] = 'phpunit-custom-cron-delete';
        $Connection = QUI::getDataBaseConnection();
        $Connection->insert(QUI\Utils\Doctrine::quoteIdentifier(Manager::table()), $custom);
        $id = (int)$Connection->lastInsertId();

        try {
            $this->Manager->deleteCronIds([$id, (int)$system['id']]);
            self::fail('A mixed selection must be rejected before any deletion.');
        } catch (QUI\Exception) {
            self::assertIsArray($this->Manager->getCronById($id));
            self::assertIsArray($this->Manager->getCronById((int)$system['id']));
        }

        $this->Manager->deleteCronIds([PHP_INT_MAX, $id]);
        self::assertFalse($this->Manager->getCronById($id));
    }

    /** @return array<int, array<string, mixed>> */
    private function rows(): array
    {
        $rows = array_filter(
            $this->Manager->getList(),
            static fn(array $row): bool => in_array($row['exec'], UpdateSettings::CRONS, true)
        );
        usort($rows, static fn(array $a, array $b): int => (int)$a['id'] <=> (int)$b['id']);

        return $rows;
    }
}
