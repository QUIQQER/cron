<?php

/**
 * This File contains QUI\Cron\SecurityUpdateCron
 */

namespace QUI\Cron;

use QUI;
use QUI\Exception;

class SecurityUpdateCron
{
    /**
     * @param array<string, mixed> $params
     * @throws Exception
     */
    public static function execute(array $params, Manager $CronManager): void
    {
        $Console = new QUI\System\Console\Tools\SecurityUpdate();

        if (!empty($params['email'])) {
            $Console->setArgument('email', '');
        }

        $Console->execute();
    }
}
