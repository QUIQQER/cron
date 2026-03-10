<?php

use QUI\Cron\CronService;
use QUI\Cron\CronServiceException;

QUI::$Ajax->registerFunction(
    'package_quiqqer_cron_ajax_cronservice_sendRegistration',
    /**
     * Registers this system with a QUIQQER cron service.
     * @throws CronServiceException
     */
    function (string $email) {
        try {
            $CronService = new CronService();
            $CronService->register($email);
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
    ['email'],
    'Permission::checkAdminUser'
);
