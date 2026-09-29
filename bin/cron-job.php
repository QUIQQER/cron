<?php

/**
 * Private single-job worker. Only the supervisor supplies the input and private report path.
 */

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

$phase = 'input';

// Release this reserve before reporting a fatal error, including memory exhaustion.
$reserve = str_repeat('x', 262144);

$reportFailureOnShutdown = static function () use ($report, &$reserve, &$phase): void {
    $reserve = null;

    if (!is_resource($report)) {
        return;
    }

    $error = error_get_last();
    $fatalErrorTypes = [
        E_ERROR,
        E_PARSE,
        E_CORE_ERROR,
        E_COMPILE_ERROR,
        E_USER_ERROR
    ];
    $diagnostics = [
        'reason' => 'worker_exited',
        'phase' => $phase
    ];

    if (
        $error !== null
        && in_array($error['type'], $fatalErrorTypes, true)
    ) {
        $diagnostics['reason'] = str_starts_with($error['message'], 'Allowed memory size of ')
            ? 'memory_exhausted'
            : 'fatal_error';
        $diagnostics['sourceFile'] = substr($error['file'], 0, 512);
        $diagnostics['sourceLine'] = $error['line'];
    }

    $shutdownResult = [
        'failed' => true,
        'stop' => false,
        'diagnostics' => $diagnostics
    ];
    $encodedResult = json_encode($shutdownResult);

    fwrite($report, $encodedResult . "\n");
    fclose($report);
};

register_shutdown_function($reportFailureOnShutdown);

try {
    $rawInput = (string)fgets(STDIN, 4097);
    $input = json_decode(
        $rawInput,
        true,
        flags: JSON_THROW_ON_ERROR
    );

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

    $phase = 'bootstrap';
    require_once dirname(__DIR__, 3) . '/autoload.php';

    $workerLock = QUI\Cron\ExecutionLock::holdWorkerLock(
        $input['lock']['path'],
        $input['lock']['token']
    );

    define('QUIQQER_SYSTEM', true);
    require dirname(__DIR__, 3) . '/header.php';

    $_REQUEST = [];
    $_POST = [];
    $_GET = [];

    $User = QUI::getUsers()->get($input['user']);
    QUI\Permissions\Permission::setUser($User);

    $Manager = new class ($input['cli']) extends QUI\Cron\Manager {
        public function __construct(private bool $originCli)
        {
            $this->isolatedJobWorker = true;
        }

        protected function isCliExecution(): bool
        {
            return $this->originCli;
        }

        /**
         * @return array{
         *     failed: bool,
         *     stop: bool,
         *     updateRunning?: bool,
         *     diagnostics?: array<string, mixed>
         * }
         */
        public function runJob(int $id): array
        {
            try {
                $this->executeCron($id);
            } catch (QUI\Cron\SystemUpdateRunningException) {
                return [
                    'failed' => false,
                    'stop' => true,
                    'updateRunning' => true
                ];
            } catch (Throwable $Error) {
                $this->lastCronFailed = true;
                $this->lastCronDiagnostics = QUI\Cron\Diagnostics::exceptionContext(
                    $Error,
                    'job'
                );
            }

            $diagnostics = QUI\Cron\Diagnostics::filter($this->lastCronDiagnostics);

            return [
                'failed' => $this->lastCronFailed,
                'stop' => $this->stopExecutionAfterCurrentCron,
                'diagnostics' => $diagnostics
            ];
        }
    };

    $phase = 'job';
    $result = QUI::getUsers()->withSessionUser(
        $User,
        static fn() => $Manager->runJob($input['id'])
    );
} catch (Throwable $Error) {
    // This also works when autoloading or the installation bootstrap itself failed.
    $result['diagnostics'] = [
        'reason' => 'exception',
        'phase' => $phase,
        'exceptionType' => substr(get_class($Error), 0, 512),
        'exceptionCode' => $Error->getCode(),
        'sourceFile' => substr($Error->getFile(), 0, 512),
        'sourceLine' => $Error->getLine()
    ];
}

$encodedResult = json_encode($result, JSON_THROW_ON_ERROR);

fwrite($report, $encodedResult . "\n");
fclose($report);

// Keep the worker guard alive until process exit, including bootstrap shutdown handlers.
exit($result['failed'] ? 1 : 0);
