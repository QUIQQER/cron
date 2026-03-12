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
     * @throws Exception
     */
    public static function execute(array $params, $CronManager): void
    {
        $Console = new QUI\System\Console\Tools\SecurityUpdate();

        if (!empty($params['email'])) {
            $Console->setArgument('email', '');
        }

        $Console->execute();
    }
}
