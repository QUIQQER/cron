<?php

use QUI\Cron\CronService;
use QUI\Cron\CronServiceException;

QUI::getAjax()->registerFunction(
    'package_quiqqer_cron_ajax_cronservice_resendActivation',
    /**
     * Requests the server to resend the activationmail again.
     * @throws CronServiceException
     */
    function () {
        try {
            $CronService = new CronService();
            $CronService->resendActivationMail();
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
