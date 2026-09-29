<?php

use QUI\Cron\WorkerProcess;
use QUI\Cron\Console\MachineRunner;

require dirname(__DIR__, 7) . '/autoload.php';

// Verify the test actually excludes the device used by Symfony's no-callback output suppression.
$nullStream = @fopen('/dev/null', 'r');

if ($nullStream !== false) {
    fclose($nullStream);
    throw new RuntimeException('The test must run with /dev/null outside open_basedir.');
}

if ($argv[1] === 'machine') {
    $Runner = new MachineRunner();
    $Result = $Runner->run(
        ['--json'],
        dirname(__DIR__) . '/Console/Fixtures/machine-worker.php'
    );

    echo json_encode($Result, JSON_THROW_ON_ERROR);
    exit;
}

$Worker = new WorkerProcess();
$result = $Worker->run(
    PHP_BINARY,
    __DIR__ . '/job-report-worker.php',
    input: [
        'id' => (int)$argv[1]
    ]
);

echo json_encode($result, JSON_THROW_ON_ERROR);
