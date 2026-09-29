<?php

require dirname(__DIR__, 7) . '/autoload.php';

$handle = QUI\Cron\ExecutionLock::holdWorkerLock($argv[2], $argv[3]);

echo "READY\n";
fflush(STDOUT);

$deadline = microtime(true) + 5;

while (!file_exists($argv[1] . '/release-child') && microtime(true) < $deadline) {
    usleep(10000);
}
