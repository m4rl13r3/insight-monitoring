<?php

declare(strict_types=1);

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/_access.php';
require_once __DIR__ . '/_probes.php';
require_once __DIR__ . '/_incidents.php';
require_once __DIR__ . '/_maintenances.php';

function insight_headless_database(array $config): ?mysqli
{
    try {
        return insight_probes_database($config);
    } catch (Throwable) {
        return null;
    }
}

function insight_headless_rows(mysqli $database, string $query): array
{
    $result = $database->query($query);
    if (!$result instanceof mysqli_result) {
        throw new RuntimeException('database_query_failed');
    }
    $rows = $result->fetch_all(MYSQLI_ASSOC);
    $result->free();
    return $rows;
}

function insight_headless_monitors(array $config): array
{
    $database = insight_headless_database($config);
    if (!$database instanceof mysqli) {
        if (insight_auth_dev_bypass_enabled()) {
            return ['ok' => true, 'mode' => 'development', 'data' => insight_probes_preview_rows()];
        }
        return ['ok' => false, 'status_code' => 503, 'error' => 'database_unavailable'];
    }
    try {
        $rows = insight_headless_rows($database, '
            SELECT
                s.id,
                s.url AS target,
                s.name,
                s.probe_type,
                s.active,
                s.probe_interval_sec AS interval_sec,
                s.timeout_sec,
                s.retry_count,
                s.failure_threshold,
                s.recovery_threshold,
                s.accepted_status_codes,
                s.keyword_text,
                s.keyword_mode,
                s.json_path,
                s.json_expected_value,
                s.request_headers_json,
                s.request_body,
                s.basic_auth_username,
                IF(s.basic_auth_password_ciphertext IS NULL OR s.basic_auth_password_ciphertext = \'\', 0, 1) AS has_basic_auth_password,
                s.tls_verify,
                s.tls_expiry_threshold_days,
                s.dns_record_type,
                s.dns_expected_value,
                s.heartbeat_grace_sec,
                s.slo_target_percent,
                s.public_visible,
                COALESCE(p.status, \'unknown\') AS status,
                p.response_time,
                p.http_code,
                p.checked_at
            FROM sites s
            LEFT JOIN probes p ON p.id = (
                SELECT p2.id FROM probes p2
                WHERE p2.site_id = s.id
                ORDER BY p2.checked_at DESC, p2.id DESC LIMIT 1
            )
            ORDER BY s.id ASC
        ');
        return ['ok' => true, 'mode' => 'database', 'data' => $rows];
    } catch (Throwable) {
        return ['ok' => false, 'status_code' => 503, 'error' => 'database_unavailable'];
    } finally {
        $database->close();
    }
}

function insight_headless_incidents(array $config, int $limit = 100): array
{
    $database = insight_headless_database($config);
    if (!$database instanceof mysqli) {
        return ['ok' => false, 'status_code' => 503, 'error' => 'database_unavailable'];
    }
    $limit = max(1, min(500, $limit));
    try {
        $rows = insight_headless_rows($database, "
            SELECT
                i.id,
                i.incident_code,
                i.title,
                i.summary,
                i.severity,
                i.lifecycle_status,
                COALESCE(s.url, i.site_label, 'Service') AS target,
                i.started_at,
                i.ended_at,
                i.acknowledged_at,
                i.acknowledged_by,
                i.resolved_by,
                i.published,
                i.http_code,
                i.postmortem,
                COALESCE(i.source_mode, 'system') AS source,
                IF(i.ended_at IS NULL AND (i.resolved IS NULL OR i.resolved = 0), 'open', 'resolved') AS status,
                GROUP_CONCAT(DISTINCT affected.id ORDER BY affected.id) AS site_ids_csv,
                GROUP_CONCAT(DISTINCT affected.url ORDER BY affected.id SEPARATOR ', ') AS affected_sites
            FROM incidents i
            LEFT JOIN sites s ON s.id = i.site_id
            LEFT JOIN incident_sites mapping ON mapping.incident_id = i.id
            LEFT JOIN sites affected ON affected.id = mapping.site_id
            GROUP BY i.id
            ORDER BY i.started_at DESC
            LIMIT {$limit}
        ");
        foreach ($rows as $index => $row) {
            $rows[$index]['site_ids'] = array_values(array_filter(array_map('intval', explode(',', (string)($row['site_ids_csv'] ?? '')))));
            unset($rows[$index]['site_ids_csv']);
            $incidentId = (int)$row['id'];
            $rows[$index]['updates'] = insight_headless_rows($database, "SELECT id, lifecycle_status AS status, message, is_public, author_name, created_at FROM incident_updates WHERE incident_id = {$incidentId} ORDER BY created_at ASC, id ASC");
        }
        return ['ok' => true, 'data' => $rows];
    } catch (Throwable) {
        return ['ok' => false, 'status_code' => 503, 'error' => 'database_unavailable'];
    } finally {
        $database->close();
    }
}

function insight_headless_status(array $config): array
{
    $monitors = insight_headless_monitors($config);
    if (!($monitors['ok'] ?? false)) {
        return $monitors;
    }
    $counts = ['total' => 0, 'operational' => 0, 'degraded' => 0, 'offline' => 0, 'unknown' => 0];
    foreach ((array)($monitors['data'] ?? []) as $monitor) {
        $counts['total']++;
        $status = strtolower((string)($monitor['status'] ?? 'unknown'));
        $bucket = match ($status) {
            'online', 'up', 'operational' => 'operational',
            'degraded', 'warning', 'partial' => 'degraded',
            'offline', 'down', 'critical' => 'offline',
            default => 'unknown',
        };
        $counts[$bucket]++;
    }
    $overall = $counts['offline'] > 0
        ? 'offline'
        : ($counts['degraded'] > 0 ? 'degraded' : ($counts['unknown'] > 0 ? 'unknown' : 'operational'));
    $database = insight_headless_database($config);
    $runtime = null;
    if ($database instanceof mysqli) {
        try {
            $rows = insight_headless_rows($database, '
                SELECT active_engine, is_degraded, monitor_last_ok, sites_checked, errors_count,
                       last_monitor_at, last_hourly_at, last_daily_at, updated_at
                FROM monitoring_public_runtime_state WHERE singleton_id = 1 LIMIT 1
            ');
            $runtime = $rows[0] ?? null;
        } catch (Throwable) {
            $runtime = null;
        } finally {
            $database->close();
        }
    }
    return [
        'ok' => true,
        'status' => $overall,
        'counts' => $counts,
        'runtime' => $runtime,
        'updated_at' => gmdate(DATE_ATOM),
    ];
}
