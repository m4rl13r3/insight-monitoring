<?php

declare(strict_types=1);

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_probes.php';

function insight_incidents_validate_postmortem(array $input): array
{
    if (!array_key_exists('postmortem', $input) || !is_string($input['postmortem'])) {
        return ['ok' => false, 'error' => 'admin.incidents.errorPostmortem'];
    }
    $postmortem = trim(str_replace(["\r\n", "\r"], "\n", $input['postmortem']));
    if (mb_strlen($postmortem, 'UTF-8') > 20000) {
        return ['ok' => false, 'error' => 'admin.incidents.errorLength'];
    }
    return ['ok' => true, 'postmortem' => $postmortem];
}

function insight_incidents_validate_details(array $input, bool $creating = false): array
{
    $title = trim((string)($input['title'] ?? ''));
    if ($creating && $title === '') {
        return ['ok' => false, 'error' => 'admin.incidents.errorTitle'];
    }
    if (mb_strlen($title, 'UTF-8') > 200) {
        return ['ok' => false, 'error' => 'admin.incidents.errorTitle'];
    }
    $summary = trim(str_replace(["\r\n", "\r"], "\n", (string)($input['summary'] ?? '')));
    if (mb_strlen($summary, 'UTF-8') > 20000) {
        return ['ok' => false, 'error' => 'admin.incidents.errorLength'];
    }
    $severity = strtolower(trim((string)($input['severity'] ?? 'major')));
    if (!in_array($severity, ['info', 'minor', 'major', 'critical'], true)) {
        return ['ok' => false, 'error' => 'admin.incidents.errorSeverity'];
    }
    $siteIds = [];
    foreach ((array)($input['site_ids'] ?? []) as $value) {
        $id = filter_var($value, FILTER_VALIDATE_INT);
        if ($id !== false && (int)$id > 0) {
            $siteIds[(int)$id] = (int)$id;
        }
    }
    if ($creating && $siteIds === []) {
        return ['ok' => false, 'error' => 'admin.incidents.errorSites'];
    }
    $metadataInput = $input['metadata'] ?? [];
    if (is_string($metadataInput)) {
        $metadataInput = trim($metadataInput) === '' ? [] : json_decode($metadataInput, true);
    }
    if (!is_array($metadataInput) || ($metadataInput !== [] && array_is_list($metadataInput)) || count($metadataInput) > 100) {
        return ['ok' => false, 'error' => 'admin.incidents.errorMetadata'];
    }
    $metadata = [];
    foreach ($metadataInput as $key => $value) {
        $name = trim((string)$key);
        if ($name === '' || strlen($name) > 120 || is_array($value) || is_object($value)) {
            return ['ok' => false, 'error' => 'admin.incidents.errorMetadata'];
        }
        $metadata[$name] = mb_substr((string)$value, 0, 2000, 'UTF-8');
    }
    $metadataJson = json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($metadataJson) || strlen($metadataJson) > 20000) {
        return ['ok' => false, 'error' => 'admin.incidents.errorMetadata'];
    }
    $runbookValue = filter_var($input['runbook_id'] ?? 0, FILTER_VALIDATE_INT);
    $runbookId = $runbookValue === false ? 0 : max(0, (int)$runbookValue);
    return [
        'ok' => true,
        'title' => $title,
        'summary' => $summary,
        'severity' => $severity,
        'published' => !isset($input['published']) || insight_probes_bool($input['published'], true),
        'site_ids' => array_values($siteIds),
        'runbook_id' => $runbookId,
        'metadata' => $metadata,
        'metadata_json' => $metadataJson,
    ];
}

function insight_incidents_store_sites(mysqli $database, int $incidentId, array $siteIds): void
{
    $delete = $database->prepare('DELETE FROM incident_sites WHERE incident_id = ?');
    if (!$delete instanceof mysqli_stmt) {
        throw new RuntimeException('database_prepare_failed');
    }
    $delete->bind_param('i', $incidentId);
    $delete->execute();
    $delete->close();
    $exists = $database->prepare('SELECT id FROM sites WHERE id = ? LIMIT 1');
    $insert = $database->prepare('INSERT INTO incident_sites (incident_id, site_id) VALUES (?, ?)');
    if (!$exists instanceof mysqli_stmt || !$insert instanceof mysqli_stmt) {
        throw new RuntimeException('database_prepare_failed');
    }
    foreach ($siteIds as $siteId) {
        $exists->bind_param('i', $siteId);
        $exists->execute();
        if (!is_array($exists->get_result()->fetch_assoc())) {
            throw new InvalidArgumentException('site_not_found');
        }
        $insert->bind_param('ii', $incidentId, $siteId);
        $insert->execute();
    }
    $exists->close();
    $insert->close();
}

function insight_incidents_validate_runbook(mysqli $database, int $runbookId): void
{
    if ($runbookId < 1) {
        return;
    }
    $statement = $database->prepare('SELECT id FROM runbooks WHERE id=? LIMIT 1');
    if (!$statement instanceof mysqli_stmt) {
        throw new RuntimeException('database_prepare_failed');
    }
    $statement->bind_param('i', $runbookId);
    $statement->execute();
    $exists = is_array($statement->get_result()->fetch_assoc());
    $statement->close();
    if (!$exists) {
        throw new InvalidArgumentException('runbook_not_found');
    }
}

function insight_incidents_create(array $config, array $input, array $user): array
{
    $validated = insight_incidents_validate_details($input, true);
    if (!($validated['ok'] ?? false)) {
        return ['ok' => false, 'status_code' => 422, 'error' => $validated['error']];
    }
    $database = insight_incidents_database($config);
    try {
        $database->begin_transaction();
        insight_incidents_validate_runbook($database, (int)$validated['runbook_id']);
        $statement = $database->prepare(
            "INSERT INTO incidents (site_id, runbook_id, incident_code, title, summary, metadata, severity, lifecycle_status, started_at, incident_date, source_mode, site_label, resolved, status, published) VALUES (?, NULLIF(?, 0), ?, ?, ?, ?, ?, 'started', NOW(), NOW(), 'manual', ?, 0, 0, ?)"
        );
        if (!$statement instanceof mysqli_stmt) {
            throw new RuntimeException('database_prepare_failed');
        }
        $siteId = (int)$validated['site_ids'][0];
        $code = 'MAN-' . gmdate('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $published = $validated['published'] ? 1 : 0;
        $siteLabel = $validated['title'];
        $runbookId = (int)$validated['runbook_id'];
        $statement->bind_param('iissssssi', $siteId, $runbookId, $code, $validated['title'], $validated['summary'], $validated['metadata_json'], $validated['severity'], $siteLabel, $published);
        $statement->execute();
        $id = (int)$statement->insert_id;
        $statement->close();
        insight_incidents_store_sites($database, $id, $validated['site_ids']);
        $update = $database->prepare("INSERT INTO incident_updates (incident_id, lifecycle_status, message, is_public, author_user_id, author_name) VALUES (?, 'started', ?, ?, ?, ?)");
        if (!$update instanceof mysqli_stmt) {
            throw new RuntimeException('database_prepare_failed');
        }
        $message = $validated['summary'] !== '' ? $validated['summary'] : $validated['title'];
        $userId = insight_auth_local_user_id($user);
        $userName = (string)($user['username'] ?? 'Insight');
        $update->bind_param('isiis', $id, $message, $published, $userId, $userName);
        $update->execute();
        $updateId = (int)$update->insert_id;
        $update->close();
        $database->commit();
        return ['ok' => true, 'status_code' => 201, 'incident' => ['id' => $id, 'status' => 'started', 'severity' => $validated['severity'], 'site_ids' => $validated['site_ids'], 'update_id' => $updateId, 'message' => $message, 'published' => $published]];
    } catch (InvalidArgumentException $exception) {
        $database->rollback();
        return ['ok' => false, 'status_code' => 422, 'error' => $exception->getMessage() === 'runbook_not_found' ? 'admin.incidents.errorRunbook' : 'admin.incidents.errorSites'];
    } catch (Throwable $exception) {
        $database->rollback();
        throw $exception;
    } finally {
        $database->close();
    }
}

function insight_incidents_manage(array $config, int $incidentId, array $input, array $user): array
{
    if ($incidentId < 1) {
        return ['ok' => false, 'status_code' => 404, 'error' => 'admin.incidents.errorNotFound'];
    }
    $action = strtolower(trim((string)($input['action'] ?? 'postmortem')));
    if ($action === 'postmortem') {
        return insight_incidents_update($config, $incidentId, $input);
    }
    $database = insight_incidents_database($config);
    try {
        $exists = $database->prepare('SELECT id, lifecycle_status FROM incidents WHERE id = ? LIMIT 1');
        if (!$exists instanceof mysqli_stmt) {
            throw new RuntimeException('database_prepare_failed');
        }
        $exists->bind_param('i', $incidentId);
        $exists->execute();
        $incident = $exists->get_result()->fetch_assoc();
        $exists->close();
        if (!is_array($incident)) {
            return ['ok' => false, 'status_code' => 404, 'error' => 'admin.incidents.errorNotFound'];
        }
        $userName = (string)($user['username'] ?? 'Insight');
        $userId = insight_auth_local_user_id($user);
        if ($action === 'comment') {
            $body = trim(str_replace(["\r\n", "\r"], "\n", (string)($input['body'] ?? '')));
            if ($body === '' || mb_strlen($body, 'UTF-8') > 20000) {
                return ['ok' => false, 'status_code' => 422, 'error' => 'admin.incidents.errorComment'];
            }
            $statement = $database->prepare('INSERT INTO incident_comments (incident_id, body, author_user_id, author_name) VALUES (?, ?, ?, ?)');
            if (!$statement instanceof mysqli_stmt) {
                throw new RuntimeException('database_prepare_failed');
            }
            $statement->bind_param('isis', $incidentId, $body, $userId, $userName);
            $statement->execute();
            $commentId = (int)$statement->insert_id;
            $statement->close();
            return ['ok' => true, 'status_code' => 201, 'incident' => ['id' => $incidentId], 'comment' => ['id' => $commentId, 'body' => $body, 'author_name' => $userName]];
        }
        if ($action === 'edit') {
            $validated = insight_incidents_validate_details($input, false);
            if (!($validated['ok'] ?? false) || $validated['title'] === '') {
                return ['ok' => false, 'status_code' => 422, 'error' => $validated['error'] ?? 'admin.incidents.errorTitle'];
            }
            $published = $validated['published'] ? 1 : 0;
            $database->begin_transaction();
            insight_incidents_validate_runbook($database, (int)$validated['runbook_id']);
            $statement = $database->prepare('UPDATE incidents SET site_id=?, runbook_id=NULLIF(?, 0), title=?, summary=?, metadata=?, severity=?, published=? WHERE id=?');
            $siteId = $validated['site_ids'][0] ?? null;
            $runbookId = (int)$validated['runbook_id'];
            $statement->bind_param('iissssii', $siteId, $runbookId, $validated['title'], $validated['summary'], $validated['metadata_json'], $validated['severity'], $published, $incidentId);
            $statement->execute();
            $statement->close();
            insight_incidents_store_sites($database, $incidentId, $validated['site_ids']);
            $database->commit();
            return ['ok' => true, 'status_code' => 200, 'incident' => ['id' => $incidentId, 'status' => $incident['lifecycle_status'], 'severity' => $validated['severity'], 'site_ids' => $validated['site_ids']]];
        }
        $message = trim(str_replace(["\r\n", "\r"], "\n", (string)($input['message'] ?? '')));
        if (mb_strlen($message, 'UTF-8') > 20000) {
            return ['ok' => false, 'status_code' => 422, 'error' => 'admin.incidents.errorLength'];
        }
        $published = !isset($input['published']) || insight_probes_bool($input['published'], true);
        $newStatus = match ($action) {
            'acknowledge' => 'acknowledged',
            'resolve' => 'resolved',
            'monitoring' => 'monitoring',
            'update' => (string)$incident['lifecycle_status'],
            default => '',
        };
        if ($newStatus === '') {
            return ['ok' => false, 'status_code' => 422, 'error' => 'admin.incidents.errorAction'];
        }
        if ($message === '') {
            $message = match ($action) {
                'acknowledge' => 'The incident has been acknowledged and is being investigated.',
                'resolve' => 'The incident is resolved and the affected services are operational.',
                'monitoring' => 'A fix has been applied and the service is being monitored.',
                default => 'Incident update.',
            };
        }
        $database->begin_transaction();
        if ($action === 'acknowledge') {
            $statement = $database->prepare("UPDATE incidents SET lifecycle_status='acknowledged', acknowledged_at=NOW(), acknowledged_by=? WHERE id=?");
            $statement->bind_param('si', $userName, $incidentId);
        } elseif ($action === 'resolve') {
            $statement = $database->prepare("UPDATE incidents SET lifecycle_status='resolved', ended_at=COALESCE(ended_at,NOW()), resolved=1, status=1, resolved_by=? WHERE id=?");
            $statement->bind_param('si', $userName, $incidentId);
        } elseif ($action === 'monitoring') {
            $statement = $database->prepare("UPDATE incidents SET lifecycle_status='monitoring' WHERE id=?");
            $statement->bind_param('i', $incidentId);
        } else {
            $statement = null;
        }
        if ($statement instanceof mysqli_stmt) {
            $statement->execute();
            $statement->close();
        }
        if ($action === 'resolve') {
            $database->query("UPDATE incident_groups groups_table INNER JOIN incidents incident ON incident.incident_group_id=groups_table.id SET groups_table.state='resolved', groups_table.last_seen_at=NOW() WHERE incident.id=" . $incidentId . " AND NOT EXISTS (SELECT 1 FROM incidents other WHERE other.incident_group_id=groups_table.id AND other.status=0 AND other.id<>" . $incidentId . ")");
        }
        $update = $database->prepare('INSERT INTO incident_updates (incident_id, lifecycle_status, message, is_public, author_user_id, author_name) VALUES (?, ?, ?, ?, ?, ?)');
        if (!$update instanceof mysqli_stmt) {
            throw new RuntimeException('database_prepare_failed');
        }
        $isPublic = $published ? 1 : 0;
        $update->bind_param('issiis', $incidentId, $newStatus, $message, $isPublic, $userId, $userName);
        $update->execute();
        $updateId = (int)$update->insert_id;
        $update->close();
        $database->commit();
        return ['ok' => true, 'status_code' => 200, 'incident' => ['id' => $incidentId, 'status' => $newStatus, 'update_id' => $updateId, 'message' => $message, 'published' => $isPublic]];
    } catch (InvalidArgumentException $exception) {
        try {
            $database->rollback();
        } catch (Throwable) {
        }
        return ['ok' => false, 'status_code' => 422, 'error' => $exception->getMessage() === 'runbook_not_found' ? 'admin.incidents.errorRunbook' : 'admin.incidents.errorSites'];
    } catch (Throwable $exception) {
        try {
            $database->rollback();
        } catch (Throwable) {
        }
        throw $exception;
    } finally {
        $database->close();
    }
}

function insight_incidents_delete(array $config, int $incidentId): array
{
    if ($incidentId < 1) {
        return ['ok' => false, 'status_code' => 404, 'error' => 'admin.incidents.errorNotFound'];
    }
    $database = insight_incidents_database($config);
    try {
        $attachments = [];
        $attachmentQuery = $database->prepare('SELECT stored_name FROM incident_attachments WHERE incident_id=?');
        if ($attachmentQuery instanceof mysqli_stmt) {
            $attachmentQuery->bind_param('i', $incidentId);
            $attachmentQuery->execute();
            $result = $attachmentQuery->get_result();
            while ($result instanceof mysqli_result && ($row = $result->fetch_assoc())) {
                $attachments[] = (string)($row['stored_name'] ?? '');
            }
            $attachmentQuery->close();
        }
        $statement = $database->prepare('DELETE FROM incidents WHERE id = ?');
        if (!$statement instanceof mysqli_stmt) {
            throw new RuntimeException('database_prepare_failed');
        }
        $statement->bind_param('i', $incidentId);
        $statement->execute();
        $deleted = $statement->affected_rows;
        $statement->close();
        if ($deleted > 0) {
            $directory = rtrim((string)(getenv('INSIGHT_DATA_DIR') ?: '/var/lib/insight'), '/') . '/incident-attachments';
            foreach ($attachments as $storedName) {
                if (preg_match('/^[a-f0-9]{64}$/', $storedName) === 1 && is_file($directory . '/' . $storedName)) {
                    unlink($directory . '/' . $storedName);
                }
            }
        }
        return $deleted > 0
            ? ['ok' => true, 'status_code' => 200, 'deleted_id' => $incidentId]
            : ['ok' => false, 'status_code' => 404, 'error' => 'admin.incidents.errorNotFound'];
    } finally {
        $database->close();
    }
}

function insight_incidents_context(array $config, int $incidentId): array
{
    $database = insight_incidents_database($config);
    try {
        $statement = $database->prepare(
            "SELECT i.id, i.title, i.summary, i.severity, i.lifecycle_status, i.published, GROUP_CONCAT(DISTINCT affected.id ORDER BY affected.id) AS site_ids, GROUP_CONCAT(DISTINCT affected.url ORDER BY affected.id SEPARATOR ', ') AS sites FROM incidents i LEFT JOIN incident_sites map ON map.incident_id=i.id LEFT JOIN sites affected ON affected.id=map.site_id WHERE i.id=? GROUP BY i.id LIMIT 1"
        );
        if (!$statement instanceof mysqli_stmt) {
            return [];
        }
        $statement->bind_param('i', $incidentId);
        $statement->execute();
        $row = $statement->get_result()->fetch_assoc();
        $statement->close();
        if (!is_array($row)) {
            return [];
        }
        $ids = array_values(array_filter(array_map('intval', explode(',', (string)($row['site_ids'] ?? '')))));
        $row['site_ids'] = $ids;
        $row['site_id'] = $ids[0] ?? 0;
        $row['domain'] = trim((string)strtok((string)($row['sites'] ?? 'service'), ','));
        return $row;
    } finally {
        $database->close();
    }
}

function insight_incidents_manage_runbook(array $config, string $method, int $runbookId, array $input): array
{
    $name = mb_substr(trim((string)($input['name'] ?? '')), 0, 160, 'UTF-8');
    $slug = strtolower(trim((string)($input['slug'] ?? '')));
    if ($slug === '' && $name !== '') {
        $slug = trim((string)preg_replace('/[^a-z0-9]+/i', '-', $name), '-');
    }
    $content = trim(str_replace(["\r\n", "\r"], "\n", (string)($input['content'] ?? '')));
    $enabled = !isset($input['enabled']) || insight_probes_bool($input['enabled'], true);
    if ($method !== 'DELETE' && ($name === '' || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1 || mb_strlen($content, 'UTF-8') > 100000)) {
        return ['ok' => false, 'status_code' => 422, 'error' => 'admin.incidents.errorRunbook'];
    }
    $database = insight_incidents_database($config);
    try {
        if ($method === 'POST') {
            $statement = $database->prepare('INSERT INTO runbooks (slug, name, content, enabled) VALUES (?, ?, ?, ?)');
            $enabledValue = $enabled ? 1 : 0;
            $statement->bind_param('sssi', $slug, $name, $content, $enabledValue);
            $statement->execute();
            $runbookId = (int)$statement->insert_id;
            $statement->close();
            return ['ok' => true, 'status_code' => 201, 'runbook' => ['id' => $runbookId, 'slug' => $slug, 'name' => $name, 'content' => $content, 'enabled' => $enabledValue]];
        }
        if ($runbookId < 1) {
            return ['ok' => false, 'status_code' => 404, 'error' => 'admin.incidents.errorRunbook'];
        }
        if ($method === 'PATCH') {
            $exists = $database->prepare('SELECT id FROM runbooks WHERE id=? LIMIT 1');
            $exists->bind_param('i', $runbookId);
            $exists->execute();
            $found = is_array($exists->get_result()->fetch_assoc());
            $exists->close();
            if (!$found) {
                return ['ok' => false, 'status_code' => 404, 'error' => 'admin.incidents.errorRunbook'];
            }
            $statement = $database->prepare('UPDATE runbooks SET slug=?, name=?, content=?, enabled=? WHERE id=?');
            $enabledValue = $enabled ? 1 : 0;
            $statement->bind_param('sssii', $slug, $name, $content, $enabledValue, $runbookId);
            $statement->execute();
            $statement->close();
            return ['ok' => true, 'status_code' => 200, 'runbook' => ['id' => $runbookId, 'slug' => $slug, 'name' => $name, 'content' => $content, 'enabled' => $enabledValue]];
        }
        $statement = $database->prepare('DELETE FROM runbooks WHERE id=?');
        $statement->bind_param('i', $runbookId);
        $statement->execute();
        $deleted = $statement->affected_rows;
        $statement->close();
        return $deleted > 0
            ? ['ok' => true, 'status_code' => 200, 'deleted_id' => $runbookId]
            : ['ok' => false, 'status_code' => 404, 'error' => 'admin.incidents.errorRunbook'];
    } finally {
        $database->close();
    }
}

function insight_incidents_dispatch_notification(array $config, string $method, array $input, array $result, int $fallbackId = 0): void
{
    $incidentId = (int)($result['incident']['id'] ?? $fallbackId);
    if ($incidentId < 1 || strtoupper($method) === 'DELETE' || insight_admin_env_bool('INSIGHT_DISABLE_NOTIFICATIONS', true)) {
        return;
    }
    $action = strtolower((string)($input['action'] ?? ''));
    $event = match (true) {
        strtoupper($method) === 'POST' => 'incident_open',
        $action === 'resolve' => 'incident_resolved',
        $action === 'acknowledge' => 'incident_acknowledged',
        in_array($action, ['update', 'monitoring'], true) => 'incident_update',
        default => '',
    };
    if ($event === '') {
        return;
    }
    require_once __DIR__ . '/_notifications.php';
    $context = insight_incidents_context($config, $incidentId);
    if ($context === []) {
        return;
    }
    $context['message'] = (string)($result['incident']['message'] ?? $context['summary'] ?? '');
    $context['update_id'] = (int)($result['incident']['update_id'] ?? 0);
    $publicUpdate = !isset($input['published']) || insight_probes_bool($input['published'], true);
    $context['notify_subscribers'] = (int)($context['published'] ?? 0) === 1 && $publicUpdate;
    $identity = $context['update_id'] > 0 ? 'update:' . $context['update_id'] : ($action !== '' ? $action : 'create');
    insight_notifications_python('dispatch', [
        'event' => $event,
        'context' => $context,
        'idempotency_key' => 'incident:' . $incidentId . ':' . $event . ':' . $identity,
    ]);
}

function insight_incidents_database(array $config): mysqli
{
    mysqli_report(MYSQLI_REPORT_OFF);
    $database = mysqli_init();
    if (!$database instanceof mysqli) {
        throw new RuntimeException('database_initialization_failed');
    }
    $database->options(MYSQLI_OPT_CONNECT_TIMEOUT, 3);
    $connected = @$database->real_connect(
        (string)$config['servername'],
        (string)$config['username'],
        (string)$config['password'],
        (string)$config['dbname'],
        (int)$config['port']
    );
    if (!$connected) {
        $database->close();
        throw new RuntimeException('database_unavailable');
    }
    $database->set_charset('utf8mb4');
    return $database;
}

function insight_incidents_update_database(array $config, int $incidentId, string $postmortem): array
{
    $database = insight_incidents_database($config);
    $exists = $database->prepare('SELECT id FROM incidents WHERE id = ? LIMIT 1');
    if (!$exists instanceof mysqli_stmt) {
        $database->close();
        throw new RuntimeException('database_prepare_failed');
    }
    $exists->bind_param('i', $incidentId);
    $exists->execute();
    $exists->store_result();
    $found = $exists->num_rows === 1;
    $exists->close();
    if (!$found) {
        $database->close();
        return ['ok' => false, 'status_code' => 404, 'error' => 'admin.incidents.errorNotFound'];
    }
    $storedPostmortem = $postmortem !== '' ? $postmortem : null;
    $statement = $database->prepare('UPDATE incidents SET postmortem = ?, ai_created = 0 WHERE id = ?');
    if (!$statement instanceof mysqli_stmt) {
        $database->close();
        throw new RuntimeException('database_prepare_failed');
    }
    $statement->bind_param('si', $storedPostmortem, $incidentId);
    if (!$statement->execute()) {
        $statement->close();
        $database->close();
        throw new RuntimeException('database_update_failed');
    }
    $statement->close();
    $database->close();
    return [
        'ok' => true,
        'status_code' => 200,
        'incident' => [
            'id' => $incidentId,
            'postmortem' => $postmortem,
            'has_postmortem' => $postmortem !== '',
        ],
        'mode' => 'database',
    ];
}

function insight_incidents_preview_path(): string
{
    return dirname(insight_admin_auth_path()) . '/dev-incident-postmortems.json';
}

function insight_incidents_preview_ids(): array
{
    return [100, 101];
}

function insight_incidents_preview_postmortems(): array
{
    if (!insight_auth_dev_bypass_enabled()) {
        return [];
    }
    $path = insight_incidents_preview_path();
    if (!is_file($path) || !is_readable($path)) {
        return [];
    }
    $decoded = json_decode((string)file_get_contents($path), true);
    if (!is_array($decoded)) {
        return [];
    }
    $postmortems = [];
    foreach ($decoded as $incidentId => $postmortem) {
        if (filter_var($incidentId, FILTER_VALIDATE_INT) === false || !is_string($postmortem)) {
            continue;
        }
        $postmortems[(int)$incidentId] = $postmortem;
    }
    return $postmortems;
}

function insight_incidents_write_preview_postmortems(array $postmortems): bool
{
    $path = insight_incidents_preview_path();
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        return false;
    }
    $json = json_encode($postmortems, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json) || file_put_contents($path, $json . PHP_EOL, LOCK_EX) === false) {
        return false;
    }
    @chmod($path, 0600);
    return true;
}

function insight_incidents_update_preview(int $incidentId, string $postmortem): array
{
    if (!in_array($incidentId, insight_incidents_preview_ids(), true)) {
        return ['ok' => false, 'status_code' => 404, 'error' => 'admin.incidents.errorNotFound'];
    }
    $postmortems = insight_incidents_preview_postmortems();
    $postmortems[$incidentId] = $postmortem;
    if (!insight_incidents_write_preview_postmortems($postmortems)) {
        return ['ok' => false, 'status_code' => 500, 'error' => 'admin.incidents.errorStorage'];
    }
    return [
        'ok' => true,
        'status_code' => 200,
        'incident' => [
            'id' => $incidentId,
            'postmortem' => $postmortem,
            'has_postmortem' => $postmortem !== '',
        ],
        'mode' => 'preview',
    ];
}

function insight_incidents_apply_preview(array $incidents): array
{
    $postmortems = insight_incidents_preview_postmortems();
    foreach ($incidents as $index => $incident) {
        $incidentId = (int)($incident['id'] ?? 0);
        if (array_key_exists($incidentId, $postmortems)) {
            $incidents[$index]['postmortem'] = $postmortems[$incidentId];
        }
    }
    return $incidents;
}

function insight_incidents_update(array $config, int $incidentId, array $input): array
{
    if ($incidentId < 1) {
        return ['ok' => false, 'status_code' => 422, 'error' => 'admin.incidents.errorNotFound'];
    }
    $validated = insight_incidents_validate_postmortem($input);
    if (!($validated['ok'] ?? false)) {
        return ['ok' => false, 'status_code' => 422, 'error' => (string)($validated['error'] ?? 'admin.incidents.errorGeneric')];
    }
    $postmortem = (string)$validated['postmortem'];
    try {
        $result = insight_incidents_update_database($config, $incidentId, $postmortem);
        if (($result['ok'] ?? false) || !insight_auth_dev_bypass_enabled()) {
            return $result;
        }
    } catch (Throwable) {
        if (!insight_auth_dev_bypass_enabled()) {
            return ['ok' => false, 'status_code' => 503, 'error' => 'admin.incidents.errorDatabase'];
        }
    }
    return insight_incidents_update_preview($incidentId, $postmortem);
}
