<?php

/** Private single-job worker. Only the supervisor supplies the input and private report path. */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$reportPath = getenv('QUIQQER_CRON_REPORT');
$report = is_string($reportPath) && $reportPath !== ''
    ? @fopen($reportPath, 'w')
    : false;

if ($report === false) {
    exit(1);
}

putenv('QUIQQER_CRON_REPORT');

$result = [
    'failed' => true,
    'stop' => false
];

try {
    $input = json_decode((string)fgets(STDIN, 4097), true, flags: JSON_THROW_ON_ERROR);

    if (
        !is_array($input)
        || !is_int($input['id'] ?? null)
        || $input['id'] <= 0
        || !is_string($input['user'] ?? null)
        || !is_bool($input['cli'] ?? null)
        || !is_array($input['lock'] ?? null)
        || !is_string($input['lock']['path'] ?? null)
        || !is_string($input['lock']['token'] ?? null)
    ) {
        throw new RuntimeException('Invalid cron worker input.');
    }

    require_once dirname(__DIR__, 3) . '/autoload.php';

    $workerLock = QUI\Cron\ExecutionLock::holdWorkerLock(
        $input['lock']['path'],
        $input['lock']['token']
    );

    define('QUIQQER_SYSTEM', true);
    require dirname(__DIR__, 3) . '/header.php';

    $_REQUEST = $_POST = $_GET = [];
    $User = QUI::getUsers()->get($input['user']);
    QUI\Permissions\Permission::setUser($User);

    $Manager = new class ($input['cli']) extends QUI\Cron\Manager {
        public function __construct(private bool $originCli)
        {
        }

        protected function isCliExecution(): bool
        {
            return $this->originCli;
        }

        /** @return array{failed: bool, stop: bool} */
        public function runJob(int $id): array
        {
            try {
                $this->executeCron($id);
            } catch (Throwable) {
                $this->lastCronFailed = true;
            }

            return [
                'failed' => $this->lastCronFailed,
                'stop' => $this->stopExecutionAfterCurrentCron
            ];
        }
    };

    $result = QUI::getUsers()->withSessionUser(
        $User,
        static fn() => $Manager->runJob($input['id'])
    );
} catch (Throwable) {
    // The parent records the cron ID and failure without exposing parameters or exception messages.
}

fwrite($report, json_encode($result, JSON_THROW_ON_ERROR) . "\n");
fclose($report);

// Keep the worker guard alive until process exit, including bootstrap shutdown handlers.
exit($result['failed'] ? 1 : 0);
