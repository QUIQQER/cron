<?php

/** Private worker of cron-run.php. stdout/stderr are discarded by its parent. */

use QUI\Cron\ExecutionResult;
use QUI\Cron\Manager;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$report = @fopen('php://fd/3', 'w');

if ($report === false) {
    exit(7);
}

require_once dirname(__DIR__) . '/src/QUI/Cron/ExecutionResult.php';

try {
    define('QUIQQER_SYSTEM', true);
    define('SYSTEM_INTERN', true);
    require dirname(__DIR__, 3) . '/header.php';

    $_REQUEST = $_POST = $_GET = [];
    QUI\Permissions\Permission::setUser(QUI::getUsers()->getSystemUser());
    $Result = (new Manager())->executeWithResult($argv[1] ?? 'skip', (float)($argv[2] ?? 300));
} catch (Throwable) {
    $Result = new ExecutionResult('execution_failed');
}

fwrite($report, json_encode($Result, JSON_THROW_ON_ERROR) . PHP_EOL);
fclose($report);
exit($Result->exitCode());
