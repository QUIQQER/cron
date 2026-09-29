<?php

namespace QUITests\Integration\Cron\Fixtures;

use QUI\Cron\Manager;

class ExecutableCron
{
    /** @var array<int, array<string, mixed>> */
    public static array $calls = [];

    /**
     * @param array<string, mixed> $params
     */
    public static function execute(array $params, Manager $Manager): void
    {
        self::$calls[] = [
            'params' => $params,
            'manager' => $Manager
        ];

        if (isset($params['probeFile'])) {
            $probe = [
                'params' => $params,
                'user' => (string)\QUI::getUserBySession()->getUUID(),
                'pid' => getmypid()
            ];

            file_put_contents(
                $params['probeFile'],
                json_encode($probe, JSON_THROW_ON_ERROR)
            );
        }

        if (!empty($params['stop'])) {
            $Manager->stopAfterCurrentCron();
        }

        switch ($params['mode'] ?? '') {
            case 'oom':
                ini_set('memory_limit', (string)(memory_get_usage(true) + 8 * 1024 * 1024));
                $allocation = str_repeat('x', 32 * 1024 * 1024);
                break;

            case 'exit':
                exit(0);

            case 'error':
                throw new \Error('Cron fixture failure');
        }
    }
}
