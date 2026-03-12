<?php

use QUI\Cron\CronService;
use QUI\Cron\CronServiceException;

QUI::getAjax()->registerFunction(
    'package_quiqqer_cron_ajax_cronservice_getStatus',
    /**
     * Gets the current Status for this instance
     *
     * @return string - Returns the status
     * @throws CronServiceException
     */
    function () {
        try {
            $CronService = new CronService();
            return $CronService->getStatus();
        } catch (CronServiceException $exception) {
            QUI\System\Log::writeDebugException($exception);
            throw $exception;
        } catch (\Throwable $exception) {
            QUI\System\Log::writeException($exception);
            throw new CronServiceException([
                'quiqqer/cron',
                'message.ajax.cronservice.general_error'
            ]);
        }
    },
    false,
    'Permission::checkAdminUser'
);
