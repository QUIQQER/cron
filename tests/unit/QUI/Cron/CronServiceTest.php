<?php

namespace QUITests\Unit\Cron;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\Config;
use QUI\Cron\CronService;
use QUI\Cron\CronServiceException;
use QUI\Package\Manager as PackageManager;
use QUI\Package\Package;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

class CronServiceTest extends TestCase
{
    private ?Config $previousConfig;

    private ?PackageManager $previousPackageManager;

    private ?QUI\Projects\Manager $previousProjectManager;

    private string $temporaryDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousConfig = QUI::$Conf;
        $this->previousPackageManager = QUI::$PackageManager;
        $this->previousProjectManager = QUI::$ProjectManager;
        $this->temporaryDirectory = sys_get_temp_dir()
            . '/quiqqer-cron-service-'
            . bin2hex(random_bytes(8));

        mkdir($this->temporaryDirectory, 0700, true);
    }

    protected function tearDown(): void
    {
        QUI::$Conf = $this->previousConfig;
        QUI::$PackageManager = $this->previousPackageManager;
        QUI::$ProjectManager = $this->previousProjectManager;

        $tokenFile = $this->temporaryDirectory . '/cronservice/.revoketoken';
        $tokenDirectory = dirname($tokenFile);

        if (file_exists($tokenFile)) {
            unlink($tokenFile);
        }

        if (is_dir($tokenDirectory)) {
            rmdir($tokenDirectory);
        }

        $ajaxFile = $this->temporaryDirectory . '/admin/ajax.php';
        $ajaxDirectory = dirname($ajaxFile);

        if (file_exists($ajaxFile)) {
            unlink($ajaxFile);
        }

        if (is_dir($ajaxDirectory)) {
            rmdir($ajaxDirectory);
        }

        if (is_dir($this->temporaryDirectory)) {
            rmdir($this->temporaryDirectory);
        }

        parent::tearDown();
    }

    #[Test]
    public function constructorInitializesConnectionSettings(): void
    {
        $Service = new CronService();

        self::assertIsString($this->readProperty($Service, 'domain'));
        self::assertIsBool($this->readProperty($Service, 'https'));
        self::assertIsString($this->readProperty($Service, 'packageDir'));
        self::assertNotSame('', $this->readProperty($Service, 'baseUrl'));
    }

    /**
     * @return array<string, array{domain: string, email: string, packageDir: string}>
     */
    public static function invalidRegistrationDataProvider(): array
    {
        return [
            'missing domain' => [
                'domain' => '',
                'email' => 'admin@example.test',
                'packageDir' => '/packages/'
            ],
            'missing email' => [
                'domain' => 'example.test',
                'email' => '',
                'packageDir' => '/packages/'
            ],
            'missing package directory' => [
                'domain' => 'example.test',
                'email' => 'admin@example.test',
                'packageDir' => ''
            ]
        ];
    }

    #[DataProvider('invalidRegistrationDataProvider')]
    #[Test]
    public function registrationRejectsIncompleteConnectionData(
        string $domain,
        string $email,
        string $packageDir
    ): void {
        $Service = $this->createServiceWithoutConstructor($domain, $packageDir);

        $this->expectException(CronServiceException::class);

        $Service->register($email);
    }

    #[Test]
    public function resendAndCancellationRejectMissingDomain(): void
    {
        $Service = $this->createServiceWithoutConstructor('', '/packages/');

        try {
            $Service->resendActivationMail();
            self::fail('Expected resendActivationMail() to reject an empty domain.');
        } catch (QUI\Exception $Exception) {
            self::assertSame('Could not get the instances domain.', $Exception->getMessage());
        }

        $this->expectException(QUI\Exception::class);
        $this->expectExceptionMessage('Could not get the instances domain.');

        $Service->cancelRegistration();
    }

    #[Test]
    public function revokeTokenCanBeStoredAndReadLocally(): void
    {
        $this->replacePackageDirectory($this->temporaryDirectory);
        $Service = $this->createServiceWithoutConstructor('example.test', '/packages/');

        $this->invokePrivate($Service, 'saveRevokeToken', ['secret-token']);

        self::assertSame('secret-token', $this->invokePrivate($Service, 'readRevokeToken'));
        self::assertSame(
            'secret-token',
            file_get_contents($this->temporaryDirectory . '/cronservice/.revoketoken')
        );
    }

    #[Test]
    public function missingRevokeTokenIsRejected(): void
    {
        $this->replacePackageDirectory($this->temporaryDirectory);
        $Service = $this->createServiceWithoutConstructor('example.test', '/packages/');

        $this->expectException(QUI\Exception::class);
        $this->expectExceptionMessage('Tokenfile not present');

        $this->invokePrivate($Service, 'readRevokeToken');
    }

    #[Test]
    public function localAjaxResponseReturnsItsResult(): void
    {
        $function = 'package_pcsg_cronservice_ajax_fixture';
        $this->writeAjaxResponse([
            $function => [
                'result' => ['status' => 1]
            ]
        ]);
        $Service = $this->createServiceWithoutConstructor('example.test', '/packages/');

        self::assertSame(
            ['status' => 1],
            $this->invokePrivate($Service, 'makeServerAjaxCall', [$function, ['domain' => 'example.test']])
        );
    }

    #[Test]
    public function localAjaxExceptionIsForwarded(): void
    {
        $function = 'package_pcsg_cronservice_ajax_fixture';
        $this->writeAjaxResponse([
            $function => [
                'Exception' => ['message' => 'Remote fixture error']
            ]
        ]);
        $Service = $this->createServiceWithoutConstructor('example.test', '/packages/');

        $this->expectException(QUI\Exception::class);
        $this->expectExceptionMessage('Remote fixture error');

        $this->invokePrivate($Service, 'makeServerAjaxCall', [$function, []]);
    }

    #[Test]
    public function invalidLocalAjaxResponseIsRejected(): void
    {
        $this->writeRawAjaxResponse('not-json');
        $Service = $this->createServiceWithoutConstructor('example.test', '/packages/');

        $this->expectException(QUI\Exception::class);
        $this->expectExceptionMessage('Invalid cron service response.');

        $this->invokePrivate($Service, 'makeServerAjaxCall', [
            'package_pcsg_cronservice_ajax_fixture',
            []
        ]);
    }

    #[Test]
    public function unavailableLocalAjaxEndpointIsRejected(): void
    {
        $Service = $this->createServiceWithoutConstructor('example.test', '/packages/');

        $this->expectException(QUI\Exception::class);
        $this->expectExceptionMessage('Could not contact cron service.');

        $this->invokePrivate($Service, 'makeServerAjaxCall', [
            'package_pcsg_cronservice_ajax_fixture',
            []
        ]);
    }

    #[Test]
    public function registrationCanUseLocalResponseAndStoresRevokeToken(): void
    {
        $function = 'package_pcsg_cronservice_ajax_register';
        $this->writeAjaxResponse([
            $function => [
                'result' => [
                    'status' => 1,
                    'revokeCode' => 'local-revoke-token'
                ]
            ]
        ]);
        $this->replacePackageDirectory($this->temporaryDirectory);
        $Service = $this->createServiceWithoutConstructor('example.test', '/packages/');

        $Service->register('admin@example.test');

        self::assertSame(
            'local-revoke-token',
            file_get_contents($this->temporaryDirectory . '/cronservice/.revoketoken')
        );
    }

    /**
     * @return array<string, array{response: array<string, mixed>, message: string}>
     */
    public static function invalidRegistrationResponseProvider(): array
    {
        $function = 'package_pcsg_cronservice_ajax_register';

        return [
            'missing result' => [
                'response' => [],
                'message' => 'Something went wrong!'
            ],
            'remote rejection' => [
                'response' => [
                    $function => [
                        'result' => [
                            'status' => 0,
                            'message' => 'Registration rejected'
                        ]
                    ]
                ],
                'message' => 'Registration rejected'
            ],
            'missing revoke code' => [
                'response' => [
                    $function => [
                        'result' => ['status' => 1]
                    ]
                ],
                'message' => 'Missing revoke code.'
            ]
        ];
    }

    /**
     * @param array<string, mixed> $response
     */
    #[DataProvider('invalidRegistrationResponseProvider')]
    #[Test]
    public function registrationRejectsInvalidServerResults(array $response, string $message): void
    {
        $this->writeAjaxResponse($response);
        $Service = $this->createServiceWithoutConstructor('example.test', '/packages/');

        $this->expectException(QUI\Exception::class);
        $this->expectExceptionMessage($message);

        $Service->register('admin@example.test');
    }

    #[Test]
    public function registrationRejectsInvalidJsonResponse(): void
    {
        $this->writeRawAjaxResponse('not-json');
        $Service = $this->createServiceWithoutConstructor('example.test', '/packages/');

        $this->expectException(QUI\Exception::class);

        $Service->register('admin@example.test');
    }

    #[Test]
    public function statusCombinesRemoteAndLocalExecutionInformation(): void
    {
        $function = 'package_pcsg_cronservice_ajax_getStatus';
        $this->writeAjaxResponse([
            $function => [
                'result' => [
                    'status' => 1,
                    'last_execution' => '',
                    'email' => 'admin@example.test'
                ]
            ]
        ]);
        $this->replacePackageDirectory($this->temporaryDirectory);
        $Service = $this->createServiceWithoutConstructor('example.test', '/packages/');
        $this->invokePrivate($Service, 'saveRevokeToken', ['local-revoke-token']);

        $status = $Service->getStatus();

        self::assertIsArray($status);
        self::assertSame(1, $status['status']);
        self::assertSame('admin@example.test', $status['email']);
        self::assertNotSame('', $status['last_execution']);
        self::assertArrayHasKey('last_local_execution', $status);
    }

    #[Test]
    public function registrationLifecycleCanUseLocalEndpoint(): void
    {
        $Config = $this->createMock(Config::class);
        $Config->expects(self::exactly(2))
            ->method('set')
            ->with('settings', 'executeOnAdminLogin', 1)
            ->willReturn(true);
        $Config->expects(self::exactly(2))
            ->method('save');

        $this->replacePackageDirectory($this->temporaryDirectory, $Config);
        $Service = $this->createServiceWithoutConstructor('example.test', '/packages/');
        $this->invokePrivate($Service, 'saveRevokeToken', ['local-token']);

        $this->writeAjaxResponse([
            'package_pcsg_cronservice_ajax_revokeRegistration' => [
                'result' => true
            ]
        ]);
        $Service->revokeRegistration();

        $this->writeAjaxResponse([
            'package_pcsg_cronservice_ajax_resendActivationMail' => [
                'result' => true
            ]
        ]);
        $Service->resendActivationMail();

        $this->writeAjaxResponse([
            'package_pcsg_cronservice_ajax_cancelRegistration' => [
                'result' => true
            ]
        ]);
        $Service->cancelRegistration();
    }

    private function replacePackageDirectory(string $directory, ?Config $Config = null): void
    {
        $Package = $this->createMock(Package::class);
        $Package->method('getVarDir')
            ->willReturn($directory);
        $Package->method('getConfig')
            ->willReturn($Config);

        $PackageManager = $this->createMock(PackageManager::class);
        $PackageManager->method('getInstalledPackage')
            ->with('quiqqer/cron')
            ->willReturn($Package);

        QUI::$PackageManager = $PackageManager;
    }

    private function createServiceWithoutConstructor(string $domain, string $packageDir): CronService
    {
        $Reflection = new ReflectionClass(CronService::class);
        $Service = $Reflection->newInstanceWithoutConstructor();

        (new ReflectionProperty(CronService::class, 'domain'))->setValue($Service, $domain);
        (new ReflectionProperty(CronService::class, 'https'))->setValue($Service, false);
        (new ReflectionProperty(CronService::class, 'packageDir'))->setValue($Service, $packageDir);
        (new ReflectionProperty(CronService::class, 'baseUrl'))->setValue(
            $Service,
            'file://' . $this->temporaryDirectory
        );

        return $Service;
    }

    private function readProperty(CronService $Service, string $property): mixed
    {
        return (new ReflectionProperty(CronService::class, $property))->getValue($Service);
    }

    /**
     * @param array<int, mixed> $arguments
     */
    private function invokePrivate(CronService $Service, string $method, array $arguments = []): mixed
    {
        $Method = new ReflectionMethod(CronService::class, $method);

        return $Method->invokeArgs($Service, $arguments);
    }

    /**
     * @param array<string, mixed> $response
     */
    private function writeAjaxResponse(array $response): void
    {
        $this->writeRawAjaxResponse((string)json_encode($response));
    }

    private function writeRawAjaxResponse(string $response): void
    {
        $ajaxDirectory = $this->temporaryDirectory . '/admin';

        if (!is_dir($ajaxDirectory)) {
            mkdir($ajaxDirectory, 0700, true);
        }

        file_put_contents(
            $ajaxDirectory . '/ajax.php',
            str_repeat('x', 9) . $response . str_repeat('x', 10)
        );
    }
}
