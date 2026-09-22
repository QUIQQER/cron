<?php

// Use a separate descriptor because the legacy endpoint may send its own response body.
register_shutdown_function(static function (): void {
    $report = fopen('php://fd/3', 'w');
    fwrite($report, json_encode(['status' => QUI::getGlobalResponse()->getStatusCode()]));
    fclose($report);
});

require dirname(__DIR__, 5) . '/bin/cron.php';
