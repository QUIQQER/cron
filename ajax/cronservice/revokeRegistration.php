<?php

use QUI\Cron\CronService;
use QUI\Cron\CronServiceException;

QUI::$Ajax->registerFunction(
    'package_quiqqer_cron_ajax_cronservice_revokeRegistration',
    /**
     * Revokes a registration on the cronservice server.
     * @throws CronServiceException
     */
    function () {
        try {
            $CronService = new CronService();
            $CronService->revokeRegistration();
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
