<?php

QUI::getAjax()->registerFunction(
    'package_quiqqer_cron_ajax_updateSettings_save',
    static function ($setting, $active): array {
        if (!is_string($setting) || !in_array($active, [0, 1, '0', '1', false, true], true)) {
            throw new QUI\Exception('Invalid update setting');
        }

        $Settings = new QUI\Cron\UpdateSettings();
        $Settings->setActive($setting, (bool)$active);

        return $Settings->getStates();
    },
    ['setting', 'active'],
    ['Permission::checkAdminUser', 'quiqqer.settings']
);
