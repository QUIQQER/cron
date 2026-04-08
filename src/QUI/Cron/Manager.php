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

use function array_filter;
use function array_key_exists;
use function array_merge;
use function boolval;
use function count;
use function date;
use function date_create;
use function date_interval_create_from_date_string;
use function explode;
use function is_callable;
use function is_null;
use function json_decode;
use function microtime;
use function round;
use function time;
use function trim;

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

        QUI::getDataBase()->insert($this->table(), [
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

        // test the cron data
        try {
            new CronExpression("$min $hour $day $month $dayOfWeek");
        } catch (\Exception $Exception) {
            throw new QUI\Exception($Exception->getMessage());
        }

        QUI::getDataBase()->update($this->table(), [
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

        QUI::getDataBase()->update(
            $this->table(),
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

        QUI::getDataBase()->update(
            $this->table(),
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


        $DataBase = QUI::getDataBase();

        foreach ($ids as $id) {
            $id = (int)$id;

            if ($this->getCronById($id) === false) {
                return;
            }

            $DataBase->delete($this->table(), [
                'id' => $id
            ]);
        }
    }

    /**
     * Execute all upcoming cron jobs
     *
     * @param bool $force - force execution
     * @throws QUI\Permissions\Exception|Exception
     */
    public function execute(bool $force = false): void
    {
        Manager::log('Start cron execution (all crons)');

        // locking
        $lockKey = self::EXECUTION_LOCK_KEY;

        $Package = null;
        $Start = date_create();

        if ($Start === false) {
            $Start = new DateTime();
        }

        $EndTime = clone $Start;

        self::$runtime['startAll'] = $Start->format('Y-m-d H:i:s');

        if ($force === false) {
            try {
                $Package = QUI::getPackage('quiqqer/cron');

                if (QUI\Lock\Locker::isLocked($Package, $lockKey, null, false)) {
                    $time = QUI\Lock\Locker::getLockTime($Package, $lockKey);

                    if ($time < 0) {
                        Manager::log(
                            'Crons cannot be executed because another instance is already executing crons.'
                        );

                        return;
                    }
                }

                $lockTime = self::getLockTime(); // lock time in seconds
                $Interval = date_interval_create_from_date_string($lockTime . ' seconds');

                if ($Interval === false) {
                    throw new QUI\Exception('Could not create lock timeout interval.');
                }

                $EndTime = $EndTime->add($Interval);

                self::$runtime['lockEnd'] = $EndTime->format('Y-m-d H:i:s');

                QUI\Lock\Locker::lock($Package, $lockKey, $lockTime);
            } catch (\Exception $Exception) {
                Log::writeDebugException($Exception);
                Log::writeRecursive($Exception->getMessage());

                Manager::log(
                    'Crons cannot be executed due to an error: ' . $Exception->getMessage()
                );

                return;
            }
        }

        Permission::checkPermission('quiqqer.cron.execute');

        $list = $this->getList();

        $activeList = array_filter($list, function ($entry) {
            return $entry['active'] == 1;
        });

        self::$runtime['total'] = count($activeList);

        foreach ($activeList as $entry) {
            $cronExpression = $this->getCronExpression($entry);

            try {
                $lastExecutionDate = !empty($entry['lastexec']) ?
                    new DateTimeImmutable($entry['lastexec']) :
                    null;

                if (!$this->shouldExecuteCron($entry, $lastExecutionDate)) {
                    self::$runtime['finished']++;
                    continue;
                }
            } catch (\Exception $Exception) {
                Log::addError(
                    'Could not evaluate cron expression "' . $cronExpression . '" for cron'
                    . ' (Cron "' . $entry['title'] . '" #' . $entry['id'] . ').'
                    . ' Error :: ' . $Exception->getMessage()
                );

                continue;
            }

            // execute cron
            try {
                self::$runtime['startCurrent'] = date('Y-m-d H:i:s');
                self::$runtime['currentCronId'] = $entry['id'];
                self::$runtime['currentCronTitle'] = $entry['title'];

                $this->executeCron($entry['id']);

                self::$runtime['finished']++;

                $Now = date_create();

                if ($Now > $EndTime) {
                    self::sendCronLockTimeoutNotification();
                }
            } catch (\Exception $Exception) {
                $message = print_r($entry, true);
                $message .= "\n" . $Exception->getMessage();

                Log::addError($message);

                #self::log($message);
                QUI::getMessagesHandler()->addError($message);
            }
        }

        Manager::log('Finish cron execution (all crons)');

        if ($force === false) {
            try {
                self::unlockExecutionLock();
            } catch (\Exception $Exception) {
                Log::writeDebugException($Exception);
            }
        }
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
     * @param DateTimeInterface|null $lastExecutionDate - If null, the current time is used
     * @throws DateMalformedStringException
     */
    protected function shouldExecuteCron(
        array $entry,
        ?DateTimeInterface $lastExecutionDate = null
    ): bool {
        $cronExpression = new CronExpression($this->getCronExpression($entry));
        $currentDateTime = $this->getCurrentDateTime();

        // If cron was never executed -> check next execution date
        if ($lastExecutionDate === null) {
            return $cronExpression->isDue($currentDateTime);
        }

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
        $Package = QUI::getPackage('quiqqer/cron');

        QUI\Lock\Locker::unlock($Package, self::EXECUTION_LOCK_KEY);
    }

    /**
     * Execute a cron
     *
     * @throws QUI\Exception
     */
    public function executeCron(int $cronId): static
    {
        Permission::checkPermission('quiqqer.cron.execute');


        $cronData = $this->getCronById($cronId);
        $params = [];

        if (!$cronData) {
            throw new QUI\Exception('Cron ID not exist');
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

        QUI::getDataBase()->insert(self::tableHistory(), [
            'cronid' => $cronId,
            'lastexec' => date('Y-m-d H:i:s', $starTime),
            'finish' => date('Y-m-d H:i:s'),
            'uid' => QUI::getUserBySession()->getUUID() ?: 0
        ]);


        QUI::getDataBase()->update(
            self::table(),
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
     * Return the data of an inserted cron
     *
     * @param integer $cronId - ID of the Cron
     *
     * @return array<string, mixed>|false Cron data
     * @throws Exception
     */
    public function getCronById(int $cronId): bool | array
    {
        $result = QUI::getDataBase()->fetch([
            'from' => $this->table(),
            'where' => [
                'id' => $cronId
            ],
            'limit' => 1
        ]);

        if (!isset($result[0])) {
            return false;
        }

        return $result[0];
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
        $limit = '0,20';
        $order = 'lastexec DESC';

        if (isset($params['perPage']) && isset($params['page'])) {
            $start = (int)$params['page'] - 1;
            $limit = $start . ',' . (int)$params['perPage'];
        }

        $data = QUI::getDataBase()->fetch([
            'from' => self::tableHistory(),
            'limit' => $limit,
            'order' => $order
        ]);

        $dataOfCron = QUI::getDataBase()->fetch([
            'from' => $this->table()
        ]);

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
        $result = QUI::getDataBase()->fetch([
            'from' => self::tableHistory(),
            'count' => 'id'
        ]);

        return $result[0]['id'];
    }

    /**
     * Return the cron list
     *
     * @return array<int, array<string, mixed>>
     * @throws Exception
     */
    public function getList(): array
    {
        return QUI::getDataBase()->fetch([
            'from' => self::table()
        ]);
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
        $result = QUI::getDataBase()->fetch([
            'select' => ['params'],
            'from' => self::table(),
            'where' => [
                'exec' => $exec
            ]
        ]);

        if (empty($result)) {
            return false;
        }

        foreach ($result as $row) {
            $cronParams = json_decode($row['params'], true);
            $identical = true;

            foreach ($cronParams as $k => $v) {
                if (!array_key_exists($k, $params) || $params[$k] !== $v) {
                    $identical = false;
                    break;
                }
            }

            if ($identical) {
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
