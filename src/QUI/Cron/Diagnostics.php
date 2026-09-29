<?php

namespace QUI\Cron;

use QUI\System\Log;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Throwable;

/**
 * Diagnostic metadata only, following the PayPal diagnostics pattern.
 */
final class Diagnostics
{
    /**
     * @return array<string, mixed>
     */
    public static function exceptionContext(Throwable $Error, string $phase): array
    {
        $context = [
            'reason' => 'exception',
            'phase' => $phase,
            'exceptionType' => get_class($Error),
            'exceptionCode' => $Error->getCode(),
            'sourceFile' => $Error->getFile(),
            'sourceLine' => $Error->getLine()
        ];

        if (
            $Error instanceof \QUI\Exception
            && $Error->getCode() === 804
        ) {
            $context['reason'] = 'project_not_found';
        }

        if ($Error instanceof ProcessSignaledException) {
            $Process = $Error->getProcess();

            $context['reason'] = 'worker_signaled';
            $context['signal'] = $Process->getTermSignal();
            $context['exitCode'] = $Process->getExitCode();
        }

        return self::filter($context);
    }

    /**
     * Keep worker reports bounded and exclude messages, traces, output and cron parameters.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public static function filter(array $context): array
    {
        $result = [];
        $stringFields = [
            'reason',
            'phase',
            'exceptionType',
            'sourceFile',
            'callback'
        ];
        $integerFields = [
            'sourceLine',
            'exceptionCode',
            'exitCode',
            'signal'
        ];

        foreach ($stringFields as $key) {
            $value = $context[$key] ?? null;

            if (is_string($value)) {
                $value = str_replace(["\r", "\n", "\0"], '', $value);
                $result[$key] = substr($value, 0, 512);
            }
        }

        foreach ($integerFields as $key) {
            if (is_int($context[$key] ?? null)) {
                $result[$key] = $context[$key];
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function logFailure(int $cronId, array $context): void
    {
        $context = self::filter($context);
        $context['cronId'] = $cronId;

        Log::addError(
            'Cron execution failed (ID: ' . $cronId . ').',
            $context,
            'cron'
        );
    }
}
