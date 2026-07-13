<?php

declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_OFF);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header_remove('X-Powered-By');

function insight_runtime_normalize_origin(string $origin): string
{
    $parts = parse_url(trim($origin));
    if (!is_array($parts)) {
        return '';
    }
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    $host = strtolower((string)($parts['host'] ?? ''));
    if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
        return '';
    }
    $port = isset($parts['port']) ? (int)$parts['port'] : 0;
    $defaultPort = ($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443);
    return $scheme . '://' . $host . ($port > 0 && !$defaultPort ? ':' . $port : '');
}

$configPath = dirname(__DIR__) . '/config/config.php';
$config = is_file($configPath) ? require $configPath : [];
$allowedOriginsRaw = trim((string)($config['allowed_origins'] ?? ''));
$allowedOrigins = array_values(array_filter(array_map(
    fn(string $allowedOrigin): string => insight_runtime_normalize_origin($allowedOrigin),
    array_map('trim', explode(',', $allowedOriginsRaw))
)));
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (is_string($origin) && $origin !== '') {
    $normalizedOrigin = insight_runtime_normalize_origin($origin);
    foreach ($allowedOrigins as $allowedOrigin) {
        if ($normalizedOrigin !== '' && hash_equals((string)$allowedOrigin, $normalizedOrigin)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Vary: Origin');
            break;
        }
    }
}
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET, OPTIONS');
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$projectRoot = dirname(__DIR__, 2);
$monitoringRoot = $projectRoot . '/monitoring';
$stateLib = $monitoringRoot . '/public_runtime_state.php';
if (!is_file($stateLib)) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => 'Public runtime state lib not found.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

require_once $stateLib;

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

echo json_encode([
    'ok' => true,
    'data' => $row ?: null,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
