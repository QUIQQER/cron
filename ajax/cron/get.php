<?php

/**
 * activate a cron
 *
 * @param integer $cronId - Cron-ID
 *
 * @return array
 */

QUI::getAjax()->registerFunction(
    'package_quiqqer_cron_ajax_cron_get',
    function ($cronId) {
        $Manager = new QUI\Cron\Manager();

        $cron = $Manager->getCronById($cronId);

        if (is_array($cron)) {
            $cron['system'] = $Manager->isSystemCron((string)$cron['exec']);
        }

        return $cron;
    },
    ['cronId'],
    'Permission::checkAdminUser'
);
