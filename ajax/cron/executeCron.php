<?php

/**
 * deactivate a cron
 *
 * @param integer $cronId - Cron-ID
 */

QUI::getAjax()->registerFunction(
    'package_quiqqer_cron_ajax_cron_executeCron',
    function ($cronId) {
        $Manager = new QUI\Cron\Manager();
        $Manager->executeCron($cronId);
    },
    ['cronId'],
    'Permission::checkAdminUser'
);
