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

    default:
        exit(0);
}
