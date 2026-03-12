<?php

/**
 * Execute the cron list
 */

use QUI\Cron\Manager;

QUI::getAjax()->registerFunction(
    'package_quiqqer_cron_ajax_execute',
    function () {
        // only execute if quiqqer is completely set up
        if (Manager::isQuiqqerInstallerExecuted() === false) {
            return;
        }

        // not execute at the first log in
        if (QUI::getPackage('quiqqer/cron')->getConfig()->get('update', 'logged_in_before') === false) {
            QUI::getPackage('quiqqer/cron')->getConfig()->set('update', 'logged_in_before', 1);
            QUI::getPackage('quiqqer/cron')->getConfig()->save();
            return;
        }

        try {
            $Manager = new QUI\Cron\Manager();
            $Manager->execute();
        } catch (QUI\Exception $Exception) {
            QUI\System\Log::addError(
                'package_quiqqer_cron_ajax_execute() :: ' . $Exception->getMessage()
            );
        }

        QUI::getMessagesHandler()->clear();
    },
    false,
    'Permission::checkAdminUser'
);
