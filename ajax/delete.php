<?php

/**
 * Delete a cron to the cron list
 *
 * @param string $ids - json array
 */

QUI::getAjax()->registerFunction(
    'package_quiqqer_cron_ajax_delete',
    function ($ids) {
        $ids = json_decode($ids, true);
        $Manager = new QUI\Cron\Manager();

        $Manager->deleteCronIds($ids);
    },
    ['ids'],
    'Permission::checkAdminUser'
);
