<?php

/**
 * return the cron history
 *
 * @param {array} $params - filter params
 * @return array
 */

QUI::getAjax()->registerFunction(
    'package_quiqqer_cron_ajax_history_get',
    function ($params) {
        $CronManager = new QUI\Cron\Manager();
        $params = json_decode($params, true);

        return [
            'page' => (int)$params['page'],
            'data' => $CronManager->getHistoryList($params),
            'total' => $CronManager->getHistoryCount()
        ];
    },
    ['params'],
    'Permission::checkAdminUser'
);
