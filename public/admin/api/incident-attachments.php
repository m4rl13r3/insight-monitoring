<?php

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once dirname(__DIR__) . '/_incidents.php';

function insight_incident_attachment_response(array $payload, int $statusCode): never
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$user = insight_auth_require_user();
if (!insight_auth_can($user, 'incidents:write')) {
    insight_incident_attachment_response(['ok' => false, 'error' => 'admin.auth.errorForbidden'], 403);
}
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    header('Allow: POST');
    insight_incident_attachment_response(['ok' => false, 'error' => 'method_not_allowed'], 405);
}
if (!insight_auth_csrf_valid($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['_csrf'] ?? null))) {
    insight_incident_attachment_response(['ok' => false, 'error' => 'admin.auth.errorCsrf'], 403);
}
$incidentId = filter_var($_POST['incident_id'] ?? null, FILTER_VALIDATE_INT);
$commentId = filter_var($_POST['comment_id'] ?? 0, FILTER_VALIDATE_INT);
$file = $_FILES['attachment'] ?? null;
if ($incidentId === false || (int)$incidentId < 1 || !is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    insight_incident_attachment_response(['ok' => false, 'error' => 'admin.incidents.errorAttachment'], 422);
}
$size = (int)($file['size'] ?? 0);
$temporary = (string)($file['tmp_name'] ?? '');
if ($size < 1 || $size > 5 * 1024 * 1024 || !is_uploaded_file($temporary)) {
    insight_incident_attachment_response(['ok' => false, 'error' => 'admin.incidents.errorAttachment'], 422);
}
$mediaType = (new finfo(FILEINFO_MIME_TYPE))->file($temporary);
$allowedTypes = ['image/png', 'image/jpeg', 'image/webp', 'application/pdf', 'text/plain', 'application/json'];
if (!is_string($mediaType) || !in_array($mediaType, $allowedTypes, true)) {
    insight_incident_attachment_response(['ok' => false, 'error' => 'admin.incidents.errorAttachmentType'], 422);
}
$database = insight_incidents_database($insightAdminConfig);
$storedName = bin2hex(random_bytes(32));
$dataRoot = rtrim((string)(getenv('INSIGHT_DATA_DIR') ?: '/var/lib/insight'), '/');
$directory = $dataRoot . '/incident-attachments';
$target = $directory . '/' . $storedName;
try {
    $statement = $database->prepare('SELECT id FROM incidents WHERE id=? LIMIT 1');
    $incidentValue = (int)$incidentId;
    $statement->bind_param('i', $incidentValue);
    $statement->execute();
    $exists = is_array($statement->get_result()->fetch_assoc());
    $statement->close();
    if (!$exists) {
        insight_incident_attachment_response(['ok' => false, 'error' => 'admin.incidents.errorNotFound'], 404);
    }
    $commentValue = $commentId === false ? 0 : max(0, (int)$commentId);
    if ($commentValue > 0) {
        $statement = $database->prepare('SELECT id FROM incident_comments WHERE id=? AND incident_id=? LIMIT 1');
        $statement->bind_param('ii', $commentValue, $incidentValue);
        $statement->execute();
        $commentExists = is_array($statement->get_result()->fetch_assoc());
        $statement->close();
        if (!$commentExists) {
            insight_incident_attachment_response(['ok' => false, 'error' => 'admin.incidents.errorComment'], 422);
        }
    }
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('attachment_directory_unavailable');
    }
    if (!move_uploaded_file($temporary, $target)) {
        throw new RuntimeException('attachment_move_failed');
    }
    chmod($target, 0600);
    $originalName = mb_substr(trim((string)($file['name'] ?? 'attachment')), 0, 255, 'UTF-8') ?: 'attachment';
    $sha256 = hash_file('sha256', $target);
    $statement = $database->prepare('INSERT INTO incident_attachments (incident_id, comment_id, stored_name, original_name, media_type, size_bytes, sha256) VALUES (?, NULLIF(?, 0), ?, ?, ?, ?, ?)');
    $statement->bind_param('iisssis', $incidentValue, $commentValue, $storedName, $originalName, $mediaType, $size, $sha256);
    $statement->execute();
    $attachmentId = (int)$statement->insert_id;
    $statement->close();
    insight_incident_attachment_response(['ok' => true, 'attachment' => ['id' => $attachmentId, 'incident_id' => $incidentValue, 'comment_id' => $commentValue ?: null, 'original_name' => $originalName, 'media_type' => $mediaType, 'size_bytes' => $size]], 201);
} catch (Throwable) {
    if (is_file($target)) {
        unlink($target);
    }
    insight_incident_attachment_response(['ok' => false, 'error' => 'admin.incidents.errorAttachment'], 500);
} finally {
    $database->close();
}
