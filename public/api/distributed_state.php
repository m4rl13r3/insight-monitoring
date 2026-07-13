<?php

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_OFF);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

$config = require dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__, 2) . '/monitoring/distributed.php';

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$connection = mysqli_init();
if (!$connection instanceof mysqli) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'message' => 'Distributed state unavailable.'], JSON_UNESCAPED_UNICODE);
    exit;
}
$connection->options(MYSQLI_OPT_CONNECT_TIMEOUT, 3);
$connected = @$connection->real_connect(
    (string)($config['servername'] ?? 'db'),
    (string)($config['username'] ?? ''),
    (string)($config['password'] ?? ''),
    (string)($config['dbname'] ?? ''),
    (int)($config['port'] ?? 3306)
);
if (!$connected) {
    $connection->close();
    http_response_code(503);
    echo json_encode(['ok' => false, 'message' => 'Distributed state unavailable.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $connection->set_charset('utf8mb4');
    $summary = insight_dist_summary($connection);
    echo json_encode([
        'ok' => true,
        'mode' => insight_dist_env('INSIGHT_DISTRIBUTED_MODE', 'standalone'),
        'data' => $summary,
        'updated_at' => gmdate('c'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable) {
    http_response_code(503);
    echo json_encode([
        'ok' => false,
        'message' => 'Distributed monitoring is not initialized yet.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} finally {
    $connection->close();
}
