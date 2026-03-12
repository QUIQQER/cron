<?php

/**
 * Return the available updates
 *
 * @return array
 */

use QUI\Cron\Manager;

QUI::getAjax()->registerFunction(
    'package_quiqqer_cron_ajax_updateCheck',
    function () {
        // only execute if quiqqer is completely set up
        if (Manager::isQuiqqerInstallerExecuted() === false) {
            return [];
        }

        return QUI\Cron\Update::getAvailableUpdates();
    },
    false,
    'Permission::checkAdminUser'
);
