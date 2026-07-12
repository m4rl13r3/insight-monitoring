<?php

require_once __DIR__ . '/api/hourly_report/helpers.php';
require_once __DIR__ . '/api/hourly_report/bootstrap.php';
require_once __DIR__ . '/api/hourly_report/incidents_mode.php';
require_once __DIR__ . '/api/hourly_report/stats_mode.php';

$ctx = hourly_bootstrap_context();

try {
    $handled = hourly_handle_incidents_mode($ctx);
    if (!$handled) {
        hourly_handle_stats_mode($ctx);
    }
} finally {
    hourly_log($ctx, "hourly_stats_report finished");
    hourly_log($ctx, "");
    if (isset($ctx['conn']) && $ctx['conn'] instanceof mysqli) {
        $ctx['conn']->close();
    }
}
