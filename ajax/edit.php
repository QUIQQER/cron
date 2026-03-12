<?php

/**
 * Edit a cron
 *
 * @param String $cronId
 * @param String $cron
 * @param String $min
 * @param String $hour
 * @param String $day
 * @param String $month
 * @param String $dayOfWeek
 * @param String $params
 */

QUI::getAjax()->registerFunction(
    'package_quiqqer_cron_ajax_edit',
    function ($cronId, $cron, $min, $hour, $day, $month, $dayOfWeek, $params) {
        $params = json_decode($params, true);

        $Manager = new QUI\Cron\Manager();
        $Manager->edit($cronId, $cron, $min, $hour, $day, $month, $dayOfWeek, $params);
    },
    ['cronId', 'cron', 'min', 'hour', 'day', 'month', 'dayOfWeek', 'params'],
    'Permission::checkAdminUser'
);
