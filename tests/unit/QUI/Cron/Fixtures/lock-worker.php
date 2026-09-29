<?php

use QUI\Cron\ExecutionLock;
use Symfony\Component\Process\Process;

require dirname(__DIR__, 7) . '/autoload.php';

define('VAR_DIR', $argv[1] . '/');
define('CMS_DIR', $argv[1] . '/app/');

$Lock = ExecutionLock::create();

if (!$Lock->acquire()) {
    echo "BUSY\n";
    exit(3);
}

if (($argv[2] ?? '') === 'child') {
    $context = $Lock->getWorkerContext();
    $Child = new Process(
        [
            PHP_BINARY,
            __DIR__ . '/lock-child.php',
            $argv[1],
            $context['path'],
            $context['token']
        ],
        timeout: 10
    );

    $Child->run(static function (string $type, string $buffer): void {
        echo $buffer;
        fflush(STDOUT);
    });

    $Lock->release();
    exit;
}

echo "READY\n";
fflush(STDOUT);

if (($argv[2] ?? '') === 'timed') {
    usleep(150000);
} else {
    fgets(STDIN);
}

$Lock->release();
