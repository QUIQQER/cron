<?php

use QUI\System\MaintenanceBusyException;
use QUI\System\MaintenanceLock;

define('CMS_DIR', $argv[1]);
define('VAR_DIR', $argv[2]);
require dirname(__DIR__, 7) . '/autoload.php';

try {
    MaintenanceLock::run(static function () use ($argv): void {
        echo 'ready' . PHP_EOL;

        if (($argv[3] ?? '') === 'hold') {
            fgets(STDIN);
        }
    });
} catch (MaintenanceBusyException) {
    echo 'busy';
    exit(3);
}
