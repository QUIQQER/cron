<?php

use QUI\Cron\ExecutionLock;

require dirname(__DIR__, 7) . '/autoload.php';

define('VAR_DIR', $argv[1] . '/');
define('CMS_DIR', $argv[1] . '/app/');

$Lock = ExecutionLock::create();

if (!$Lock->acquire()) {
    echo "BUSY\n";
    exit(3);
}

echo "READY\n";
fflush(STDOUT);

if (($argv[2] ?? '') === 'timed') {
    usleep(150000);
} else {
    fgets(STDIN);
}

$Lock->release();
