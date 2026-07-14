<?php

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/_incidents.php';

$user = insight_auth_require_user();
if (!insight_auth_can($user, 'incidents:read')) {
    http_response_code(403);
    exit;
}
$attachmentId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if ($attachmentId === false || (int)$attachmentId < 1) {
    http_response_code(404);
    exit;
}
$database = insight_incidents_database($insightAdminConfig);
try {
    $statement = $database->prepare('SELECT stored_name, original_name, media_type, size_bytes FROM incident_attachments WHERE id=? LIMIT 1');
    $id = (int)$attachmentId;
    $statement->bind_param('i', $id);
    $statement->execute();
    $row = $statement->get_result()->fetch_assoc();
    $statement->close();
} finally {
    $database->close();
}
if (!is_array($row) || preg_match('/^[a-f0-9]{64}$/', (string)($row['stored_name'] ?? '')) !== 1) {
    http_response_code(404);
    exit;
}
$root = realpath(rtrim((string)(getenv('INSIGHT_DATA_DIR') ?: '/var/lib/insight'), '/') . '/incident-attachments');
$path = is_string($root) ? realpath($root . '/' . $row['stored_name']) : false;
if (!is_string($root) || !is_string($path) || !str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !is_file($path)) {
    http_response_code(404);
    exit;
}
$name = trim((string)($row['original_name'] ?? 'attachment')) ?: 'attachment';
header('Content-Type: ' . (string)($row['media_type'] ?? 'application/octet-stream'));
header('Content-Length: ' . (string)filesize($path));
header('Content-Disposition: attachment; filename="attachment"; filename*=UTF-8\'\'' . rawurlencode($name));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');
readfile($path);
