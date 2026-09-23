<?php

/**
 * This File contains QUI\Cron\Manager
 */

namespace QUI\Cron;

use Cron\CronExpression;
use DateMalformedStringException;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use DOMElement;
use QUI;
use QUI\Database\Exception;
use QUI\Permissions\Permission;
use QUI\System\Log;
use Symfony\Component\Lock\LockInterface;
use Throwable;

use function array_filter;
use function array_key_exists;
use function array_merge;
use function boolval;
use function count;
use function date;
use function date_create;
use function explode;
use function is_callable;
use function is_null;
use function json_decode;
use function max;
use function microtime;
use function round;
use function time;
use function trim;

use const VAR_DIR;

/**
 * Cron Manager
 *
 * @error  1001 - Cannot add Cron. Cron not exists
 * @error  1002 - Cannot edit Cron. Cron command not exists
 */
class Manager
{
    const AUTOCREATE_SCOPE_PROJECTS = 'projects';
    const AUTOCREATE_SCOPE_LANGUAGES = 'languages';
    const EXECUTION_LOCK_KEY = 'cron-execution';
    public const CRON_TYPE_SYSTEM = 'system';
    public const CRON_TYPE_CUSTOM = 'custom';

    /**
     * Flag that indicates if a cron.log is written
     *
     * @var bool
     */
    protected static ?bool $writeCronLog = null;

    /**
     * Data about the current runtime
     *
     * @var array{
     *     currentCronTitle: string,
     *     currentCronId: int,
     *     finished: int,
     *     total: int,
     *     startAll: string|false,
     *     startCurrent: string|false,
     *     lockEnd: string|false
     * }
     */
    protected static array $runtime = [
        'currentCronTitle' => '',
        'currentCronId' => 0,
        'finished' => 0,
        'total' => 0,
        'startAll' => false,
        'startCurrent' => false,
        'lockEnd' => false
    ];

    /**
     * @var bool
     */
    protected static bool $lockTimeoutNotificationSent = false;

    protected bool $stopExecutionAfterCurrentCron = false;

    private int $scheduledCount = 0;
    private int $executedCount = 0;
    private int $failedCount = 0;
    private bool $executionInterrupted = false;
    private bool $lastCronFailed = false;
    private ?Throwable $executionFailure = null;
    private bool $runningCycle = false;

    /**
     * @var array<string, bool>|null
     */
    protected ?array $cliOnlyExecutables = null;

    /**
     * Determines whether the Quiqqer installer has been executed or not.
     *
     * @return bool Returns true if the installer has been executed, false otherwise.
     */
    public static function isQuiqqerInstallerExecuted(): bool
    {
        $notExecuted = QUI\InstallationWizard\ProviderHandler::getNotSetUpProviderList();

        if (count($notExecuted)) {
            return false;
        }

        return true;
    }

    /**
     * Add a cron
     *
     * @param string $cron - Name of the Cron
     * @param int|string $min - On which minute should it start
     * @param int|string $hour - On which hour should it start
     * @param int|string $day - On which day should it start
     * @param int|string $month - On which month should it start
     * @param int|string $dayOfWeek - day of week (0 - 6) (0 to 6 are Sunday to Saturday,
     *                          or use names; 7 is Sunday, the same as 0)
     * @param array<string, mixed> $params Cron parameter
     *
     * @throws QUI\Exception
     */
    public function add(
        string $cron,
        int | string $min,
        int | string $hour,
        int | string $day,
        int | string $month,
        int | string $dayOfWeek,
        array $params = []
    ): void {
        Permission::checkPermission('quiqqer.cron.add');

        if (!$this->cronExists($cron)) {
            throw new QUI\Exception(
                QUI::getLocale()->get('quiqqer/cron', 'exception.cron.1001'),
                1001
            );
        }

        $cronData = $this->getCronData($cron);

        if ($cronData === false) {
            throw new QUI\Exception(
                QUI::getLocale()->get('quiqqer/cron', 'exception.cron.1001'),
                1001
            );
        }

        if (!empty($params['exec'])) {
            $cronData['exec'] = $params['exec'];
            unset($params['exec']);
        }

        QUI::getDataBaseConnection()->insert(QUI\Utils\Doctrine::quoteIdentifier($this->table()), [
            'active' => 1,
            'exec' => $cronData['exec'],
            'title' => $cronData['title'],
            'min' => $min,
            'hour' => $hour,
            'day' => $day,
            'month' => $month,
            'dayOfWeek' => $dayOfWeek,
            'params' => json_encode($params)
        ]);

        QUI::getMessagesHandler()->addSuccess(
            QUI::getLocale()->get(
                'quiqqer/cron',
                'message.cron.succesful.added'
            )
        );
    }

    /**
     * Edit the cron
     *
     * @param string $cron - Name of the Cron
     * @param int $cronId
     * @param int|string $min
     * @param int|string $hour
     * @param int|string $day
     * @param int|string $month
     * @param int|string $dayOfWeek
     * @param array<string, mixed> $params
     *
     * @throws QUI\Exception
     */
    public function edit(
        int $cronId,
        string $cron,
        int | string $min,
        int | string $hour,
        int | string $day,
        int | string $month,
        int | string $dayOfWeek,
        array $params = []
    ): void {
        Permission::checkPermission('quiqqer.cron.edit');

        if (!$this->cronExists($cron)) {
            throw new QUI\Exception(
                QUI::getLocale()->get('quiqqer/cron', 'exception.cron.1002'),
                1002
            );
        }

        $cronData = $this->getCronData($cron);

        if ($cronData === false) {
            throw new QUI\Exception(
                QUI::getLocale()->get('quiqqer/cron', 'exception.cron.1002'),
                1002
            );
        }

        $existing = $this->getCronById($cronId);

        if (
            is_array($existing)
            && $this->isSystemCron((string)$existing['exec'])
            && ltrim((string)$existing['exec'], '\\') !== ltrim((string)$cronData['exec'], '\\')
        ) {
            throw new QUI\Exception(['quiqqer/cron', 'exception.cron.system.change']);
        }

        // test the cron data
        try {
            new CronExpression("$min $hour $day $month $dayOfWeek");
        } catch (\Exception $Exception) {
            throw new QUI\Exception($Exception->getMessage());
        }

        QUI::getDataBaseConnection()->update(QUI\Utils\Doctrine::quoteIdentifier($this->table()), [
            'exec' => $cronData['exec'],
            'title' => $cronData['title'],
            'min' => $min,
            'hour' => $hour,
            'day' => $day,
            'month' => $month,
            'dayOfWeek' => $dayOfWeek,
            'params' => json_encode($params)
        ], [
            'id' => $cronId
        ]);

        QUI::getMessagesHandler()->addSuccess(
            QUI::getLocale()->get(
                'quiqqer/cron',
                'message.cron.succesful.edit'
            )
        );
    }

    /**
     * activate a cron in the cron list
     *
     * @param integer $cronId - ID of the cron
     * @throws QUI\Permissions\Exception
     * @throws Exception
     */
    public function activateCron(int $cronId): void
    {
        Permission::checkPermission('quiqqer.cron.deactivate');

        QUI::getDataBaseConnection()->update(
            QUI\Utils\Doctrine::quoteIdentifier($this->table()),
            ['active' => 1],
            ['id' => $cronId]
        );
    }

    /**
     * deactivate a cron in the cron list
     *
     * @param integer $cronId - ID of the cron
     * @throws QUI\Permissions\Exception|Exception
     */
    public function deactivateCron(int $cronId): void
    {
        Permission::checkPermission('quiqqer.cron.activate');

        QUI::getDataBaseConnection()->update(
            QUI\Utils\Doctrine::quoteIdentifier($this->table()),
            ['active' => 0],
            ['id' => $cronId]
        );
    }

    /**
     * Delete the crons
     *
     * @param array<int, int|string> $ids Array of the cron IDs
     * @throws QUI\Permissions\Exception|Exception
     */
    public function deleteCronIds(array $ids): void
    {
        Permission::checkPermission('quiqqer.cron.delete');

        // Validate the complete selection before deleting anything.
        foreach ($ids as $id) {
            $cron = $this->getCronById((int)$id);

            if (is_array($cron) && $this->isSystemCron((string)$cron['exec'])) {
                throw new QUI\Exception(['quiqqer/cron', 'exception.cron.system.delete']);
            }
        }

        foreach ($ids as $id) {
            $id = (int)$id;
            QUI::getDataBaseConnection()->delete(QUI\Utils\Doctrine::quoteIdentifier($this->table()), [
                'id' => $id
            ]);
        }
    }

    /**
     * Execute all upcoming cron jobs
     *
     * @param bool $force Retained for compatibility; active process locks are never bypassed.
     * @throws QUI\Permissions\Exception|Exception
     */
    public function execute(bool $force = false): void
    {
        // Keep the public signature; force no longer bypasses an active process lock.
        $Result = $this->executeWithResult();

        if ($Result->status === 'execution_failed' && $this->executionFailure !== null) {
            throw $this->executionFailure;
        }
    }

    /**
     * Run a complete cycle using the version-1 execution contract.
     * Timeout bounds lock contention, not execution time or backend I/O.
     */
    public function executeWithResult(string $lockMode = 'skip', float $lockTimeout = 300): ExecutionResult
    {
        if (!in_array($lockMode, ['skip', 'wait'], true) || !is_finite($lockTimeout) || $lockTimeout < 0) {
            return new ExecutionResult('invalid_arguments');
        }

        if ($this->runningCycle) {
            return new ExecutionResult('already_running');
        }

        $this->runningCycle = true;
        $this->scheduledCount = $this->executedCount = $this->failedCount = 0;
        $this->stopExecutionAfterCurrentCron = $this->executionInterrupted = false;
        $this->executionFailure = null;

        $Lock = null;
        $ownsLock = false;
        $legacyMarker = false;
        $started = false;
        $status = 'execution_failed';
        $phase = 'execution_failed';

        try {
            Manager::log('Start cron execution (all crons)');

            if ($this->isSystemUpdateRunning()) {
                return new ExecutionResult('system_update_running');
            }

            $phase = 'lock_failed';
            $Lock = $this->createExecutionLock();
            $deadline = hrtime(true) / 1e9 + $lockTimeout;

            while (true) {
                if ($Lock->acquire()) {
                    $ownsLock = true;

                    // Respect pre-upgrade runs and retain the marker read by Core's cron command.
                    if (!$this->isLegacyExecutionLocked()) {
                        break;
                    }

                    $Lock->release();
                    $ownsLock = false;
                }

                if ($lockMode === 'skip') {
                    return new ExecutionResult('already_running');
                }

                $remaining = $deadline - hrtime(true) / 1e9;

                if ($remaining <= 0) {
                    return new ExecutionResult('lock_timeout');
                }

                usleep((int)min(50000, ceil($remaining * 1e6)));

                if (hrtime(true) / 1e9 >= $deadline) {
                    return new ExecutionResult('lock_timeout');
                }
            }

            $phase = 'execution_failed';

            // An update may have started while we waited for the lock.
            if ($this->isSystemUpdateRunning()) {
                $status = 'system_update_running';
            } else {
                $this->checkExecutionPermission();
                self::$runtime = [
                    'currentCronTitle' => '', 'currentCronId' => 0, 'finished' => 0, 'total' => 0,
                    'startAll' => false, 'startCurrent' => false, 'lockEnd' => false
                ];
                self::$lockTimeoutNotificationSent = false;
                $Start = new DateTime();
                $lockTime = max(1, self::getLockTime());
                $EndTime = $Start->modify('+' . $lockTime . ' seconds');
                self::$runtime['startAll'] = $Start->format('Y-m-d H:i:s');
                self::$runtime['lockEnd'] = $EndTime->format('Y-m-d H:i:s');

                $phase = 'lock_failed';
                $legacyMarker = true;
                $this->setLegacyExecutionLock($lockTime);
                $phase = 'execution_failed';

                $activeList = array_filter($this->getList(), static fn($entry) => $entry['active'] == 1);
                self::$runtime['total'] = count($activeList);
                $started = true;
                $this->executeCronList($activeList, $EndTime);
                $status = $this->executionInterrupted ? 'execution_interrupted' :
                    ($this->failedCount > 0 ? 'completed_with_errors' : 'executed');
                Manager::log('Finish cron execution (all crons)');
            }
        } catch (Throwable $Error) {
            $this->executionFailure = $Error;
            $status = $phase;
            // Do not expose exception messages or cron parameters in the public contract.
            Log::addError('Cron cycle failed: ' . $status);
        } finally {
            if ($ownsLock) {
                try {
                    if ($legacyMarker) {
                        $this->clearLegacyExecutionLock();
                    }
                } catch (Throwable) {
                    $status = 'lock_failed';
                } finally {
                    try {
                        $Lock?->release();
                    } catch (Throwable) {
                        $status = 'lock_failed';
                    }
                }
            }

            $this->runningCycle = false;
        }

        return new ExecutionResult(
            $status,
            $this->scheduledCount,
            $this->executedCount,
            $this->scheduledCount - $this->executedCount - $this->failedCount,
            $this->failedCount,
            $started
        );
    }

    protected function createExecutionLock(): LockInterface
    {
        return ExecutionLock::create();
    }

    protected function checkExecutionPermission(): void
    {
        Permission::checkPermission('quiqqer.cron.execute');
    }

    protected function isLegacyExecutionLocked(): bool
    {
        $Package = QUI::getPackage('quiqqer/cron');

        return (bool)QUI\Lock\Locker::isLocked($Package, self::EXECUTION_LOCK_KEY, null, false);
    }

    protected function setLegacyExecutionLock(int $seconds): void
    {
        QUI\Lock\Locker::lock(QUI::getPackage('quiqqer/cron'), self::EXECUTION_LOCK_KEY, $seconds);
    }

    protected function clearLegacyExecutionLock(): void
    {
        QUI\Lock\Locker::unlock(QUI::getPackage('quiqqer/cron'), self::EXECUTION_LOCK_KEY);
    }

    /**
     * @param array<int, array<string, mixed>> $activeList
     */
    protected function executeCronList(array $activeList, DateTimeInterface $EndTime): void
    {
        foreach ($activeList as $entry) {
            try {
                $lastExecutionDate = !empty($entry['lastexec']) ?
                    new DateTimeImmutable($entry['lastexec']) : new DateTimeImmutable($entry['createDate']);

                if (!$this->shouldExecuteCron($entry, $lastExecutionDate)) {
                    self::$runtime['finished']++;
                    continue;
                }
            } catch (Throwable) {
                // A schedule that cannot be evaluated is a failed candidate, never a successful run.
                $this->scheduledCount++;
                $this->failedCount++;
                self::$runtime['finished']++;
                Log::addError('Could not evaluate cron schedule (ID: ' . $entry['id'] . ').');
                continue;
            }

            $this->scheduledCount++;

            if ($this->executionInterrupted || $this->shouldStopExecution()) {
                $this->executionInterrupted = true;
                continue;
            }

            if (!$this->canExecuteCron($entry)) {
                self::$runtime['finished']++;
                Manager::log('SKIP CLI-only cron (ID: ' . $entry['id'] . ')');
                continue;
            }

            try {
                self::$runtime['startCurrent'] = date('Y-m-d H:i:s');
                self::$runtime['currentCronId'] = $entry['id'];
                self::$runtime['currentCronTitle'] = $entry['title'];
                $this->lastCronFailed = false;
                $this->executeCron($entry['id']);

                if ($this->lastCronFailed) {
                    $this->failedCount++;
                } else {
                    $this->executedCount++;
                }
            } catch (Throwable) {
                $this->failedCount++;
                $message = 'Cron execution failed (ID: ' . $entry['id'] . ').';
                Log::addError($message);
                QUI::getMessagesHandler()->addError($message);
            }

            self::$runtime['finished']++;

            if (new DateTimeImmutable() > $EndTime) {
                self::sendCronLockTimeoutNotification();
            }
        }
    }

    public function stopAfterCurrentCron(): void
    {
        $this->stopExecutionAfterCurrentCron = true;
    }

    protected function shouldStopExecution(): bool
    {
        if ($this->stopExecutionAfterCurrentCron) {
            return true;
        }

        return $this->isSystemUpdateRunning();
    }

    /** @phpstan-impure Reads update state changed by other processes. */
    protected function isSystemUpdateRunning(): bool
    {
        $Repository = new QUI\System\Update\RunRepository(VAR_DIR . 'update/runs/');
        $runs = $Repository->cleanupAndFindActive(time(), 86400);

        return count($runs['active']) > 0;
    }

    /**
     * Return the cron expression for a cron entry.
     *
     * @param array<string, mixed> $entry
     */
    protected function getCronExpression(array $entry): string
    {
        $dayOfWeek = '*';

        if (isset($entry['dayOfWeek'])) {
            $dayOfWeek = $entry['dayOfWeek'];
        }

        return "{$entry['min']} {$entry['hour']} {$entry['day']} {$entry['month']} {$dayOfWeek}";
    }

    protected function getCurrentDateTime(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }

    /**
     * Check whether a cron entry should be executed at the current time.
     *
     * @param array<string, mixed> $entry
     * @param DateTimeInterface $lastExecutionDate
     * @return bool
     * @throws \Exception
     */
    protected function shouldExecuteCron(
        array $entry,
        DateTimeInterface $lastExecutionDate
    ): bool {
        $cronExpression = new CronExpression($this->getCronExpression($entry));
        $currentDateTime = $this->getCurrentDateTime();

        $lastExecutionDate = DateTimeImmutable::createFromInterface($lastExecutionDate);
        $nextExecutionDate = DateTimeImmutable::createFromMutable(
            $cronExpression->getNextRunDate($lastExecutionDate)
        );

        return $nextExecutionDate <= $currentDateTime;
    }

    /**
     * Remove the cron execution lock.
     *
     * @throws \Exception
     */
    public static function unlockExecutionLock(): void
    {
        $Lock = ExecutionLock::create();

        if (!$Lock->acquire()) {
            throw new QUI\Exception('Cannot unlock an active cron cycle. Wait for its owner to finish.');
        }

        try {
            QUI\Lock\Locker::unlock(QUI::getPackage('quiqqer/cron'), self::EXECUTION_LOCK_KEY);
        } finally {
            $Lock->release();
        }
    }

    /**
     * Execute a cron
     *
     * @throws QUI\Exception
     * @phpstan-impure Executes callbacks and mutates execution state.
     */
    public function executeCron(int $cronId): static
    {
        Permission::checkPermission('quiqqer.cron.execute');


        $cronData = $this->getCronById($cronId);
        $params = [];

        if (!$cronData) {
            throw new QUI\Exception('Cron ID not exist');
        }

        if (!$this->canExecuteCron($cronData)) {
            throw new QUI\Exception([
                'quiqqer/cron',
                'message.cron.cli_only'
            ]);
        }

        if (isset($cronData['params'])) {
            $cronDataParams = json_decode($cronData['params'], true);

            if (is_array($cronDataParams)) {
                foreach ($cronDataParams as $entry) {
                    $params[$entry['name']] = $entry['value'];
                }
            }
        }

        Manager::log('START cron "' . $cronData['title'] . '" (ID: ' . $cronId . ')');
        $start = microtime(true);
        $starTime = time();

        if (!is_callable($cronData['exec'])) {
            $this->lastCronFailed = true;
            Log::addError('Cron is not callable "' . $cronData['title'] . '" (ID: ' . $cronId . ')');
            return $this;
        }

        call_user_func_array($cronData['exec'], [$params, $this]);

        $end = round(microtime(true) - $start, 2);
        Manager::log('FINISH cron "' . $cronData['title'] . '" (ID: ' . $cronId . ') - time: ' . $end . ' seconds');

        QUI::getMessagesHandler()->addSuccess(
            QUI::getLocale()->get(
                'quiqqer/cron',
                'message.cron.succesful.executed'
            )
        );

        QUI::getDataBaseConnection()->insert(QUI\Utils\Doctrine::quoteIdentifier(self::tableHistory()), [
            'cronid' => $cronId,
            'lastexec' => date('Y-m-d H:i:s', $starTime),
            'finish' => date('Y-m-d H:i:s'),
            'uid' => QUI::getUserBySession()->getUUID() ?: 0
        ]);


        QUI::getDataBaseConnection()->update(
            QUI\Utils\Doctrine::quoteIdentifier(self::table()),
            ['lastexec' => date('Y-m-d H:i:s')],
            ['id' => $cronId]
        );

        return $this;
    }

    /**
     * Return the Crons which are available and from other Plugins provided
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAvailableCrons(): array
    {
        $PackageManager = QUI::getPackageManager();
        $packageList = $PackageManager->getInstalled();

        $result = [];

        foreach ($packageList as $entry) {
            $dir = OPT_DIR . $entry['name'] . '/';
            $cronFile = $dir . 'cron.xml';

            if (!file_exists($cronFile)) {
                continue;
            }

            $result = array_merge(
                $result,
                $this->getCronsFromFile($cronFile)
            );
        }

        return $result;
    }

    /**
     * Determine the type of a cron from its cron.xml definition.
     *
     * Required and automatically created crons are managed by the system.
     * Definitions without these properties are considered user-defined.
     *
     * @param array<string, mixed> $cron
     */
    public static function getCronType(array $cron): string
    {
        if (!empty($cron['required']) || !empty($cron['autocreate'])) {
            return self::CRON_TYPE_SYSTEM;
        }

        return self::CRON_TYPE_CUSTOM;
    }

    public function isSystemCron(string $exec): bool
    {
        foreach ($this->getAvailableCrons() as $definition) {
            if (
                ltrim((string)$definition['exec'], '\\') === ltrim($exec, '\\')
                && self::getCronType($definition) === self::CRON_TYPE_SYSTEM
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check whether a cron definition is restricted to CLI execution.
     *
     * Definitions without the cliOnly flag are available in every execution context.
     *
     * @param array<string, mixed> $cron
     */
    public static function isCliOnlyDefinition(array $cron): bool
    {
        $cliOnly = $cron['cliOnly'] ?? false;

        return $cliOnly === true || $cliOnly === 1 || $cliOnly === '1' || $cliOnly === 'true';
    }

    /**
     * Check whether the cron can be executed in the current environment.
     *
     * @param array<string, mixed> $cron
     */
    protected function canExecuteCron(array $cron): bool
    {
        return $this->isCliExecution() || !$this->isCliOnlyCron($cron);
    }

    /**
     * Check whether the current request is executed via CLI.
     */
    protected function isCliExecution(): bool
    {
        return PHP_SAPI === 'cli';
    }

    /**
     * Check whether a stored cron references a CLI-only definition.
     *
     * @param array<string, mixed> $cron
     */
    protected function isCliOnlyCron(array $cron): bool
    {
        $exec = (string)($cron['exec'] ?? '');

        if ($exec === '') {
            return false;
        }

        if ($this->cliOnlyExecutables === null) {
            $this->cliOnlyExecutables = [];

            foreach ($this->getAvailableCrons() as $availableCron) {
                if (!self::isCliOnlyDefinition($availableCron)) {
                    continue;
                }

                $availableExec = (string)($availableCron['exec'] ?? '');

                if ($availableExec !== '') {
                    $this->cliOnlyExecutables[$availableExec] = true;
                }
            }
        }

        return isset($this->cliOnlyExecutables[$exec]);
    }

    /**
     * Return the data of an inserted cron
     *
     * @param integer $cronId - ID of the Cron
     *
     * @return array<string, mixed>|false Cron data
     * @throws Exception
     */
    public function getCronById(int $cronId): bool | array
    {
        $QueryBuilder = QUI::getQueryBuilder();
        $result = $QueryBuilder
            ->select('*')
            ->from(QUI\Utils\Doctrine::quoteIdentifier($this->table()))
            ->where($QueryBuilder->expr()->eq('id', ':id'))
            ->setParameter('id', $cronId)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        if (!is_array($result)) {
            return false;
        }

        return $result;
    }

    /**
     * Return the data of a specific cron from the available cron list
     * This cron is not in the cron list
     *
     * @param string $cron - Cron-Identifier (package/package:NO) or name of the Cron or exec path of cron
     *
     * @return array<string, mixed>|false Cron data
     */
    public function getCronData(string $cron): bool | array
    {
        $availableCrons = $this->getAvailableCrons();

        // cron by package Identifier package/package:NO
        $cronParts = explode(':', $cron);

        try {
            $Package = QUI::getPackage($cronParts[0]);
            $cronFile = $Package->getXMLFilePath('cron.xml');

            if ($Package->isQuiqqerPackage() && $cronFile && isset($cronParts[1]) && is_numeric($cronParts[1])) {
                $cronNo = (int)$cronParts[1];
                $cronList = $this->getCronsFromFile($cronFile);

                if (isset($cronList[$cronNo])) {
                    return $cronList[$cronNo];
                }
            }
        } catch (QUI\Exception) {
        }

        // search cron via title
        foreach ($availableCrons as $entry) {
            if ($entry['title'] == $cron || $entry['exec'] == $cron) {
                return $entry;
            }
        }

        return false;
    }

    /**
     * Return the history list
     *
     * @param array<string, int|string> $params Select params -> (page, perPage)
     *
     * @return array<int, array<string, mixed>>
     * @throws Exception
     */
    public function getHistoryList(array $params = []): array
    {
        $firstResult = 0;
        $maxResults = 20;

        if (isset($params['perPage']) && isset($params['page'])) {
            $page = max(1, (int)$params['page']);
            $maxResults = max(1, (int)$params['perPage']);
            $firstResult = ($page - 1) * $maxResults;
        }

        $QueryBuilder = QUI::getQueryBuilder();
        $data = $QueryBuilder
            ->select('*')
            ->from(QUI\Utils\Doctrine::quoteIdentifier(self::tableHistory()))
            ->orderBy('lastexec', 'DESC')
            ->setFirstResult($firstResult)
            ->setMaxResults($maxResults)
            ->executeQuery()
            ->fetchAllAssociative();

        $QueryBuilder = QUI::getQueryBuilder();
        $dataOfCron = $QueryBuilder
            ->select('*')
            ->from(QUI\Utils\Doctrine::quoteIdentifier($this->table()))
            ->executeQuery()
            ->fetchAllAssociative();

        $Users = QUI::getUsers();
        $crons = [];
        $result = [];

        // create assoc cron data array
        foreach ($dataOfCron as $cronData) {
            $crons[$cronData['id']] = $cronData;
        }

        $Nobody = new QUI\Users\Nobody();
        $nobodyUsername = $Nobody->getUsername();

        foreach ($data as $entry) {
            $entry['cronTitle'] = '';
            $entry['username'] = '';

            if (isset($crons[$entry['cronid']])) {
                $entry['cronTitle'] = $crons[$entry['cronid']]['title'];
            }

            try {
                if (!empty($entry['uid'])) {
                    $username = $Users->get($entry['uid'])->getName();
                } else {
                    $username = $nobodyUsername;
                }

                $entry['username'] = $username;
            } catch (QUI\Exception) {
            }

            $result[] = $entry;
        }

        return $result;
    }

    /**
     * Return the history count, how many history entries exist
     *
     * @return integer
     * @throws Exception
     */
    public function getHistoryCount(): int
    {
        $QueryBuilder = QUI::getQueryBuilder();

        return (int)$QueryBuilder
            ->select('COUNT(id)')
            ->from(QUI\Utils\Doctrine::quoteIdentifier(self::tableHistory()))
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * Return the cron list
     *
     * @return array<int, array<string, mixed>>
     * @throws Exception
     */
    public function getList(): array
    {
        $QueryBuilder = QUI::getQueryBuilder();

        return $QueryBuilder
            ->select('*')
            ->from(QUI\Utils\Doctrine::quoteIdentifier(self::table()))
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Checks if a specific cron is already set up
     *
     * @param string $cron - cron title
     *
     * @return bool
     * @throws Exception
     */
    public function isCronSetUp(string $cron): bool
    {
        $list = $this->getList();

        foreach ($list as $entry) {
            if ($entry['title'] == $cron) {
                return true;
            }
        }

        return false;
    }

    /**
     * Exist the cron?
     *
     * @param string $cron - name of the cron
     *
     * @return Bool
     */
    protected function cronExists(string $cron): bool
    {
        return !($this->getCronData($cron) === false);
    }

    /**
     * Check if a specific cron exists based on its executed method and exact parameters.
     *
     * @param string $exec - Execution path to static class method
     * @param array<string, mixed> $params Cron parameters
     * @return bool
     *
     * @throws QUI\Exception
     */
    public function cronWithExecAndParamsExists(string $exec, array $params = []): bool
    {
        $QueryBuilder = QUI::getQueryBuilder();
        $result = $QueryBuilder
            ->select('params')
            ->from(QUI\Utils\Doctrine::quoteIdentifier(self::table()))
            ->where($QueryBuilder->expr()->eq('exec', ':exec'))
            ->setParameter('exec', $exec)
            ->executeQuery()
            ->fetchAllAssociative();

        if (empty($result)) {
            return false;
        }

        foreach ($result as $row) {
            $cronParams = json_decode($row['params'], true);

            if (!is_array($cronParams)) {
                continue;
            }

            if ($cronParams === $params) {
                return true;
            }
        }

        return false;
    }

    /**
     * static
     */

    /**
     * Return the cron table
     *
     * @return string
     */
    public static function table(): string
    {
        return QUI_DB_PRFX . 'cron';
    }

    /**
     * Return the cron table
     *
     * @return string
     */
    public static function tableHistory(): string
    {
        return QUI_DB_PRFX . 'cron_history';
    }

    /**
     * Return the Cron from an XML File
     *
     * @param string $file
     * @return array<int, array<string, mixed>>
     */
    public static function getCronsFromFile(string $file): array
    {
        if (!file_exists($file)) {
            return [];
        }

        $Dom = QUI\Utils\Text\XML::getDomFromXml($file);
        $crons = $Dom->getElementsByTagName('crons');

        if (!$crons->length) {
            return [];
        }

        $Crons = $crons->item(0);

        if (!$Crons instanceof DOMElement) {
            return [];
        }

        $list = $Crons->getElementsByTagName('cron');

        if (!$list->length) {
            return [];
        }

        $result = [];

        for ($i = 0; $i < $list->length; $i++) {
            $Cron = $list->item($i);

            if (!$Cron instanceof DOMElement) {
                continue;
            }

            $title = '';
            $desc = '';
            $required = false;
            $cliOnly = self::isCliOnlyDefinition([
                'cliOnly' => $Cron->getAttribute('cliOnly')
            ]);
            $params = [];

            $Title = $Cron->getElementsByTagName('title');
            $Desc = $Cron->getElementsByTagName('description');
            $Params = $Cron->getElementsByTagName('params');

            if (
                $Cron->getAttribute('required')
                && ($Cron->getAttribute('required') === '1' || $Cron->getAttribute('required') === 'true')
            ) {
                $required = true;
            }

            if ($Title->length) {
                $TitleNode = $Title->item(0);

                if ($TitleNode instanceof DOMElement) {
                    $title = QUI\Utils\DOM::getTextFromNode($TitleNode);
                }
            }

            if ($Desc->length) {
                $DescNode = $Desc->item(0);

                if ($DescNode instanceof DOMElement) {
                    $desc = QUI\Utils\DOM::getTextFromNode($DescNode);
                }
            }

            if ($Params->length) {
                $CronParams = false;

                for ($j = 0; $j < $Params->length; $j++) {
                    $ParamsNode = $Params->item($j);

                    if (!$ParamsNode instanceof DOMElement) {
                        continue;
                    }

                    if (
                        $ParamsNode->parentNode
                        && isset($ParamsNode->parentNode->tagName)
                        && $ParamsNode->parentNode->tagName === 'cron'
                    ) {
                        $CronParams = $ParamsNode->getElementsByTagName('param');
                        break;
                    }
                }

                if ($CronParams) {
                    foreach ($CronParams as $Param) {
                        /* @var $Param DOMElement */
                        $param = [
                            'name' => $Param->getAttribute('name'),
                            'type' => $Param->getAttribute('type'),
                            'data-qui' => $Param->getAttribute('data-qui'),
                            'desc' => false
                        ];

                        if ($Param->childNodes->length) {
                            $param['desc'] = QUI\Utils\DOM::getTextFromNode($Param);
                        }

                        $params[] = $param;
                    }
                }
            }

            // Autocreate entries
            $autocreate = [];
            $AutoCreate = $Cron->getElementsByTagName('autocreate');

            if ($AutoCreate->length) {
                /** @var DOMElement $AutoCreateEntry */
                foreach ($AutoCreate as $AutoCreateEntry) {
                    $Interval = $AutoCreateEntry->getElementsByTagName('interval');
                    $Active = $AutoCreateEntry->getElementsByTagName('active');
                    $AutoCreateParams = $AutoCreateEntry->getElementsByTagName('params');
                    $Scope = $AutoCreateEntry->getElementsByTagName('scope');

                    if (!$Interval->length) {
                        Log::addWarning(
                            'quiqqer/cron -> Cron "' . $Cron->getAttribute('exec') . '" from file'
                            . ' "' . $file . '" has an <autocreate> entry, but no <interval> set.'
                            . ' The <autocreate>-property is ignored.'
                        );

                        continue;
                    }

                    $IntervalNode = $Interval->item(0);

                    if (!$IntervalNode instanceof DOMElement) {
                        continue;
                    }

                    $interval = trim($IntervalNode->textContent);
                    [$min, $hour, $day, $month, $dayOfWeek] = explode(' ', $interval);

                    $min = trim($min);
                    $hour = trim($hour);
                    $day = trim($day);
                    $month = trim($month);
                    $dayOfWeek = trim($dayOfWeek);

                    // Test interval
                    try {
                        new CronExpression("$min $hour $day $month $dayOfWeek");
                    } catch (\Exception $Exception) {
                        Log::addWarning(
                            'quiqqer/cron -> Cron "' . $Cron->getAttribute('exec') . '" from file'
                            . ' "' . $file . '" has an <autocreate> entry, but the <interval>'
                            . ' is invalid: ' . $Exception->getMessage()
                            . ' The <autocreate>-property is ignored.'
                        );

                        continue;
                    }

                    // Params
                    $autoCreateParams = [];

                    if ($AutoCreateParams->length) {
                        $AutoCreateParamsNode = $AutoCreateParams->item(0);

                        if (!$AutoCreateParamsNode instanceof DOMElement) {
                            continue;
                        }

                        $AutoCreateParams = $AutoCreateParamsNode->getElementsByTagName('param');

                        foreach ($AutoCreateParams as $AutoCreateParam) {
                            $autoCreateParams[] = [
                                'name' => $AutoCreateParam->getAttribute('name'),
                                'value' => trim($AutoCreateParam->textContent)
                            ];
                        }
                    }

                    $autocreate[] = [
                        'interval' => "$min $hour $day $month $dayOfWeek",
                        'active' => $Active->length
                            && $Active->item(0) instanceof DOMElement
                            && $Active->item(0)->textContent,
                        'params' => $autoCreateParams,
                        'scope' => $Scope->length && $Scope->item(0) instanceof DOMElement
                            ? trim($Scope->item(0)->textContent)
                            : false,
                    ];
                }
            }

            $result[] = [
                'title' => $title,
                'description' => $desc,
                'required' => $required,
                'cliOnly' => $cliOnly,
                'exec' => $Cron->getAttribute('exec'),
                'params' => $params,
                'autocreate' => $autocreate
            ];
        }

        return $result;
    }

    /**
     * Print a message to the log cron.log
     *
     * @param string $message - Message
     */
    public static function log(string $message): void
    {
        if (self::isWriteCronLog()) {
            Log::addInfo($message, [], 'cron');
        }
    }

    /**
     * Write cron log?
     *
     * @return bool|null
     */
    protected static function isWriteCronLog(): ?bool
    {
        if (!is_null(self::$writeCronLog)) {
            return self::$writeCronLog;
        }

        try {
            $Config = QUI::getPackage('quiqqer/cron')->getConfig();

            if (!$Config) {
                self::$writeCronLog = false;
                return self::$writeCronLog;
            }

            self::$writeCronLog = boolval(
                $Config->get(
                    'settings',
                    'writeCronLog'
                )
            );
        } catch (\Exception $Exception) {
            Log::writeException($Exception);
            self::$writeCronLog = false;
        }

        return self::$writeCronLog;
    }

    /**
     * Send admin notification when cron lock time is exceeded.
     *
     * @return void
     */
    protected static function sendCronLockTimeoutNotification(): void
    {
        // Check if notification shall be sent
        if (self::$lockTimeoutNotificationSent) {
            return;
        }

        try {
            $Conf = QUI::getPackage('quiqqer/cron')->getConfig();

            if (!$Conf) {
                return;
            }

            if (empty($Conf->get('settings', 'cron_lock_timeout_notification'))) {
                return;
            }
        } catch (\Exception $Exception) {
            Log::writeException($Exception);

            return;
        }

        $adminMail = QUI::conf('mail', 'admin_mail');

        if (empty($adminMail)) {
            Log::addWarning(
                'quiqqer/cron -> Cannot send lock timeout notification since no administrator e-mail is configured in'
                . ' this QUIQQER system.'
            );

            return;
        }

        try {
            $Mailer = new QUI\Mail\Mailer();
            $Mailer->addRecipient($adminMail);

            $L = QUI::getLocale();

            $Mailer->setSubject(
                $L->get('quiqqer/cron', 'notification.lock_timeout.subject')
            );

            if (self::$runtime['lockEnd'] === false) {
                return;
            }

            $End = date_create(self::$runtime['lockEnd']);
            $Now = date_create();

            if ($End === false || $Now === false) {
                return;
            }

            $TimeDiff = $End->diff($Now);

            $Mailer->setBody(
                $L->get(
                    'quiqqer/cron',
                    'notification.lock_timeout.body',
                    array_merge(self::$runtime, [
                        'host' => QUI::conf('globals', 'host'),
                        'diff' => $TimeDiff->format('%H:%M:%S')
                    ])
                )
            );

            $Mailer->send();

            self::$lockTimeoutNotificationSent = true;
        } catch (\Exception $Exception) {
            Log::writeException($Exception);
        }
    }

    /**
     * Get cron lock time
     *
     * @return int - Lock time (seconds)
     */
    protected static function getLockTime(): int
    {
        try {
            $Conf = QUI::getPackage('quiqqer/cron')->getConfig();

            if (!$Conf) {
                return 1440;
            }

            $lockTime = $Conf->get('settings', 'cron_lock_time');

            if (empty($lockTime)) {
                return 1440;
            }

            return $lockTime;
        } catch (\Exception $Exception) {
            Log::writeException($Exception);

            return 1440;
        }
    }
}
