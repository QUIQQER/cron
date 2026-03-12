<?php

/**
 * Return the cron list
 *
 * @return array
 */

QUI::getAjax()->registerFunction(
    'package_quiqqer_cron_ajax_getList',
    function () {
        $CronManager = new QUI\Cron\Manager();
        $list = $CronManager->getList();
        $Locale = QUI::getLocale();
        $Formatter = $Locale->getDateFormatter(
            IntlDateFormatter::SHORT,
            IntlDateFormatter::SHORT
        );

        foreach ($list as $key => $cron) {
            if ($Locale->isLocaleString($cron['title'])) {
                $locale = $Locale->getPartsOfLocaleString($cron['title']);
                $list[$key]['title'] = $Locale->get($locale[0], $locale[1]);
            }

            if (!empty($list[$key]['lastexec'])) {
                $list[$key]['lastexec'] = $Formatter->format(strtotime($list[$key]['lastexec']));
            } else {
                $list[$key]['lastexec'] = '';
            }
        }

        return $list;
    },
    false,
    'Permission::checkAdminUser'
);
