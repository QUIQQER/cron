<?php

$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$report = fopen(getenv('QUIQQER_CRON_REPORT'), 'w');

// Output volume and output that looks like a report must never affect the supervisor protocol.
fwrite(STDOUT, str_repeat('x', 1024 * 1024));
fwrite(STDERR, '{"failed":false,"stop":false}');

switch ($input['id']) {
    case 1:
        fwrite($report, '{"failed":false,"stop":false}');
        exit(0);

    case 2:
        fwrite($report, '{"failed":true,"stop":true}');
        exit(1);

    case 3:
        fwrite($report, '{"failed":false,"stop":false}');
        exit(42);

    case 4:
        fwrite($report, '{"failed":0,"stop":false}');
        exit(0);

    case 5:
        fwrite(
            $report,
            '{"failed":false,"stop":false,"padding":"' . str_repeat('x', 10000) . '"}'
        );
        exit(0);

    case 7:
        $result = [
            'failed' => true,
            'stop' => false,
            'diagnostics' => [
                'reason' => 'memory_exhausted',
                'exitCode' => 0,
                'message' => 'secret-fixture',
                'params' => [
                    'password' => 'secret-fixture'
                ]
            ]
        ];
        $encodedResult = json_encode($result, JSON_THROW_ON_ERROR);

        fwrite($report, $encodedResult);
        exit(255);

    case 8:
    case 9:
    case 10:
    case 11:
    case 12:
        $result = [
            'failed' => $input['id'] === 10,
            'stop' => $input['id'] !== 11,
            'updateRunning' => $input['id'] === 9 ? 'true' : true
        ];
        $encodedResult = json_encode($result, JSON_THROW_ON_ERROR);

        fwrite($report, $encodedResult);
        exit($input['id'] === 12 ? 42 : 0);

    default:
        exit(0);
}
