<?php

QUI::getAjax()->registerFunction(
    'package_quiqqer_cron_ajax_updateSettings_get',
    static fn(): array => (new QUI\Cron\UpdateSettings())->getStates(),
    [],
    ['Permission::checkAdminUser', 'quiqqer.settings']
);
