<?php

namespace QUI\Cron;

/**
 * A cron was skipped because setup, package maintenance or a system update is active.
 */
class SystemUpdateRunningException extends \QUI\Exception
{
    public function __construct()
    {
        parent::__construct('Crons cannot be executed while setup, installation or a system update is running.');
    }
}
