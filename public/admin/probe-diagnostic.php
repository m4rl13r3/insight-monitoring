<?php

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_probes.php';

$user = insight_auth_require_user();
if (!insight_auth_can($user, 'dashboard:view')) {
    http_response_code(403);
    exit;
}
$diagnosticId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if ($diagnosticId === false || (int)$diagnosticId < 1) {
    http_response_code(404);
    exit;
}
$database = insight_probes_database($insightAdminConfig);
try {
    $statement = $database->prepare('SELECT diagnostic.*,site.url,site.name FROM probe_diagnostics diagnostic INNER JOIN sites site ON site.id=diagnostic.site_id WHERE diagnostic.id=? LIMIT 1');
    $id = (int)$diagnosticId;
    $statement->bind_param('i', $id);
    $statement->execute();
    $diagnostic = $statement->get_result()->fetch_assoc();
    $statement->close();
} finally {
    $database->close();
}
if (!is_array($diagnostic)) {
    http_response_code(404);
    exit;
}
if (isset($_GET['artifact'])) {
    $relative = trim((string)($diagnostic['artifact_path'] ?? ''));
    if ($relative === '' || str_contains($relative, '..') || str_starts_with($relative, '/')) {
        http_response_code(404);
        exit;
    }
    $dataRoot = realpath((string)(getenv('INSIGHT_DATA_DIR') ?: '/var/lib/insight'));
    $artifact = is_string($dataRoot) ? realpath($dataRoot . '/' . $relative) : false;
    if (!is_string($dataRoot) || !is_string($artifact) || !str_starts_with($artifact, $dataRoot . DIRECTORY_SEPARATOR) || !is_file($artifact)) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: image/png');
    header('Content-Length: ' . (string)filesize($artifact));
    header('Content-Disposition: inline; filename="diagnostic.png"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store, max-age=0');
    readfile($artifact);
    exit;
}
foreach (['timing_json', 'response_headers_json', 'network_json'] as $field) {
    $decoded = json_decode((string)($diagnostic[$field] ?? ''), true);
    $diagnostic[str_replace('_json', '', $field)] = is_array($decoded) ? $decoded : [];
    unset($diagnostic[$field]);
}
$diagnostic['id'] = (int)$diagnostic['id'];
$diagnostic['site_id'] = (int)$diagnostic['site_id'];
$diagnostic['probe_id'] = $diagnostic['probe_id'] === null ? null : (int)$diagnostic['probe_id'];
$diagnostic['has_artifact'] = trim((string)($diagnostic['artifact_path'] ?? '')) !== '';
unset($diagnostic['artifact_path']);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store, max-age=0');
echo json_encode(['ok' => true, 'diagnostic' => $diagnostic], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
