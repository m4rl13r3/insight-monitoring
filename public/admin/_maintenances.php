<?php

declare(strict_types=1);

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_probes.php';

function insight_maintenances_validate(array $input): array
{
    $title = trim((string)($input['title'] ?? ''));
    if ($title === '' || mb_strlen($title, 'UTF-8') > 160) {
        return ['ok' => false, 'error' => 'admin.maintenance.errorTitle'];
    }
    $description = trim(str_replace(["\r\n", "\r"], "\n", (string)($input['description'] ?? '')));
    if (mb_strlen($description, 'UTF-8') > 20000) {
        return ['ok' => false, 'error' => 'admin.maintenance.errorDescription'];
    }
    $timezone = trim((string)($input['timezone'] ?? insight_admin_env('INSIGHT_TIMEZONE', 'Europe/Paris')));
    try {
        $zone = new DateTimeZone($timezone);
        $starts = new DateTimeImmutable((string)($input['starts_at'] ?? ''), $zone);
        $ends = new DateTimeImmutable((string)($input['ends_at'] ?? ''), $zone);
    } catch (Throwable) {
        return ['ok' => false, 'error' => 'admin.maintenance.errorDates'];
    }
    if ($ends <= $starts) {
        return ['ok' => false, 'error' => 'admin.maintenance.errorDates'];
    }
    $recurrence = strtolower(trim((string)($input['recurrence'] ?? 'none')));
    if (!in_array($recurrence, ['none', 'daily', 'weekly', 'monthly'], true)) {
        return ['ok' => false, 'error' => 'admin.maintenance.errorRecurrence'];
    }
    $recurrenceInterval = insight_probes_bounded_int($input['recurrence_interval'] ?? null, 1, 1, 52);
    $recurrenceUntil = null;
    if ($recurrence !== 'none' && trim((string)($input['recurrence_until'] ?? '')) !== '') {
        try {
            $until = new DateTimeImmutable((string)$input['recurrence_until'], $zone);
        } catch (Throwable) {
            return ['ok' => false, 'error' => 'admin.maintenance.errorRecurrence'];
        }
        if ($until < $ends) {
            return ['ok' => false, 'error' => 'admin.maintenance.errorRecurrence'];
        }
        $recurrenceUntil = $until->format('Y-m-d H:i:s');
    }
    $status = strtolower(trim((string)($input['status'] ?? 'planned')));
    if (!in_array($status, ['planned', 'cancelled', 'completed'], true)) {
        $status = 'planned';
    }
    $siteIds = [];
    foreach ((array)($input['site_ids'] ?? []) as $value) {
        $id = filter_var($value, FILTER_VALIDATE_INT);
        if ($id !== false && (int)$id > 0) {
            $siteIds[(int)$id] = (int)$id;
        }
    }
    return [
        'ok' => true,
        'title' => $title,
        'description' => $description,
        'starts_at' => $starts->format('Y-m-d H:i:s'),
        'ends_at' => $ends->format('Y-m-d H:i:s'),
        'timezone' => $timezone,
        'recurrence' => $recurrence,
        'recurrence_interval' => $recurrenceInterval,
        'recurrence_until' => $recurrenceUntil,
        'status' => $status,
        'notify_public' => insight_probes_bool($input['notify_public'] ?? null, true),
        'site_ids' => array_values($siteIds),
    ];
}

function insight_maintenances_list(array $config, int $limit = 200): array
{
    $database = insight_probes_database($config);
    $limit = max(1, min(500, $limit));
    try {
        $result = $database->query(
            "SELECT m.*, GROUP_CONCAT(ms.site_id ORDER BY ms.site_id SEPARATOR ',') AS site_ids_csv
             FROM scheduled_maintenances m
             LEFT JOIN maintenance_sites ms ON ms.maintenance_id = m.id
             GROUP BY m.id
             ORDER BY m.starts_at DESC, m.id DESC LIMIT {$limit}"
        );
        if (!$result instanceof mysqli_result) {
            throw new RuntimeException('database_read_failed');
        }
        $rows = $result->fetch_all(MYSQLI_ASSOC);
        foreach ($rows as $index => $row) {
            $rows[$index]['site_ids'] = array_values(array_filter(array_map('intval', explode(',', (string)($row['site_ids_csv'] ?? '')))));
            $rows[$index]['notify_public'] = (bool)($row['notify_public'] ?? false);
            unset($rows[$index]['site_ids_csv']);
        }
        return ['ok' => true, 'status_code' => 200, 'maintenances' => $rows];
    } finally {
        $database->close();
    }
}

function insight_maintenances_store_sites(mysqli $database, int $maintenanceId, array $siteIds): void
{
    $delete = $database->prepare('DELETE FROM maintenance_sites WHERE maintenance_id = ?');
    if (!$delete instanceof mysqli_stmt) {
        throw new RuntimeException('database_prepare_failed');
    }
    $delete->bind_param('i', $maintenanceId);
    $delete->execute();
    $delete->close();
    if ($siteIds === []) {
        return;
    }
    $exists = $database->prepare('SELECT id FROM sites WHERE id = ? LIMIT 1');
    $insert = $database->prepare('INSERT INTO maintenance_sites (maintenance_id, site_id) VALUES (?, ?)');
    if (!$exists instanceof mysqli_stmt || !$insert instanceof mysqli_stmt) {
        throw new RuntimeException('database_prepare_failed');
    }
    foreach ($siteIds as $siteId) {
        $exists->bind_param('i', $siteId);
        $exists->execute();
        $row = $exists->get_result()->fetch_assoc();
        if (!is_array($row)) {
            throw new InvalidArgumentException('site_not_found');
        }
        $insert->bind_param('ii', $maintenanceId, $siteId);
        $insert->execute();
    }
    $exists->close();
    $insert->close();
}

function insight_maintenances_create(array $config, array $input, array $user): array
{
    $validated = insight_maintenances_validate($input);
    if (!($validated['ok'] ?? false)) {
        return ['ok' => false, 'status_code' => 422, 'error' => $validated['error']];
    }
    $database = insight_probes_database($config);
    try {
        $database->begin_transaction();
        $statement = $database->prepare(
            'INSERT INTO scheduled_maintenances (site_id, title, description, starts_at, ends_at, timezone, recurrence, recurrence_interval, recurrence_until, status, notify_public, created_by_user_id, created_by_name) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if (!$statement instanceof mysqli_stmt) {
            throw new RuntimeException('database_prepare_failed');
        }
        $firstSiteId = $validated['site_ids'][0] ?? null;
        $notifyPublic = $validated['notify_public'] ? 1 : 0;
        $userId = insight_auth_local_user_id($user);
        $userName = (string)($user['username'] ?? 'Insight');
        $statement->bind_param(
            'issssssissiis',
            $firstSiteId,
            $validated['title'],
            $validated['description'],
            $validated['starts_at'],
            $validated['ends_at'],
            $validated['timezone'],
            $validated['recurrence'],
            $validated['recurrence_interval'],
            $validated['recurrence_until'],
            $validated['status'],
            $notifyPublic,
            $userId,
            $userName
        );
        $statement->execute();
        $id = (int)$statement->insert_id;
        $statement->close();
        insight_maintenances_store_sites($database, $id, $validated['site_ids']);
        $database->commit();
        return ['ok' => true, 'status_code' => 201, 'id' => $id];
    } catch (InvalidArgumentException) {
        $database->rollback();
        return ['ok' => false, 'status_code' => 422, 'error' => 'admin.maintenance.errorSite'];
    } catch (Throwable $exception) {
        $database->rollback();
        throw $exception;
    } finally {
        $database->close();
    }
}

function insight_maintenances_update(array $config, int $id, array $input): array
{
    if ($id < 1) {
        return ['ok' => false, 'status_code' => 404, 'error' => 'admin.maintenance.errorNotFound'];
    }
    $validated = insight_maintenances_validate($input);
    if (!($validated['ok'] ?? false)) {
        return ['ok' => false, 'status_code' => 422, 'error' => $validated['error']];
    }
    $database = insight_probes_database($config);
    try {
        $database->begin_transaction();
        $statement = $database->prepare(
            'UPDATE scheduled_maintenances SET site_id=?, title=?, description=?, starts_at=?, ends_at=?, timezone=?, recurrence=?, recurrence_interval=?, recurrence_until=?, status=?, notify_public=?, cancelled_at=IF(? = \'cancelled\', COALESCE(cancelled_at, NOW()), NULL) WHERE id=?'
        );
        if (!$statement instanceof mysqli_stmt) {
            throw new RuntimeException('database_prepare_failed');
        }
        $firstSiteId = $validated['site_ids'][0] ?? null;
        $notifyPublic = $validated['notify_public'] ? 1 : 0;
        $statement->bind_param(
            'issssssissisi',
            $firstSiteId,
            $validated['title'],
            $validated['description'],
            $validated['starts_at'],
            $validated['ends_at'],
            $validated['timezone'],
            $validated['recurrence'],
            $validated['recurrence_interval'],
            $validated['recurrence_until'],
            $validated['status'],
            $notifyPublic,
            $validated['status'],
            $id
        );
        $statement->execute();
        $affected = $statement->affected_rows;
        $statement->close();
        $exists = $database->query('SELECT id FROM scheduled_maintenances WHERE id = ' . $id);
        if (!$exists instanceof mysqli_result || $exists->num_rows !== 1) {
            $database->rollback();
            return ['ok' => false, 'status_code' => 404, 'error' => 'admin.maintenance.errorNotFound'];
        }
        insight_maintenances_store_sites($database, $id, $validated['site_ids']);
        $database->commit();
        return ['ok' => true, 'status_code' => 200, 'id' => $id, 'changed' => $affected > 0];
    } catch (InvalidArgumentException) {
        $database->rollback();
        return ['ok' => false, 'status_code' => 422, 'error' => 'admin.maintenance.errorSite'];
    } catch (Throwable $exception) {
        $database->rollback();
        throw $exception;
    } finally {
        $database->close();
    }
}

function insight_maintenances_delete(array $config, int $id): array
{
    if ($id < 1) {
        return ['ok' => false, 'status_code' => 404, 'error' => 'admin.maintenance.errorNotFound'];
    }
    $database = insight_probes_database($config);
    try {
        $statement = $database->prepare('DELETE FROM scheduled_maintenances WHERE id = ?');
        if (!$statement instanceof mysqli_stmt) {
            throw new RuntimeException('database_prepare_failed');
        }
        $statement->bind_param('i', $id);
        $statement->execute();
        $deleted = $statement->affected_rows;
        $statement->close();
        return $deleted > 0
            ? ['ok' => true, 'status_code' => 200, 'deleted_id' => $id]
            : ['ok' => false, 'status_code' => 404, 'error' => 'admin.maintenance.errorNotFound'];
    } finally {
        $database->close();
    }
}
