<?php

/**
 * This file contains QUI\Cron\Events
 */

namespace QUI\Cron;

use DateTime;
use QUI;
use QUI\Exception;
use QUI\System\Console\Tools\MigrationV2;

use function explode;
use function str_replace;

/**
 * Cron Main Events
 */
class EventHandler
{
    protected static bool $cronWarning = false;

    /**
     * event: onPackageSetup
     *
     * @param QUI\Package\Package $Package
     */
    public static function onPackageSetup(QUI\Package\Package $Package): void
    {
        if ($Package->getName() === 'quiqqer/cron') {
            self::checkCronTable();
        }

        self::createAutoCreateCrons(null, true);
    }

    /**
     * @return void
     * @throws Exception
     */
    public static function updateEnd(): void
    {
        QUI\Cron\Update::clearUpdateCheck();
    }

    /**
     * Checks if the table cron is correct
     *
     * @return void
     */
    protected static function checkCronTable(): void
    {
        $categoryColumn = QUI::getDataBase()->table()->getColumn('cron', 'title');

        if ($categoryColumn['Type'] !== 'varchar(1000)') {
            $Statement = QUI::getDataBase()->getPDO()->prepare("ALTER TABLE cron MODIFY `title` VARCHAR(1000)");
            $Statement->execute();
        }

        $cronHistoryUidColumn = QUI::getDataBase()->table()->getColumn('cron_history', 'uid');

        if ($cronHistoryUidColumn['Type'] !== 'varchar(50)') {
            $Statement = QUI::getDataBase()->getPDO()->prepare("ALTER TABLE cron_history MODIFY `uid` VARCHAR(50)");
            $Statement->execute();
        }
    }

    /**
     * event : on admin header loaded
     * @throws \Doctrine\DBAL\Exception
     */
    public static function onAdminLoad(): void
    {
        if (!defined('ADMIN')) {
            return;
        }

        if (!ADMIN) {
            return;
        }

        $User = QUI::getUserBySession();

        if (!$User->isSU()) {
            return;
        }

        try {
            $Package = QUI::getPackageManager()->getInstalledPackage('quiqqer/cron');
            $Config = $Package->getConfig();
        } catch (QUI\Exception) {
            return;
        }


        // send admin info
        if (!$Config->get('settings', 'showAdminMessageIfCronNotRun')) {
            return;
        }

        // check last cron execution
        $database = QUI::getDataBaseConnection();
        $table = Manager::table();

        // Zeitstempel 24 Stunden zurück
        $thresholdDate = new DateTime('-24 hours');
        $thresholdFormatted = $thresholdDate->format('Y-m-d H:i:s');

        // Alle Crons mit lastexec NULL oder älter als 24h
        $sql = "
            SELECT id
            FROM $table
            WHERE lastexec >= :threshold
            LIMIT 1
        ";

        $result = $database->fetchOne($sql, [
            'threshold' => $thresholdFormatted
        ]);

        if ($result === false) {
            self::sendAdminInfoCronError();
        }
    }

    /**
     * event : on admin loaded -> footer output
     */
    public static function adminLoadFooter(): void
    {
        try {
            $Package = QUI::getPackageManager()->getInstalledPackage('quiqqer/cron');
            $Config = $Package->getConfig();
        } catch (QUI\Exception) {
            return;
        }

        echo '
            <script>
            window.addEvent("load", function() {
                require(["package/quiqqer/cron/bin/UpdateInfo"]);
            });
            </script>
        ';

        // execute cron ?
        if ($Config->get('settings', 'executeOnAdminLogin')) {
            echo '<script src="' . URL_OPT_DIR . 'quiqqer/cron/bin/executeCronViaAdmin.js"></script>';
        }

        if (self::$cronWarning) {
            echo '<script src="' . URL_OPT_DIR . 'quiqqer/cron/bin/noRunWarning.js"></script>';
        }
    }

    /**
     * send a message to the user, maybe an error in the crons exist
     * last 24h was no cron sended
     */
    public static function sendAdminInfoCronError(): void
    {
        if (Manager::isQuiqqerInstallerExecuted() === false) {
            return;
        }

        QUI::getMessagesHandler()->sendAttention(
            QUI::getUserBySession(),
            QUI::getUserBySession()->getLocale()->get('quiqqer/cron', 'message.cron.admin.info.24h')
        );

        self::$cronWarning = true;
    }

    /**
     * Event: onPackageInstall => Add default crons
     *
     * @param QUI\Package\Package $Package
     */
    public static function onPackageInstall(QUI\Package\Package $Package): void
    {
        self::createAutoCreateCrons();
    }

    /**
     * Event: onCreateProject => Add the publishing cron for this project
     *
     * @param QUI\Projects\Project $Project
     */
    public static function onCreateProject(QUI\Projects\Project $Project): void
    {
        self::createAutoCreateCrons(Manager::AUTOCREATE_SCOPE_PROJECTS);
    }

    /**
     * Create all crons with a <autocreate> items.
     *
     * @param string|null $scope (optional) - Only create crons for given scope (see Manager::AUTOCREATE_SCOPE_*)
     * @param bool $onlyRequired - Only create required crons
     * @return void
     */
    public static function createAutoCreateCrons(
        ?string $scope = null,
        bool $onlyRequired = false
    ): void {
        $CronManager = new Manager();

        foreach ($CronManager->getAvailableCrons() as $cron) {
            $title = $cron['title'];
            $exec = $cron['exec'];
            $required = $cron['required'];

            if ($onlyRequired && $required === false) {
                continue;
            }

            if (empty($cron['autocreate'])) {
                continue;
            }

            foreach ($cron['autocreate'] as $autocreate) {
                // Check if cron already exists
                $params = $autocreate['params'];
                [$min, $hour, $day, $month, $dayOfWeek] = explode(' ', $autocreate['interval']);

                // Parse params by scope and placeholders
                if ($scope && $scope !== $autocreate['scope']) {
                    continue;
                }

                $createWithParams = match ($autocreate['scope']) {
                    Manager::AUTOCREATE_SCOPE_PROJECTS => self::getCronsToCreateForProjectsScope($params),
                    Manager::AUTOCREATE_SCOPE_LANGUAGES => self::getCronsToCreateForLanguagesScope($params),
                    default => [$params],
                };

                // Create crons
                foreach ($createWithParams as $createParams) {
                    try {
                        if ($CronManager->cronWithExecAndParamsExists($exec, $createParams)) {
                            continue;
                        }
                    } catch (\Exception $Exception) {
                        QUI\System\Log::writeException($Exception);
                        continue;
                    }

                    $createParams['exec'] = $exec;

                    try {
                        $CronManager->add($title, $min, $hour, $day, $month, $dayOfWeek, $createParams);

                        $cronId = QUI::getDataBase()->getPDO()->lastInsertId('id');

                        if (!$autocreate['active']) {
                            QUI::getDataBase()->update(
                                $CronManager::table(),
                                ['active' => 0],
                                ['id' => $cronId]
                            );
                        }
                    } catch (\Exception $Exception) {
                        QUI\System\Log::writeException($Exception);
                    }
                }
            }
        }
    }

    /**
     * Get all crons to create for autocreate scope "projects".
     *
     * @param array $params
     * @return array
     */
    protected static function getCronsToCreateForProjectsScope(array $params): array
    {
        $createCrons = [];

        try {
            $projects = QUI::getProjectManager()::getProjects(true);
        } catch (\Exception $Exception) {
            QUI\System\Log::writeException($Exception);
            return $createCrons;
        }

        /** @var QUI\Projects\Project $Project */
        foreach ($projects as $Project) {
            $projectName = $Project->getName();

            foreach ($Project->getLanguages() as $language) {
                $projectCronParams = $params;

                foreach ($projectCronParams as $k => $v) {
                    $projectCronParams[$k] = str_replace(
                        ['[projectName]', '[projectLang]'],
                        [$projectName, $language],
                        $v
                    );
                }

                $createCrons[] = $projectCronParams;
            }
        }

        return $createCrons;
    }

    /**
     * Get all crons to create for autocreate scope "languages".
     *
     * @param array $params
     * @return array
     */
    protected static function getCronsToCreateForLanguagesScope(array $params): array
    {
        $createCrons = [];

        foreach (QUI::availableLanguages() as $language) {
            $projectCronParams = $params;

            foreach ($projectCronParams as $k => $v) {
                $projectCronParams[$k] = str_replace(
                    ['[lang]',],
                    [$language],
                    $v
                );
            }

            $createCrons[] = $projectCronParams;
        }

        return $createCrons;
    }

    /**
     * @throws QUI\Database\Exception
     */
    public static function onQuiqqerMigrationV2(MigrationV2 $Console): void
    {
        $Console->writeLn('- Migrate cron history');
        $count = (new Manager())->getHistoryCount();

        if ($count > 100000) {
            $Console->writeLn(
                'cron history table has more than 100000 entries. skip the migration. 
                please have a look and empty or decimate the table if necessary.',
                'red'
            );

            $Console->resetColor();
            return;
        }

        QUI\Utils\MigrationV1ToV2::migrateUsers(
            QUI::getDBTableName('cron_history'),
            ['uid'],
            'cronid'
        );

        QUI\Utils\MigrationV1ToV2::migrateUsers(
            QUI::getDBTableName('cron_cronservice'),
            ['uid'],
            'cronid'
        );
    }
}
