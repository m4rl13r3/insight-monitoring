<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$stateLib = __DIR__ . '/public_runtime_state.php';
if (!is_file($stateLib)) {
    http_response_code(503);
    echo json_encode([
        'ok' => false,
        'message' => 'Public runtime state indisponible.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
require $stateLib;

$conn = public_state_db_connect();
if (!$conn) {
    http_response_code(503);
    echo json_encode([
        'ok' => false,
        'message' => 'Public state unavailable.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

public_state_ensure_table($conn);

$sql = <<<SQL
SELECT
    service_name,
    service_timezone,
    app_env,
    is_degraded,
    active_engine,
    monitor_last_ok,
    monitor_last_message,
    monitor_python_error,
    monitor_checked_by,
    sites_checked,
    errors_count,
    incidents_opened,
    incidents_closed,
    hourly_last_ok,
    hourly_processed,
    hourly_bad_data,
    hourly_engine,
    daily_last_ok,
    daily_processed,
    daily_bad_data,
    daily_engine,
    last_monitor_at,
    last_hourly_at,
    last_daily_at,
    updated_at
FROM monitoring_public_runtime_state
WHERE singleton_id = 1
LIMIT 1
SQL;

$row = null;
$res = $conn->query($sql);
if ($res) {
    $row = $res->fetch_assoc();
    $res->free();
}
$conn->close();

if (!$row) {
    echo json_encode([
        'ok' => true,
        'data' => null,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

echo json_encode([
    'ok' => true,
    'data' => $row,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
