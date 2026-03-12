<?php

/**
 * activate a cron
 *
 * @param integer $cronId - Cron-ID
 */

QUI::getAjax()->registerFunction(
    'package_quiqqer_cron_ajax_cron_activate',
    function ($cronId) {
        $Manager = new QUI\Cron\Manager();
        $Manager->activateCron($cronId);
    },
    ['cronId'],
    'Permission::checkAdminUser'
);
