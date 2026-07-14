<?php

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_OFF);

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'POST', 'HEAD'], true)) {
    header('Allow: GET, POST, HEAD');
    http_response_code(405);
    exit;
}

$config = require dirname(__DIR__) . '/config/config.php';
$token = trim((string)($_GET['token'] ?? $_SERVER['HTTP_X_INSIGHT_HEARTBEAT'] ?? ''));
if (strlen($token) < 32 || strlen($token) > 200) {
    http_response_code(404);
    exit;
}

$database = @new mysqli(
    (string)$config['servername'],
    (string)$config['username'],
    (string)$config['password'],
    (string)$config['dbname'],
    (int)$config['port']
);
if ($database->connect_errno) {
    http_response_code(503);
    exit;
}
$database->set_charset('utf8mb4');
$hash = hash('sha256', $token);
$statement = $database->prepare("SELECT id FROM sites WHERE probe_type = 'heartbeat' AND active = 1 AND heartbeat_token_hash = ? LIMIT 1");
if (!$statement instanceof mysqli_stmt) {
    $database->close();
    http_response_code(503);
    exit;
}
$statement->bind_param('s', $hash);
$statement->execute();
$result = $statement->get_result();
$site = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
$statement->close();
if (!is_array($site)) {
    $database->close();
    http_response_code(404);
    exit;
}
$siteId = (int)$site['id'];
$upsert = $database->prepare(
    "INSERT INTO monitoring_check_state (site_id, last_heartbeat_at, last_raw_status) VALUES (?, CURRENT_TIMESTAMP(3), 'online')
     ON DUPLICATE KEY UPDATE last_heartbeat_at = CURRENT_TIMESTAMP(3), last_raw_status = 'online', last_error = NULL"
);
if (!$upsert instanceof mysqli_stmt) {
    $database->close();
    http_response_code(503);
    exit;
}
$upsert->bind_param('i', $siteId);
$upsert->execute();
$upsert->close();
$database->close();
header('Cache-Control: no-store');
if (strtolower((string)($_GET['format'] ?? '')) === 'json' && $method !== 'HEAD') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'received_at' => gmdate(DATE_ATOM)], JSON_UNESCAPED_SLASHES);
    exit;
}
http_response_code(204);
