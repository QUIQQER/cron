<?php

namespace QUITests\Unit\Cron;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\Config;
use QUI\Cron\Update;
use QUI\Cron\Manager;
use QUI\Mail\Manager as MailManager;
use QUI\Package\Manager as PackageManager;
use QUI\Package\Package;

class UpdateTest extends TestCase
{
    private ?Config $previousConfig = null;

    private ?PackageManager $previousPackageManager = null;

    private ?MailManager $previousMailManager = null;

    private string $updatesFile;

    private bool $updatesFileExisted = false;

    private string | false $previousUpdatesFileContent = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousConfig = QUI::$Conf;
        $this->previousPackageManager = QUI::$PackageManager;
        $this->previousMailManager = QUI::$MailManager;
        $this->updatesFile = QUI::getPackage('quiqqer/cron')->getVarDir() . 'updates';
        $this->updatesFileExisted = file_exists($this->updatesFile);

        if ($this->updatesFileExisted) {
            $this->previousUpdatesFileContent = file_get_contents($this->updatesFile);
        }
    }

    protected function tearDown(): void
    {
        QUI::$Conf = $this->previousConfig;
        QUI::$PackageManager = $this->previousPackageManager;
        QUI::$MailManager = $this->previousMailManager;

        if ($this->updatesFileExisted && $this->previousUpdatesFileContent !== false) {
            file_put_contents($this->updatesFile, $this->previousUpdatesFileContent);
        } elseif (file_exists($this->updatesFile)) {
            unlink($this->updatesFile);
        }

        parent::tearDown();
    }

    #[Test]
    public function availableUpdatesCanBeStoredReadAndCleared(): void
    {
        $updates = [[
            'package' => 'vendor/package',
            'oldVersion' => '1.0.0',
            'version' => '1.1.0'
        ]];

        Update::setAvailableUpdates($updates);

        self::assertSame($updates, Update::getAvailableUpdates());
        self::assertFileExists($this->updatesFile);

        Update::clearUpdateCheck();

        self::assertFileDoesNotExist($this->updatesFile);
        self::assertSame([], Update::getAvailableUpdates());
    }

    #[Test]
    public function invalidAvailableUpdatesFileReturnsEmptyList(): void
    {
        file_put_contents($this->updatesFile, '{invalid');

        self::assertSame([], Update::getAvailableUpdates());
    }

    #[Test]
    public function scheduledCheckIgnoresLegacyConfigAndDevelopmentVersions(): void
    {
        file_put_contents($this->updatesFile, 'stale update data');

        $Config = $this->createMock(Config::class);
        $Config->expects(self::never())->method('get');

        $Package = $this->createMock(Package::class);
        $Package->method('getConfig')
            ->willReturn($Config);
        $Package->method('getVarDir')
            ->willReturn(dirname($this->updatesFile) . '/');

        $PackageManager = $this->createMock(PackageManager::class);
        $PackageManager->expects(self::once())
            ->method('getInstalledPackage')
            ->with('quiqqer/cron')
            ->willReturn($Package);
        $PackageManager->expects(self::once())
            ->method('getOutdated')
            ->with(true)
            ->willReturn([[
                'package' => 'vendor/development-package',
                'oldVersion' => 'dev-main',
                'version' => 'dev-feature'
            ]]);

        QUI::$PackageManager = $PackageManager;

        Update::check();

        self::assertFileDoesNotExist($this->updatesFile);
    }

    #[Test]
    public function scheduledUpdateIgnoresLegacyConfigWithoutOutdatedPackages(): void
    {
        $GlobalConfig = $this->createMock(Config::class);
        $GlobalConfig->expects(self::once())
            ->method('get')
            ->with('globals', 'maintenance')
            ->willReturn(0);
        $GlobalConfig->expects(self::exactly(2))
            ->method('set')
            ->willReturnMap([
                ['globals', 'maintenance', 1, true],
                ['globals', 'maintenance', 0, true]
            ]);
        $GlobalConfig->expects(self::exactly(2))
            ->method('save');

        $PackageManager = $this->createMock(PackageManager::class);
        $PackageManager->expects(self::never())->method('getInstalledPackage');
        $PackageManager->expects(self::once())
            ->method('getOutdated')
            ->with(true)
            ->willReturn([]);

        $this->replaceQuiServices($GlobalConfig, $PackageManager);

        Update::update();
    }

    #[Test]
    public function updateCheckMailContainsClosedPackageList(): void
    {
        $sentBody = '';
        $GlobalConfig = $this->createGlobalConfigMock();
        $Package = $this->createMock(Package::class);
        $Package->method('getVarDir')
            ->willReturn(dirname($this->updatesFile) . '/');

        $PackageManager = $this->createMock(PackageManager::class);
        $PackageManager->expects(self::once())
            ->method('getOutdated')
            ->with(true)
            ->willReturn([$this->createOutdatedPackage()]);
        $PackageManager->expects(self::once())
            ->method('getInstalledPackage')
            ->with('quiqqer/cron')
            ->willReturn($Package);

        $MailManager = $this->createMock(MailManager::class);
        $MailManager->expects(self::once())
            ->method('send')
            ->willReturnCallback(static function (string $to, string $subject, string $body) use (&$sentBody): void {
                $sentBody = $body;
            });

        QUI::$Conf = $GlobalConfig;
        QUI::$PackageManager = $PackageManager;
        QUI::$MailManager = $MailManager;

        Update::checkExecute();

        self::assertStringContainsString('<ul>', $sentBody);
        self::assertStringContainsString('</ul>', $sentBody);
    }

    #[Test]
    public function updateSuccessMailContainsClosedPackageList(): void
    {
        $sentBody = '';
        $GlobalConfig = $this->createGlobalConfigMock();
        $GlobalConfig->expects(self::exactly(2))
            ->method('set')
            ->willReturnMap([
                ['globals', 'maintenance', 1, true],
                ['globals', 'maintenance', 0, true]
            ]);
        $GlobalConfig->expects(self::exactly(2))
            ->method('save');

        $PackageManager = $this->createMock(PackageManager::class);
        $PackageManager->expects(self::once())
            ->method('getOutdated')
            ->with(true)
            ->willReturn([$this->createOutdatedPackage()]);
        $PackageManager->expects(self::once())
            ->method('update');

        $MailManager = $this->createMock(MailManager::class);
        $MailManager->expects(self::once())
            ->method('send')
            ->willReturnCallback(static function (string $to, string $subject, string $body) use (&$sentBody): void {
                $sentBody = $body;
            });

        $Manager = $this->createMock(Manager::class);
        $Manager->expects(self::once())
            ->method('stopAfterCurrentCron');

        QUI::$Conf = $GlobalConfig;
        QUI::$PackageManager = $PackageManager;
        QUI::$MailManager = $MailManager;

        Update::updateExecute($Manager);

        self::assertStringContainsString('<ul>', $sentBody);
        self::assertStringContainsString('</ul>', $sentBody);
    }

    #[Test]
    public function failedPackageUpdateSendsErrorMailAndRestoresMaintenanceMode(): void
    {
        $sentBody = '';
        $GlobalConfig = $this->createGlobalConfigMock();
        $GlobalConfig->expects(self::exactly(2))
            ->method('set')
            ->willReturnMap([
                ['globals', 'maintenance', 1, true],
                ['globals', 'maintenance', 0, true]
            ]);
        $GlobalConfig->expects(self::exactly(2))
            ->method('save');

        $PackageManager = $this->createMock(PackageManager::class);
        $PackageManager->expects(self::once())
            ->method('getOutdated')
            ->with(true)
            ->willReturn([$this->createOutdatedPackage()]);
        $PackageManager->expects(self::once())
            ->method('update')
            ->willThrowException(new \RuntimeException('Update fixture failed'));

        $MailManager = $this->createMock(MailManager::class);
        $MailManager->expects(self::once())
            ->method('send')
            ->willReturnCallback(static function (string $to, string $subject, string $body) use (&$sentBody): void {
                $sentBody = $body;
            });

        $Manager = $this->createMock(Manager::class);
        $Manager->expects(self::once())
            ->method('stopAfterCurrentCron');

        QUI::$Conf = $GlobalConfig;
        QUI::$PackageManager = $PackageManager;
        QUI::$MailManager = $MailManager;

        Update::updateExecute($Manager);

        self::assertStringContainsString('<li>vendor/package: 1.0.0 -> 1.1.0</li>', $sentBody);
        self::assertStringContainsString('</ul>', $sentBody);
    }

    #[Test]
    public function failedUpdateLookupStillRestoresMaintenanceMode(): void
    {
        $maintenanceModeWasRead = false;
        $Config = $this->createMock(Config::class);
        $Config->method('get')
            ->willReturnCallback(static function (
                string $section,
                ?string $key
            ) use (
                &$maintenanceModeWasRead
            ): int {
                if ($section === 'globals' && $key === 'maintenance') {
                    $maintenanceModeWasRead = true;
                }

                return 0;
            });
        $Config->expects(self::exactly(2))
            ->method('set')
            ->willReturnMap([
                ['globals', 'maintenance', 1, true],
                ['globals', 'maintenance', 0, true]
            ]);
        $Config->expects(self::exactly(2))
            ->method('save');

        $PackageManager = $this->createMock(PackageManager::class);
        $PackageManager->expects(self::once())
            ->method('getOutdated')
            ->with(true)
            ->willThrowException(new \RuntimeException('Package lookup failed'));

        $this->replaceQuiServices($Config, $PackageManager);

        Update::updateExecute();

        self::assertTrue($maintenanceModeWasRead);
    }

    #[Test]
    public function updateWithoutPackagesRestoresPreviousMaintenanceMode(): void
    {
        $Config = $this->createMock(Config::class);
        $Config->expects(self::once())
            ->method('get')
            ->with('globals', 'maintenance')
            ->willReturn(0);
        $Config->expects(self::exactly(2))
            ->method('set')
            ->willReturnMap([
                ['globals', 'maintenance', 1, true],
                ['globals', 'maintenance', 0, true]
            ]);
        $Config->expects(self::exactly(2))
            ->method('save');

        $PackageManager = $this->createMock(PackageManager::class);
        $PackageManager->expects(self::once())
            ->method('getOutdated')
            ->with(true)
            ->willReturn([]);
        $PackageManager->expects(self::never())
            ->method('update');

        $this->replaceQuiServices($Config, $PackageManager);

        Update::updateExecute();
    }

    private function replaceQuiServices(
        Config & MockObject $Config,
        PackageManager & MockObject $PackageManager
    ): void {
        QUI::$Conf = $Config;
        QUI::$PackageManager = $PackageManager;
    }

    private function createGlobalConfigMock(): Config & MockObject
    {
        $Config = $this->createMock(Config::class);
        $Config->method('get')
            ->willReturnCallback(static function (string $section, ?string $key): string | int {
                return match ([$section, $key]) {
                    ['globals', 'maintenance'] => 0,
                    ['globals', 'host'] => 'example.test',
                    ['mail', 'admin_mail'] => 'admin@example.test',
                    default => ''
                };
            });

        return $Config;
    }

    /**
     * @return array{package: string, oldVersion: string, version: string}
     */
    private function createOutdatedPackage(): array
    {
        return [
            'package' => 'vendor/package',
            'oldVersion' => '1.0.0',
            'version' => '1.1.0'
        ];
    }
}
