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

$conn = mysqli_init();
if (!$conn instanceof mysqli) {
    http_response_code(503);
    echo json_encode([
        'ok' => false,
        'message' => 'Public state unavailable.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
$conn->options(MYSQLI_OPT_CONNECT_TIMEOUT, 3);
$connected = @$conn->real_connect(
    (string)($config['servername'] ?? 'db'),
    (string)($config['username'] ?? ''),
    (string)($config['password'] ?? ''),
    (string)($config['dbname'] ?? ''),
    (int)($config['port'] ?? 3306)
);
if (!$connected) {
    $conn->close();
    http_response_code(503);
    echo json_encode([
        'ok' => false,
        'message' => 'Public state unavailable.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
$conn->set_charset('utf8mb4');

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
$reinforced = $conn->query("
    SELECT COUNT(*) AS active_sites, MIN(ends_at) AS next_end_at, MAX(ends_at) AS last_end_at
    FROM monitoring_reinforced_watch
    WHERE ends_at > CURRENT_TIMESTAMP(3)
");
if ($reinforced instanceof mysqli_result) {
    $reinforcedRow = $reinforced->fetch_assoc();
    $row = $row ?: [];
    $row['reinforced_monitoring_active_sites'] = (int)($reinforcedRow['active_sites'] ?? 0);
    $row['reinforced_monitoring_next_end_at'] = $reinforcedRow['next_end_at'] ?? null;
    $row['reinforced_monitoring_last_end_at'] = $reinforcedRow['last_end_at'] ?? null;
    $reinforced->free();
}
$conn->close();

echo json_encode([
    'ok' => true,
    'data' => $row ?: null,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
