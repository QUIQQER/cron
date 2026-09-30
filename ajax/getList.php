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
        $canDelete = QUI\Permissions\Permission::hasPermission('quiqqer.cron.delete');
        $isSU = QUI\Permissions\Permission::isSU();
        $cronDefinitions = [];
        $Locale = QUI::getLocale();
        $Formatter = $Locale->getDateFormatter(
            IntlDateFormatter::SHORT,
            IntlDateFormatter::SHORT
        );

        foreach ($CronManager->getAvailableCrons() as $availableCron) {
            $exec = (string)($availableCron['exec'] ?? '');

            if ($exec === '') {
                continue;
            }

            $cronType = QUI\Cron\Manager::getCronType($availableCron);
            $isCliOnly = QUI\Cron\Manager::isCliOnlyDefinition($availableCron);
            $existingCliOnly = $cronDefinitions[$exec]['cliOnly'] ?? false;

            if (
                !isset($cronDefinitions[$exec])
                || $cronType === QUI\Cron\Manager::CRON_TYPE_SYSTEM
            ) {
                $cronDefinitions[$exec] = [
                    'type' => $cronType,
                    'description' => (string)($availableCron['description'] ?? '')
                ];
            }

            $cronDefinitions[$exec]['cliOnly'] = $existingCliOnly || $isCliOnly;
        }

        foreach ($list as $key => $cron) {
            $cronDefinition = $cronDefinitions[$cron['exec']] ?? [];

            $list[$key]['cronType'] = $cronDefinition['type']
                ?? QUI\Cron\Manager::CRON_TYPE_CUSTOM;
            $list[$key]['canDelete'] = $canDelete
                && ($isSU || $list[$key]['cronType'] !== QUI\Cron\Manager::CRON_TYPE_SYSTEM);
            $list[$key]['desc'] = $cronDefinition['description'] ?? '';
            $list[$key]['cliOnly'] = $cronDefinition['cliOnly'] ?? false;

            [$localeGroup, $localeVar] = $Locale->getPartsOfLocaleString($cron['title']);

            if ($localeGroup !== null && $localeVar !== null) {
                $list[$key]['title'] = $Locale->get($localeGroup, $localeVar);
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
