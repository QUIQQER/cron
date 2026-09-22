<?php

require dirname(__DIR__, 6) . '/src/QUI/Cron/ExecutionResult.php';

use QUI\Cron\ExecutionResult;

// Every public output channel may contain credentials from third-party callbacks.
echo "secret from echo\n";
fwrite(STDOUT, "secret from direct stdout\n");
fwrite(STDERR, "secret from stderr\n");
trigger_error('secret warning', E_USER_WARNING);

$mode = $argv[1];
$timeout = $argv[2];

if ($timeout === '1') {
    exit(0); // Premature exit, without the private report.
}

if ($timeout === '2') {
    throw new RuntimeException('secret fatal exception');
}

$report = fopen('php://fd/3', 'w');

if ($timeout === '3') {
    fwrite($report, '{"status":"secret invalid report"}');
    exit(0);
}

if ($timeout === '4') {
    $Result = new ExecutionResult('completed_with_errors', 2, 1, 0, 1, true);
} else {
    $Result = new ExecutionResult('executed', 1, 1, 0, 0, true);
}

$data = $Result->jsonSerialize();
$data['private_exception'] = 'secret field that must not be forwarded';
fwrite($report, json_encode($data) . PHP_EOL);
fclose($report);
exit($timeout === '5' ? 99 : $Result->exitCode());
