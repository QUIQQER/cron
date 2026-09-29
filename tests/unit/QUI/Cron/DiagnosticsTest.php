<?php

namespace QUITests\Unit\Cron;

use PHPUnit\Framework\TestCase;
use QUI\Cron\Diagnostics;
use RuntimeException;

class DiagnosticsTest extends TestCase
{
    public function testExceptionMetadataDoesNotIncludeMessageOrTraceArguments(): void
    {
        $Error = new RuntimeException('password=secret-fixture', 42);
        $context = Diagnostics::exceptionContext($Error, 'job');
        $encodedContext = json_encode($context);

        self::assertSame('RuntimeException', $context['exceptionType']);
        self::assertSame(42, $context['exceptionCode']);
        self::assertSame(__FILE__, $context['sourceFile']);
        self::assertSame($Error->getLine(), $context['sourceLine']);
        self::assertStringNotContainsString('secret-fixture', $encodedContext);
    }

    public function testOnlyBoundedDiagnosticFieldsAreAccepted(): void
    {
        $context = Diagnostics::filter([
            'reason' => 'exception',
            'phase' => "job\n\r\0",
            'sourceFile' => str_repeat('a', 10000),
            'sourceLine' => 'wrong-type',
            'exitCode' => 255,
            'message' => 'secret-fixture',
            'params' => [
                'password' => 'secret-fixture'
            ],
            'trace' => 'secret-fixture',
            'stdout' => 'secret-fixture'
        ]);
        $encodedContext = json_encode($context);

        self::assertSame('job', $context['phase']);
        self::assertSame(512, strlen($context['sourceFile']));
        self::assertSame(255, $context['exitCode']);
        self::assertArrayNotHasKey('sourceLine', $context);
        self::assertStringNotContainsString('secret-fixture', $encodedContext);
    }
}
