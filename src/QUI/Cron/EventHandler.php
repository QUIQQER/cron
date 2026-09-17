<?php

/**
 * This file contains QUI\Cron\Events
 */

namespace QUI\Cron;

use DateTime;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ColumnDiff;
use Doctrine\DBAL\Schema\TableDiff;
use Doctrine\DBAL\Types\Type;
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
        try {
            $historyTable = Manager::tableHistory();

            if (self::migrateLegacyCronHistoryPrimaryKey($historyTable)) {
                QUI\Update::importDatabase(OPT_DIR . 'quiqqer/cron/database.xml');
            }

            self::ensureStringColumnLength(Manager::table(), 'title', 1000);
            self::ensureStringColumnLength($historyTable, 'uid', 50);
        } catch (\Doctrine\DBAL\Exception $Exception) {
            QUI\System\Log::writeDebugException($Exception);
        }
    }

    /**
     * @throws \Doctrine\DBAL\Exception
     */
    private static function migrateLegacyCronHistoryPrimaryKey(string $tableName): bool
    {
        $table = QUI::getSchemaManager()->introspectTable($tableName);
        $primaryKey = $table->getPrimaryKeyConstraint();
        $primaryColumns = array_map(
            static fn($columnName): string => $columnName->getIdentifier()->getValue(),
            $primaryKey?->getColumnNames() ?? []
        );

        if ($primaryColumns !== ['cronid', 'lastexec']) {
            return false;
        }

        $quotedTable = QUI\Utils\Doctrine::quoteIdentifier($tableName);
        $quotedId = QUI\Utils\Doctrine::quoteIdentifier('id');
        $idColumnDefinition = "ADD COLUMN $quotedId INT NOT NULL AUTO_INCREMENT FIRST";

        if ($table->hasColumn('id')) {
            if (!$table->getColumn('id')->getType() instanceof \Doctrine\DBAL\Types\IntegerType) {
                return false;
            }

            $idColumnDefinition = "MODIFY COLUMN $quotedId INT NOT NULL AUTO_INCREMENT";
        }

        QUI::getDataBaseConnection()->executeStatement(
            "ALTER TABLE $quotedTable
                DROP PRIMARY KEY,
                $idColumnDefinition,
                ADD PRIMARY KEY ($quotedId)"
        );

        return true;
    }


    /**
     * @throws \Doctrine\DBAL\Exception
     */
    private static function ensureStringColumnLength(string $tableName, string $columnName, int $length): void
    {
        $SchemaManager = QUI::getSchemaManager();
        $Table = $SchemaManager->introspectTable($tableName);

        if (!$Table->hasColumn($columnName)) {
            return;
        }

        $Column = $Table->getColumn($columnName);

        if ($Column->getType() instanceof \Doctrine\DBAL\Types\StringType && $Column->getLength() === $length) {
            return;
        }

        $SchemaManager->alterTable(new TableDiff(
            $Table,
            changedColumns: [
                $columnName => new ColumnDiff(
                    $Column,
                    new Column(
                        $columnName,
                        Type::getType('string'),
                        ['length' => $length]
                    )
                )
            ]
        ));
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

        if (!$Config) {
            return;
        }

        // send admin info
        if (!$Config->get('settings', 'showAdminMessageIfCronNotRun')) {
            return;
        }

        // check last cron execution
        $thresholdDate = new DateTime('-24 hours');
        $thresholdFormatted = $thresholdDate->format('Y-m-d H:i:s');

        $QueryBuilder = QUI::getQueryBuilder();
        $result = $QueryBuilder
            ->select('id')
            ->from(QUI\Utils\Doctrine::quoteIdentifier(Manager::table()))
            ->where($QueryBuilder->expr()->gte('lastexec', ':threshold'))
            ->setParameter('threshold', $thresholdFormatted)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

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

        if (!$Config) {
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

                        $cronId = QUI::getDataBaseConnection()->lastInsertId();

                        if (!$autocreate['active']) {
                            QUI::getDataBaseConnection()->update(
                                QUI\Utils\Doctrine::quoteIdentifier($CronManager::table()),
                                ['active' => 0],
                                ['id' => (int)$cronId]
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
     * @param array<int, array{name: string, value: string}> $params
     * @return array<int, array<int, array{name: string, value: string}>>
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

                foreach ($projectCronParams as $key => $param) {
                    $projectCronParams[$key]['value'] = str_replace(
                        ['[projectName]', '[projectLang]'],
                        [$projectName, $language],
                        $param['value']
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
     * @param array<int, array{name: string, value: string}> $params
     * @return array<int, array<int, array{name: string, value: string}>>
     */
    protected static function getCronsToCreateForLanguagesScope(array $params): array
    {
        $createCrons = [];

        foreach (QUI::availableLanguages() as $language) {
            $projectCronParams = $params;

            foreach ($projectCronParams as $key => $param) {
                $projectCronParams[$key]['value'] = str_replace(
                    ['[lang]',],
                    [$language],
                    $param['value']
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
