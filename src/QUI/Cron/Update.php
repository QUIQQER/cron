<?php

/**
 * This file contains QUI\Cron\Update
 */

namespace QUI\Cron;

use QUI;

use function array_filter;
use function count;
use function file_exists;
use function file_get_contents;
use function is_array;
use function json_decode;
use function json_encode;
use function file_put_contents;
use function unlink;

/**
 * Update cron
 * - checked if updates are available
 */
class Update
{
    //region check for updates

    /**
     * Execute the update check, if auto update is active
     *
     * @return void
     * @throws QUI\Exception|\PHPMailer\PHPMailer\Exception
     */
    public static function check(): void
    {
        try {
            $Package = QUI::getPackage('quiqqer/cron');
            $Config = $Package->getConfig();
        } catch (\Exception) {
            return;
        }

        if (!$Config) {
            return;
        }

        if (!$Config->get('update', 'auto_check')) {
            return;
        }

        self::checkExecute();
    }

    /**
     * Execute the update check
     * - if updates are available, an email will be sent
     *
     * @return void
     * @throws QUI\Exception|\PHPMailer\PHPMailer\Exception
     */
    public static function checkExecute(): void
    {
        try {
            $Packages = QUI::getPackageManager();
            $packages = $Packages->getOutdated(true);
            $file = QUI::getPackage('quiqqer/cron')->getVarDir() . 'updates';
        } catch (\Exception $Exception) {
            QUI\System\Log::writeException($Exception);
            return;
        }

        // filter - dev-dev and dev-master
        $filteredPackages = array_filter($packages, function ($package) {
            if (str_contains($package['version'], 'dev-')) {
                return false;
            }

            return true;
        });

        if (count($filteredPackages)) {
            file_put_contents($file, json_encode($packages));

            $updateString = '<ul>';

            foreach ($packages as $package) {
                $packageName = $package['package'];
                $from = $package['oldVersion'];
                $to = $package['version'];

                $updateString .= '<li>' . $packageName . ': ' . $from . ' -> ' . $to . '</li>';
            }

            $updateString .= '<ul>';


            QUI::getMailManager()->send(
                QUI::conf('mail', 'admin_mail'),
                QUI::getLocale()->get('quiqqer/cron', 'update.mail.updateCheck.subject', [
                    'system' => QUI::conf('globals', 'host')
                ]),
                QUI::getLocale()->get('quiqqer/cron', 'update.mail.updateCheck.description', [
                    'packages' => $updateString,
                    'host' => HOST,
                    'ip' => QUI\Utils\System::getClientIP()
                ])
            );

            return;
        }

        if (file_exists($file)) {
            unlink($file);
        }
    }

    //endregion

    //region execute update

    /**
     * execute an update, if auto update is active
     *
     * @return void
     * @throws QUI\Exception|\PHPMailer\PHPMailer\Exception
     */
    public static function update(): void
    {
        try {
            $Package = QUI::getPackage('quiqqer/cron');
            $Config = $Package->getConfig();
        } catch (\Exception) {
            return;
        }

        if (!$Config) {
            return;
        }

        if (!$Config->get('update', 'auto_update')) {
            return;
        }

        self::updateExecute();
    }

    /**
     * Execute an system update
     *
     * @return void
     * @throws QUI\Exception|\PHPMailer\PHPMailer\Exception
     */
    public static function updateExecute(): void
    {
        $Config = QUI::getConfig('etc/conf.ini.php');
        $Config->set('globals', 'maintenance', 1);
        $Config->save();

        try {
            $Packages = QUI::getPackageManager();
            $packages = $Packages->getOutdated(true);
        } catch (\Exception $Exception) {
            QUI\System\Log::writeException($Exception);
            return;
        }

        if (!count($packages)) {
            return;
        }

        $updateString = '<ul>';

        foreach ($packages as $package) {
            $packageName = $package['package'];
            $from = $package['oldVersion'];
            $to = $package['version'];

            $updateString .= '<li>' . $packageName . ': ' . $from . ' -> ' . $to . '</li>';
        }

        $updateString .= '<ul>';


        try {
            $Packages->update();
        } catch (\Exception) {
            QUI::getMailManager()->send(
                QUI::conf('mail', 'admin_mail'),
                QUI::getLocale()->get('quiqqer/cron', 'update.mail.error.subject'),
                QUI::getLocale()->get('quiqqer/cron', 'update.mail.error.body', [
                    'packages' => $updateString,
                    'host' => HOST,
                    'ip' => QUI\Utils\System::getClientIP()
                ])
            );

            return;
        }

        QUI::getMailManager()->send(
            QUI::conf('mail', 'admin_mail'),
            QUI::getLocale()->get('quiqqer/cron', 'update.mail.success.subject'),
            QUI::getLocale()->get('quiqqer/cron', 'update.mail.success.body', [
                'packages' => $updateString,
                'host' => HOST,
                'ip' => QUI\Utils\System::getClientIP()
            ])
        );

        $Config->set('globals', 'maintenance', 0);
        $Config->save();
    }

    //endregion

    //region utils

    /**
     * @param array<int, array<string, mixed>> $packages
     * @return void
     * @throws QUI\Exception
     */
    public static function setAvailableUpdates(array $packages = []): void
    {
        $file = QUI::getPackage('quiqqer/cron')->getVarDir() . 'updates';

        if (count($packages)) {
            file_put_contents($file, json_encode($packages));
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     * @throws QUI\Exception
     */
    public static function getAvailableUpdates(): array
    {
        $file = QUI::getPackage('quiqqer/cron')->getVarDir() . 'updates';

        if (!file_exists($file)) {
            return [];
        }

        $json = file_get_contents($file);

        if ($json === false) {
            return [];
        }

        $result = json_decode($json, true);

        if (!is_array($result)) {
            return [];
        }

        return $result;
    }

    /**
     * If update file exists, this file will be deleted
     *
     * @return void
     * @throws QUI\Exception
     */
    public static function clearUpdateCheck(): void
    {
        $file = QUI::getPackage('quiqqer/cron')->getVarDir() . 'updates';

        if (file_exists($file)) {
            unlink($file);
        }
    }

    //endregion
}
