<?php

/** Official CLI-only machine entrypoint; deliberately runs before QUIQQER bootstrap. */

use QUI\Cron\Console\MachineRunner;
use QUI\Cron\ExecutionResult;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/src/QUI/Cron/ExecutionResult.php';
require dirname(__DIR__) . '/src/QUI/Cron/Console/MachineRunner.php';

// Do not allow PHP warnings (including process startup errors) to disclose paths or credentials.
ini_set('display_errors', '0');
set_error_handler(static function (): bool {
    return true;
});

$Result = (new MachineRunner())->run(array_slice($argv, 1), __DIR__ . '/cron-worker.php');

if ($Result->exitCode() !== 0) {
    fwrite(STDERR, 'Cron cycle: ' . $Result->status . PHP_EOL);
}

fwrite(STDOUT, json_encode($Result, JSON_THROW_ON_ERROR) . PHP_EOL);
exit($Result->exitCode());
