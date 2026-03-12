<?php

/**
 * Toggle the status of a cron
 *
 * @param integer $cronId
 *
 * @throws \QUI\Exception
 */

QUI::getAjax()->registerFunction(
    'package_quiqqer_cron_ajax_cron_toggle',
    function ($cronId) {
        $Manager = new QUI\Cron\Manager();
        $data = $Manager->getCronById($cronId);

        if (!$data) {
            throw new QUI\Exception('Cron not exists', 404);
        }

        if ($data['active'] == 1) {
            $Manager->deactivateCron($cronId);
        } else {
            $Manager->activateCron($cronId);
        }
    },
    ['cronId'],
    'Permission::checkAdminUser'
);
