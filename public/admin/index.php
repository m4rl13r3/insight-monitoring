<?php

declare(strict_types=1);

require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/_probes.php';
require_once __DIR__ . '/_incidents.php';
require_once __DIR__ . '/_notifications.php';
require_once __DIR__ . '/_oncall.php';
require_once __DIR__ . '/_oidc.php';
require_once __DIR__ . '/_security.php';
require_once __DIR__ . '/_maintenances.php';
require_once __DIR__ . '/_status_pages.php';
require_once __DIR__ . '/_slo.php';

$user = insight_auth_require_user();

function insight_dashboard_rows(mysqli $database, string $query): array
{
    $result = $database->query($query);
    if (!$result instanceof mysqli_result) {
        throw new RuntimeException('Unable to read data.');
    }
    $rows = $result->fetch_all(MYSQLI_ASSOC);
    $result->free();
    return $rows;
}

function insight_dashboard_table_exists(mysqli $database, string $table): bool
{
    $escaped = $database->real_escape_string($table);
    $result = $database->query("SHOW TABLES LIKE '{$escaped}'");
    if (!$result instanceof mysqli_result) {
        return false;
    }
    $exists = $result->num_rows > 0;
    $result->free();
    return $exists;
}

function insight_dashboard_demo_data(): array
{
    $now = new DateTimeImmutable('now');
    $data = [
        'mode' => 'preview',
        'monitors' => [
            [
                'id' => 1,
                'url' => 'https://example.com',
                'probe_type' => 'http',
                'probe_interval_sec' => 60,
                'status' => 'online',
                'response_time' => 84,
                'http_code' => 200,
                'checked_at' => $now->modify('-28 seconds')->format(DATE_ATOM),
            ],
            [
                'id' => 2,
                'url' => 'https://status.example.com',
                'probe_type' => 'http',
                'probe_interval_sec' => 60,
                'status' => 'online',
                'response_time' => 121,
                'http_code' => 200,
                'checked_at' => $now->modify('-41 seconds')->format(DATE_ATOM),
            ],
            [
                'id' => 3,
                'url' => 'https://api.example.com',
                'probe_type' => 'http',
                'probe_interval_sec' => 30,
                'status' => 'degraded',
                'response_time' => 742,
                'http_code' => 503,
                'checked_at' => $now->modify('-17 seconds')->format(DATE_ATOM),
            ],
        ],
        'servers' => [
            [
                'id' => 4,
                'url' => 'edge-paris.example.com',
                'probe_type' => 'icmp',
                'probe_interval_sec' => 60,
                'status' => 'online',
                'response_time' => 14,
                'http_code' => null,
                'checked_at' => $now->modify('-19 seconds')->format(DATE_ATOM),
            ],
            [
                'id' => 5,
                'url' => 'backup.example.com:22',
                'probe_type' => 'tcp',
                'probe_interval_sec' => 60,
                'status' => 'offline',
                'response_time' => null,
                'http_code' => null,
                'checked_at' => $now->modify('-34 seconds')->format(DATE_ATOM),
            ],
        ],
        'incidents' => [
            [
                'id' => 101,
                'url' => 'https://api.example.com',
                'started_at' => $now->modify('-42 minutes')->format(DATE_ATOM),
                'ended_at' => null,
                'http_code' => 503,
                'source_mode' => 'system',
                'postmortem' => 'Intermittent responses are under investigation.',
            ],
            [
                'id' => 100,
                'url' => 'https://status.example.com',
                'started_at' => $now->modify('-5 hours')->format(DATE_ATOM),
                'ended_at' => $now->modify('-4 hours 18 minutes')->format(DATE_ATOM),
                'http_code' => 522,
                'source_mode' => 'ai',
                'postmortem' => 'Response time has returned to its usual level.',
            ],
        ],
        'open_incidents' => 1,
        'maintenances' => [
            [
                'id' => 12,
                'url' => 'https://example.com',
                'title' => 'Application update',
                'description' => 'Scheduled deployment of the next release.',
                'starts_at' => $now->modify('+1 day 2 hours')->format(DATE_ATOM),
                'ends_at' => $now->modify('+1 day 2 hours 30 minutes')->format(DATE_ATOM),
                'status' => 'planned',
            ],
        ],
        'runtime' => [
            'active_engine' => 'consensus',
            'is_degraded' => 0,
            'monitor_last_ok' => 1,
            'sites_checked' => 5,
            'errors_count' => 1,
            'reinforced_monitoring_active_sites' => 0,
            'last_monitor_at' => $now->modify('-17 seconds')->format(DATE_ATOM),
            'last_hourly_at' => $now->modify('-18 minutes')->format(DATE_ATOM),
            'last_daily_at' => $now->setTime(0, 4)->format(DATE_ATOM),
        ],
        'distributed_mode' => 'hub',
        'nodes' => [
            [
                'node_key' => 'paris-1',
                'display_name' => 'Paris 1',
                'region' => 'fr-par',
                'zone' => 'fr-par-1',
                'status' => 'active',
                'connectivity_status' => 'online',
                'is_live' => 1,
                'assignments' => 3,
                'last_seen_at' => $now->modify('-8 seconds')->format(DATE_ATOM),
            ],
            [
                'node_key' => 'frankfurt-1',
                'display_name' => 'Francfort 1',
                'region' => 'de-fra',
                'zone' => 'de-fra-1',
                'status' => 'active',
                'connectivity_status' => 'online',
                'is_live' => 1,
                'assignments' => 3,
                'last_seen_at' => $now->modify('-13 seconds')->format(DATE_ATOM),
            ],
            [
                'node_key' => 'montreal-1',
                'display_name' => 'Montreal 1',
                'region' => 'ca-yul',
                'zone' => 'ca-yul-1',
                'status' => 'active',
                'connectivity_status' => 'online',
                'is_live' => 1,
                'assignments' => 3,
                'last_seen_at' => $now->modify('-21 seconds')->format(DATE_ATOM),
            ],
        ],
        'consensus' => [
            [
                'site_id' => 1,
                'url' => 'https://example.com',
                'status' => 'online',
                'nodes_expected' => 3,
                'nodes_fresh' => 3,
                'nodes_online' => 3,
                'nodes_offline' => 0,
                'nodes_degraded' => 0,
                'nodes_missing' => 0,
                'confidence' => 1,
                'response_median_ms' => 84,
                'evaluated_at' => $now->modify('-8 seconds')->format(DATE_ATOM),
            ],
            [
                'site_id' => 2,
                'url' => 'https://status.example.com',
                'status' => 'online',
                'nodes_expected' => 3,
                'nodes_fresh' => 3,
                'nodes_online' => 3,
                'nodes_offline' => 0,
                'nodes_degraded' => 0,
                'nodes_missing' => 0,
                'confidence' => 1,
                'response_median_ms' => 121,
                'evaluated_at' => $now->modify('-13 seconds')->format(DATE_ATOM),
            ],
            [
                'site_id' => 3,
                'url' => 'https://api.example.com',
                'status' => 'degraded',
                'nodes_expected' => 3,
                'nodes_fresh' => 3,
                'nodes_online' => 2,
                'nodes_offline' => 1,
                'nodes_degraded' => 0,
                'nodes_missing' => 0,
                'confidence' => 0.66667,
                'response_median_ms' => 742,
                'evaluated_at' => $now->modify('-17 seconds')->format(DATE_ATOM),
            ],
        ],
    ];
    foreach (insight_probes_preview_rows() as $probe) {
        $probeType = strtolower((string)($probe['probe_type'] ?? 'http'));
        $bucket = in_array($probeType, ['icmp', 'ping', 'tcp', 'snmp', 'service'], true) ? 'servers' : 'monitors';
        $data[$bucket][] = $probe;
    }
    $data['incidents'] = insight_incidents_apply_preview($data['incidents']);
    foreach (['monitors', 'servers'] as $bucket) {
        foreach ($data[$bucket] as $index => $monitor) {
            $data[$bucket][$index]['slo'] = insight_dashboard_slo([
                'slo_target_percent' => 99.9,
                'slo_observed_seconds' => 2592000,
                'slo_offline_seconds' => ($index + 1) * 240,
            ]);
        }
    }
    return $data;
}

function insight_dashboard_duration(int $seconds): string
{
    $value = abs($seconds);
    $days = intdiv($value, 86400);
    $hours = intdiv($value % 86400, 3600);
    $minutes = intdiv($value % 3600, 60);
    if ($days > 0) {
        return $days . ' d ' . $hours . ' h';
    }
    if ($hours > 0) {
        return $hours . ' h ' . $minutes . ' min';
    }
    return max(1, $minutes) . ' min';
}

function insight_dashboard_load_data(array $config): array
{
    if (!extension_loaded('mysqli')) {
        return insight_dashboard_demo_data();
    }
    mysqli_report(MYSQLI_REPORT_OFF);
    $database = mysqli_init();
    if (!$database instanceof mysqli) {
        return insight_dashboard_demo_data();
    }
    $database->options(MYSQLI_OPT_CONNECT_TIMEOUT, 2);
    $connected = @$database->real_connect(
        (string)$config['servername'],
        (string)$config['username'],
        (string)$config['password'],
        (string)$config['dbname'],
        (int)$config['port']
    );
    if (!$connected) {
        $database->close();
        return insight_dashboard_demo_data();
    }
    try {
        $database->set_charset('utf8mb4');
        $allMonitors = insight_dashboard_rows($database, "
            SELECT
                s.*,
                COALESCE(state.effective_status, p.status, 'unknown') AS status,
                p.response_time,
                p.http_code,
                p.checked_at,
                state.last_error,
                state.consecutive_failures,
                state.consecutive_successes,
                diagnostic.id AS diagnostic_id,
                diagnostic.status AS diagnostic_status,
                diagnostic.error_code AS diagnostic_error_code,
                diagnostic.artifact_path AS diagnostic_artifact_path,
                diagnostic.created_at AS diagnostic_created_at,
                objective.observed_seconds AS slo_observed_seconds,
                objective.offline_seconds AS slo_offline_seconds
            FROM sites s
            LEFT JOIN monitoring_check_state state ON state.site_id = s.id
            LEFT JOIN probes p ON p.id = (
                SELECT p2.id
                FROM probes p2
                WHERE p2.site_id = s.id
                ORDER BY p2.checked_at DESC, p2.id DESC
                LIMIT 1
            )
            LEFT JOIN probe_diagnostics diagnostic ON diagnostic.id = (
                SELECT diagnostic_latest.id
                FROM probe_diagnostics diagnostic_latest
                WHERE diagnostic_latest.site_id = s.id
                ORDER BY diagnostic_latest.created_at DESC, diagnostic_latest.id DESC
                LIMIT 1
            )
            LEFT JOIN (
                SELECT site_id,
                       SUM(GREATEST(total_seconds - maintenance_seconds - unknown_seconds, 0)) AS observed_seconds,
                       SUM(LEAST(offline_seconds, GREATEST(total_seconds - maintenance_seconds - unknown_seconds, 0))) AS offline_seconds
                FROM hourly_stats
                WHERE date >= DATE_SUB(CURRENT_DATE, INTERVAL 29 DAY)
                GROUP BY site_id
            ) objective ON objective.site_id = s.id
            ORDER BY s.id ASC
        ");
        if ($allMonitors === []) {
            $database->close();
            return insight_dashboard_demo_data();
        }
        foreach ($allMonitors as $index => $monitor) {
            $allMonitors[$index]['slo'] = insight_dashboard_slo($monitor);
        }
        $serverProbeTypes = ['icmp', 'ping', 'tcp', 'snmp', 'service'];
        $servers = array_values(array_filter(
            $allMonitors,
            static fn(array $monitor): bool => in_array(strtolower((string)($monitor['probe_type'] ?? '')), $serverProbeTypes, true)
        ));
        $monitors = array_values(array_filter(
            $allMonitors,
            static fn(array $monitor): bool => !in_array(strtolower((string)($monitor['probe_type'] ?? '')), $serverProbeTypes, true)
        ));
        $incidents = insight_dashboard_rows($database, "
            SELECT
                i.*,
                COALESCE(s.url, i.site_label, 'Service') AS url,
                incident_group.title AS group_title,
                incident_group.occurrence_count AS group_occurrence_count,
                runbook.name AS runbook_name,
                (SELECT GROUP_CONCAT(link.site_id ORDER BY link.site_id SEPARATOR ',') FROM incident_sites link WHERE link.incident_id=i.id) AS site_ids_csv,
                (SELECT GROUP_CONCAT(target.url ORDER BY target.id SEPARATOR ' | ') FROM incident_sites link INNER JOIN sites target ON target.id=link.site_id WHERE link.incident_id=i.id) AS sites_label
            FROM incidents i
            LEFT JOIN sites s ON s.id = i.site_id
            LEFT JOIN incident_groups incident_group ON incident_group.id = i.incident_group_id
            LEFT JOIN runbooks runbook ON runbook.id = i.runbook_id
            ORDER BY i.started_at DESC
            LIMIT 100
        ");
        if ($incidents !== []) {
            $incidentIds = array_values(array_filter(array_map(static fn(array $incident): int => (int)($incident['id'] ?? 0), $incidents)));
            $updatesByIncident = [];
            $commentsByIncident = [];
            $attachmentsByIncident = [];
            if ($incidentIds !== []) {
                $updates = insight_dashboard_rows($database, 'SELECT id,incident_id,lifecycle_status,message,is_public,author_name,created_at FROM incident_updates WHERE incident_id IN (' . implode(',', $incidentIds) . ') ORDER BY created_at,id');
                foreach ($updates as $update) {
                    $updatesByIncident[(int)$update['incident_id']][] = $update;
                }
                $comments = insight_dashboard_rows($database, 'SELECT id,incident_id,body,author_name,created_at FROM incident_comments WHERE incident_id IN (' . implode(',', $incidentIds) . ') ORDER BY created_at,id');
                foreach ($comments as $comment) {
                    $commentsByIncident[(int)$comment['incident_id']][] = $comment;
                }
                $attachments = insight_dashboard_rows($database, 'SELECT id,incident_id,comment_id,original_name,media_type,size_bytes,created_at FROM incident_attachments WHERE incident_id IN (' . implode(',', $incidentIds) . ') ORDER BY created_at,id');
                foreach ($attachments as $attachment) {
                    $attachmentsByIncident[(int)$attachment['incident_id']][] = $attachment;
                }
            }
            foreach ($incidents as $index => $incident) {
                $incidents[$index]['site_ids'] = array_values(array_filter(array_map('intval', explode(',', (string)($incident['site_ids_csv'] ?? '')))));
                $incidents[$index]['updates'] = $updatesByIncident[(int)$incident['id']] ?? [];
                $incidents[$index]['comments'] = $commentsByIncident[(int)$incident['id']] ?? [];
                $incidents[$index]['attachments'] = $attachmentsByIncident[(int)$incident['id']] ?? [];
                $metadata = json_decode((string)($incident['metadata'] ?? ''), true);
                $incidents[$index]['metadata'] = is_array($metadata) && !array_is_list($metadata) ? $metadata : [];
                unset($incidents[$index]['site_ids_csv']);
            }
        }
        $openIncidentsResult = $database->query(
            'SELECT COUNT(*) FROM incidents WHERE ended_at IS NULL AND (resolved IS NULL OR resolved = 0)'
        );
        $openIncidents = $openIncidentsResult instanceof mysqli_result
            ? (int)$openIncidentsResult->fetch_row()[0]
            : 0;
        if ($openIncidentsResult instanceof mysqli_result) {
            $openIncidentsResult->free();
        }
        $maintenances = insight_dashboard_rows($database, "
            SELECT
                m.*,
                COALESCE(s.url, 'All services') AS url,
                (SELECT GROUP_CONCAT(link.site_id ORDER BY link.site_id SEPARATOR ',') FROM maintenance_sites link WHERE link.maintenance_id=m.id) AS site_ids_csv
            FROM scheduled_maintenances m
            LEFT JOIN sites s ON s.id = m.site_id
            ORDER BY m.starts_at DESC
            LIMIT 100
        ");
        foreach ($maintenances as $index => $maintenance) {
            $maintenances[$index]['site_ids'] = array_values(array_filter(array_map('intval', explode(',', (string)($maintenance['site_ids_csv'] ?? '')))));
            unset($maintenances[$index]['site_ids_csv']);
        }
        $runtimeRows = insight_dashboard_rows(
            $database,
            'SELECT * FROM monitoring_public_runtime_state WHERE singleton_id = 1 LIMIT 1'
        );
        if (insight_dashboard_table_exists($database, 'monitoring_reinforced_watch')) {
            $reinforcedRows = insight_dashboard_rows($database, "
                SELECT COUNT(*) AS active_sites, MIN(ends_at) AS next_end_at
                FROM monitoring_reinforced_watch
                WHERE ends_at > CURRENT_TIMESTAMP(3)
            ");
            $runtimeRows[0]['reinforced_monitoring_active_sites'] = (int)($reinforcedRows[0]['active_sites'] ?? 0);
            $runtimeRows[0]['reinforced_monitoring_next_end_at'] = $reinforcedRows[0]['next_end_at'] ?? null;
        }
        $nodes = [];
        $consensus = [];
        if (
            insight_dashboard_table_exists($database, 'monitoring_nodes')
            && insight_dashboard_table_exists($database, 'monitoring_assignments')
        ) {
            $nodeTtl = max(30, min(86400, (int)(getenv('INSIGHT_AGENT_NODE_TTL_SEC') ?: 180)));
            $nodes = insight_dashboard_rows($database, "
                SELECT
                    n.node_key,
                    n.display_name,
                    COALESCE(n.region, '') AS region,
                    COALESCE(n.zone, '') AS zone,
                    n.status,
                    n.connectivity_status,
                    n.last_seen_at,
                    IF(n.status = 'active' AND n.last_seen_at >= DATE_SUB(NOW(), INTERVAL {$nodeTtl} SECOND), 1, 0) AS is_live,
                    (SELECT COUNT(*) FROM monitoring_assignments a WHERE a.node_id = n.id AND a.active = 1) AS assignments
                FROM monitoring_nodes n
                ORDER BY is_live DESC, n.display_name ASC
            ");
        }
        if (insight_dashboard_table_exists($database, 'monitoring_consensus_current')) {
            $consensus = insight_dashboard_rows($database, "
                SELECT
                    c.site_id,
                    s.url,
                    c.status,
                    c.nodes_expected,
                    c.nodes_fresh,
                    c.nodes_online,
                    c.nodes_offline,
                    c.nodes_degraded,
                    c.nodes_missing,
                    c.confidence,
                    c.response_median_ms,
                    c.evaluated_at,
                    rw.ends_at AS reinforced_ends_at,
                    rw.interval_sec AS reinforced_interval_sec
                FROM monitoring_consensus_current c
                INNER JOIN sites s ON s.id = c.site_id
                LEFT JOIN monitoring_reinforced_watch rw ON rw.site_id = s.id AND rw.ends_at > CURRENT_TIMESTAMP(3)
                ORDER BY s.id
            ");
        }
        $runbooks = insight_dashboard_rows($database, 'SELECT id,slug,name,content,enabled,created_at,updated_at FROM runbooks ORDER BY name,id');
        $database->close();
        return [
            'mode' => 'database',
            'monitors' => $monitors,
            'servers' => $servers,
            'incidents' => $incidents,
            'runbooks' => $runbooks,
            'open_incidents' => $openIncidents,
            'maintenances' => $maintenances,
            'runtime' => $runtimeRows[0] ?? [],
            'distributed_mode' => trim((string)(getenv('INSIGHT_DISTRIBUTED_MODE') ?: 'standalone')),
            'nodes' => $nodes,
            'consensus' => $consensus,
        ];
    } catch (Throwable) {
        $database->close();
        return insight_dashboard_demo_data();
    }
}

function insight_dashboard_status(string $status): array
{
    return match (strtolower(trim($status))) {
        'online', 'up', 'operational' => ['operational', 'state.operational'],
        'degraded', 'warning', 'partial' => ['degraded', 'state.degraded'],
        'maintenance', 'planned' => ['maintenance', 'state.maintenance'],
        'offline', 'down', 'critical' => ['offline', 'state.offline'],
        default => ['unknown', 'state.unknown'],
    };
}

function insight_dashboard_server_status(string $status): array
{
    return match (strtolower(trim($status))) {
        'online', 'up', 'operational' => ['operational', 'admin.servers.up'],
        'offline', 'down', 'critical' => ['offline', 'admin.servers.down'],
        default => ['unknown', 'admin.servers.unknown'],
    };
}

function insight_dashboard_node_status(array $node): array
{
    if (($node['status'] ?? '') === 'revoked') {
        return ['offline', 'admin.network.revoked'];
    }
    if (($node['status'] ?? '') === 'paused') {
        return ['unknown', 'admin.network.paused'];
    }
    if (empty($node['last_seen_at'])) {
        return ['unknown', 'admin.network.awaiting'];
    }
    if ((int)($node['is_live'] ?? 0) !== 1) {
        return ['unknown', 'state.disconnected'];
    }
    if (($node['connectivity_status'] ?? '') === 'offline') {
        return ['degraded', 'admin.network.networkIssue'];
    }
    return ['operational', 'admin.network.active'];
}

function insight_dashboard_host(string $url): string
{
    $host = parse_url($url, PHP_URL_HOST);
    return is_string($host) && $host !== '' ? $host : $url;
}

function insight_dashboard_iso(?string $value): string
{
    global $insightAdminConfig;
    if ($value === null || trim($value) === '') {
        return '';
    }
    try {
        $timezone = new DateTimeZone((string)($insightAdminConfig['timezone'] ?? 'Europe/Paris'));
        return (new DateTimeImmutable($value, $timezone))->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
    } catch (Throwable) {
        return '';
    }
}

function insight_dashboard_utc_iso(?string $value): string
{
    if ($value === null || trim($value) === '') {
        return '';
    }
    try {
        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->format(DATE_ATOM);
    } catch (Throwable) {
        return '';
    }
}

function insight_dashboard_number(mixed $value, int $precision = 0): string
{
    return number_format((float)$value, $precision, ',', ' ');
}

function insight_dashboard_probe_form_data(array $probe): array
{
    try {
        $advanced = insight_probes_decrypt_config((string)($probe['probe_config_ciphertext'] ?? ''));
    } catch (Throwable) {
        $advanced = [];
    }
    return [
        'id' => (int)($probe['id'] ?? 0),
        'url' => (string)($probe['url'] ?? ''),
        'name' => (string)($probe['name'] ?? ''),
        'probe_type' => (string)($probe['probe_type'] ?? 'http'),
        'active' => (int)($probe['active'] ?? 1) === 1,
        'probe_interval_sec' => (int)($probe['probe_interval_sec'] ?? 60),
        'timeout_sec' => (int)($probe['timeout_sec'] ?? 10),
        'retry_count' => (int)($probe['retry_count'] ?? 2),
        'failure_threshold' => (int)($probe['failure_threshold'] ?? 2),
        'recovery_threshold' => (int)($probe['recovery_threshold'] ?? 2),
        'calc_method' => (string)($probe['calc_method'] ?? 'inherit'),
        'accepted_status_codes' => (string)($probe['accepted_status_codes'] ?? '200-399'),
        'http_method' => (string)($probe['http_primary_method'] ?? 'GET'),
        'http_redirect' => (string)($probe['http_primary_redirect'] ?? 'follow'),
        'keyword_text' => (string)($probe['keyword_text'] ?? ''),
        'keyword_mode' => (string)($probe['keyword_mode'] ?? 'none'),
        'json_path' => (string)($probe['json_path'] ?? ''),
        'json_expected_value' => (string)($probe['json_expected_value'] ?? ''),
        'request_headers_json' => (string)($probe['request_headers_json'] ?? ''),
        'request_body' => (string)($probe['request_body'] ?? ''),
        'basic_auth_username' => (string)($probe['basic_auth_username'] ?? ''),
        'has_basic_auth_password' => trim((string)($probe['basic_auth_password_ciphertext'] ?? '')) !== '',
        'has_probe_config' => trim((string)($probe['probe_config_ciphertext'] ?? '')) !== '',
        'browser_script' => (string)($probe['browser_script'] ?? ''),
        'capture_success_screenshot' => (bool)($advanced['capture_success_screenshot'] ?? false),
        'has_browser_variables' => is_array($advanced['variables'] ?? null) && $advanced['variables'] !== [],
        'websocket_send' => (string)($advanced['send'] ?? ''),
        'websocket_expect' => (string)($advanced['expect'] ?? ''),
        'has_websocket_headers' => is_array($advanced['headers'] ?? null) && $advanced['headers'] !== [],
        'mqtt_username' => (string)($advanced['username'] ?? ''),
        'mqtt_expect' => (string)($advanced['expect'] ?? ''),
        'mqtt_qos' => (int)($advanced['qos'] ?? 0),
        'has_mqtt_password' => (string)($advanced['password'] ?? '') !== '',
        'sql_username' => (string)($advanced['username'] ?? ''),
        'sql_query' => (string)($advanced['query'] ?? 'SELECT 1'),
        'sql_expect' => (string)($advanced['expect'] ?? ''),
        'has_sql_password' => (string)($advanced['password'] ?? '') !== '',
        'diagnostics_enabled' => (int)($probe['diagnostics_enabled'] ?? 1) === 1,
        'diagnostic_capture_body' => (int)($probe['diagnostic_capture_body'] ?? 0) === 1,
        'tls_verify' => (int)($probe['tls_verify'] ?? 1) === 1,
        'tls_expiry_threshold_days' => (int)($probe['tls_expiry_threshold_days'] ?? 14),
        'dns_record_type' => (string)($probe['dns_record_type'] ?? 'A'),
        'dns_expected_value' => (string)($probe['dns_expected_value'] ?? ''),
        'heartbeat_grace_sec' => (int)($probe['heartbeat_grace_sec'] ?? 300),
        'slo_target_percent' => (float)($probe['slo_target_percent'] ?? 99.9),
        'public_visible' => (int)($probe['public_visible'] ?? 1) === 1,
    ];
}

$dashboard = insight_dashboard_load_data($insightAdminConfig);
$notificationState = insight_notifications_state($insightAdminConfig);
$oncallState = insight_oncall_state($insightAdminConfig);
$accessState = insight_access_state();
$ssoState = insight_oidc_public_state();
$securityState = insight_security_state($user);
try {
    $statusPageState = insight_status_pages_list($insightAdminConfig);
} catch (Throwable) {
    $statusPageState = ['ok' => false, 'pages' => []];
}
$monitors = is_array($dashboard['monitors'] ?? null) ? $dashboard['monitors'] : [];
$servers = is_array($dashboard['servers'] ?? null) ? $dashboard['servers'] : [];
$incidents = is_array($dashboard['incidents'] ?? null) ? $dashboard['incidents'] : [];
$runbooks = is_array($dashboard['runbooks'] ?? null) ? $dashboard['runbooks'] : [];
$maintenances = is_array($dashboard['maintenances'] ?? null) ? $dashboard['maintenances'] : [];
$runtime = is_array($dashboard['runtime'] ?? null) ? $dashboard['runtime'] : [];
$nodes = is_array($dashboard['nodes'] ?? null) ? $dashboard['nodes'] : [];
$consensus = is_array($dashboard['consensus'] ?? null) ? $dashboard['consensus'] : [];
$notificationChannels = is_array($notificationState['channels'] ?? null) ? $notificationState['channels'] : [];
$notificationTemplates = is_array($notificationState['templates'] ?? null) ? $notificationState['templates'] : insight_notifications_templates();
$notificationDeliveries = is_array($notificationState['deliveries'] ?? null) ? $notificationState['deliveries'] : [];
$notificationCatalog = is_array($notificationState['catalog'] ?? null) ? $notificationState['catalog'] : insight_notifications_provider_catalog();
$oncallSchedules = is_array($oncallState['schedules'] ?? null) ? $oncallState['schedules'] : [];
$oncallEvents = is_array($oncallState['events'] ?? null) ? $oncallState['events'] : [];
$notificationsDisabled = (bool)($notificationState['notifications_disabled'] ?? true);
$statusPages = is_array($statusPageState['pages'] ?? null) ? $statusPageState['pages'] : [];
$securityUsers = is_array($securityState['users'] ?? null) ? $securityState['users'] : [];
$isPreview = ($dashboard['mode'] ?? 'preview') !== 'database';
$isDevBypass = insight_auth_dev_bypass_enabled();
$canManageMonitors = insight_auth_can($user, 'monitors:write');
$canManageIncidents = insight_auth_can($user, 'incidents:write');
$canManageMaintenance = insight_auth_can($user, 'maintenance:write');
$canManageNotifications = insight_auth_can($user, 'notifications:write');
$canManageStatusPages = insight_auth_can($user, 'status_pages:write');
$canManageNetwork = insight_auth_can($user, 'network:write');
$canManageAccess = insight_auth_can($user, 'access:write');
$canManageUsers = insight_auth_can($user, 'users:write');
$localUserId = insight_auth_local_user_id($user);
$operational = 0;
$issues = 0;
foreach (array_merge($monitors, $servers) as $monitor) {
    [$statusClass] = insight_dashboard_status((string)($monitor['status'] ?? 'unknown'));
    if ($statusClass === 'operational') {
        $operational++;
    } else {
        $issues++;
    }
}
$openIncidents = (int)($dashboard['open_incidents'] ?? 0);
$totalMonitors = count($monitors) + count($servers);
$sloRows = array_values(array_filter(array_merge($monitors, $servers), static fn(array $monitor): bool => (int)($monitor['active'] ?? 1) === 1));
$sloMet = count(array_filter($sloRows, static fn(array $monitor): bool => in_array((string)($monitor['slo']['state'] ?? ''), ['met', 'at_risk'], true)));
$serversUp = count(array_filter($servers, static function (array $server): bool {
    [$statusClass] = insight_dashboard_server_status((string)($server['status'] ?? 'unknown'));
    return $statusClass === 'operational';
}));
$liveNodes = count(array_filter($nodes, static fn(array $node): bool => (int)($node['is_live'] ?? 0) === 1));
$healthyConsensus = count(array_filter($consensus, static fn(array $current): bool => ($current['status'] ?? '') === 'online'));
$reinforcedSites = (int)($runtime['reinforced_monitoring_active_sites'] ?? 0);
$distributedMode = trim((string)($dashboard['distributed_mode'] ?? 'standalone')) ?: 'standalone';
$auditEvents = [];
if (!$isDevBypass) {
    if (($user['source'] ?? 'local') === 'local') {
        $auditStatement = insight_auth_db()->prepare(
            'SELECT event, created_at FROM auth_audit_log WHERE user_id = :user_id ORDER BY id DESC LIMIT 4'
        );
        $auditStatement->execute(['user_id' => (int)$user['id']]);
    } else {
        $auditStatement = insight_auth_db()->prepare(
            "SELECT event, created_at FROM auth_audit_log
             WHERE user_id IS NULL AND event LIKE 'sso_%' ORDER BY id DESC LIMIT 4"
        );
        $auditStatement->execute();
    }
    $auditEvents = $auditStatement->fetchAll();
}
$appName = (string)($insightAdminConfig['app_name'] ?? 'Insight');
$runtimeEngine = trim((string)($runtime['active_engine'] ?? 'unknown')) ?: 'unknown';
if ($distributedMode === 'hub') {
    $runtimeEngine = 'consensus';
}
$runtimeDegraded = (int)($runtime['is_degraded'] ?? 0) === 1;
$runtimeOperational = (int)($runtime['monitor_last_ok'] ?? 0) === 1
    || in_array(strtolower($runtimeEngine), ['python', 'consensus'], true);
$runtimeStatusClass = $runtimeDegraded ? 'degraded' : ($runtimeOperational ? 'operational' : 'unknown');
$runtimeStatusKey = $runtimeDegraded ? 'state.degraded' : ($runtimeOperational ? 'state.operational' : 'state.unknown');

insight_admin_page_start('admin.meta.dashboardTitle', 'admin.meta.dashboardDescription', 'admin-dashboard-page');
?>
  <div class="admin-shell">
    <aside class="admin-sidebar" aria-label="Main navigation" data-i18n-aria-label="admin.nav.main">
      <a class="admin-sidebar-brand" href="/admin/">
        <img src="/favicons/favicon.svg" alt="" width="30" height="30">
        <span><?= insight_admin_escape($appName) ?></span>
      </a>
      <nav class="admin-nav">
        <a class="is-active" href="#overview" data-admin-route="overview" aria-current="page"><i class="fa-solid fa-chart-line" aria-hidden="true"></i><span data-i18n="admin.nav.overview">Home</span></a>
        <a href="#monitors" data-admin-route="monitors"><i class="fa-solid fa-heart-pulse" aria-hidden="true"></i><span data-i18n="admin.nav.monitors">Monitors</span></a>
        <a href="#servers" data-admin-route="servers"><i class="fa-solid fa-server" aria-hidden="true"></i><span data-i18n="admin.nav.servers">Servers</span></a>
        <a href="#network" data-admin-route="network"><i class="fa-solid fa-code-branch" aria-hidden="true"></i><span data-i18n="admin.nav.network">Network</span></a>
        <a href="#incidents" data-admin-route="incidents"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i><span data-i18n="admin.nav.incidents">Incidents</span></a>
        <a href="#notifications" data-admin-route="notifications"><i class="fa-regular fa-bell" aria-hidden="true"></i><span data-i18n="admin.nav.notifications">Alerts</span></a>
        <a href="#maintenance" data-admin-route="maintenance"><i class="fa-solid fa-calendar-check" aria-hidden="true"></i><span data-i18n="admin.nav.maintenance">Schedule</span></a>
        <a href="#status-pages" data-admin-route="status-pages"><i class="fa-solid fa-window-maximize" aria-hidden="true"></i><span data-i18n="admin.nav.statusPages">Status pages</span></a>
        <a class="admin-nav-account" href="#account" data-admin-route="account"><i class="fa-solid fa-fingerprint" aria-hidden="true"></i><span data-i18n="admin.nav.account">Access</span></a>
      </nav>
      <a class="admin-sidebar-public" href="/"><i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i><span data-i18n="admin.publicStatus">Public status page</span></a>
    </aside>
    <main class="admin-main">
      <header class="admin-toolbar">
        <div class="admin-toolbar-title">
          <span data-i18n="admin.dashboard.title">Dashboard</span>
          <?php if ($isDevBypass): ?>
            <span class="admin-source-badge" data-source="development"><span aria-hidden="true"></span><span data-i18n="admin.devBadge">Dev mode</span></span>
          <?php elseif ($isPreview): ?>
            <span class="admin-source-badge"><span aria-hidden="true"></span><span data-i18n="admin.previewBadge">Local preview</span></span>
          <?php endif; ?>
        </div>
        <div class="admin-toolbar-actions">
          <div id="insight-controls-root"></div>
          <a class="admin-user" href="#account" data-admin-route="account" aria-label="Access" data-i18n-aria-label="admin.nav.account"><i class="fa-regular fa-user" aria-hidden="true"></i><span><?= insight_admin_escape((string)$user['username']) ?></span></a>
          <?php if (!$isDevBypass): ?>
            <form method="post" action="/admin/logout.php">
              <input type="hidden" name="csrf_token" value="<?= insight_admin_escape(insight_auth_csrf_token()) ?>">
              <button class="admin-icon-button" type="submit" aria-label="Sign out" title="Sign out" data-i18n-aria-label="admin.logout" data-i18n-title="admin.logout"><i class="fa-solid fa-arrow-right-from-bracket" aria-hidden="true"></i></button>
            </form>
          <?php endif; ?>
        </div>
      </header>
      <div class="admin-content">
        <section id="overview" class="admin-overview" data-admin-view="overview">
          <div class="admin-page-heading">
            <div>
              <p class="admin-eyebrow" data-i18n="admin.dashboard.eyebrow">Local operations</p>
              <h1 data-i18n="admin.dashboard.heading">Instance status</h1>
              <p data-i18n="admin.dashboard.description">Track monitors and events that need your attention.</p>
            </div>
            <a class="admin-secondary-button" href="/" target="_blank" rel="noopener"><i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i><span data-i18n="admin.dashboard.openPublic">Open public page</span></a>
          </div>
          <?php if ($isDevBypass): ?>
            <div class="admin-dev-notice" role="status">
              <i class="fa-solid fa-unlock" aria-hidden="true"></i>
              <div><strong data-i18n="admin.dev.title">Authentication bypassed</strong><span data-i18n="admin.dev.description">For local development only. No account is required to browse the administration area.</span></div>
            </div>
          <?php endif; ?>
          <?php if ($isPreview): ?>
            <div class="admin-preview-notice" role="status">
              <i class="fa-solid fa-flask" aria-hidden="true"></i>
              <div><strong data-i18n="admin.preview.title">Demo data</strong><span data-i18n="admin.preview.description">MariaDB is empty or unavailable. This data lets you preview the dashboard.</span></div>
            </div>
          <?php endif; ?>
          <div class="admin-metrics" aria-label="Metrics" data-i18n-aria-label="admin.metrics.label">
            <div class="admin-metric">
              <span><i class="fa-solid fa-heart-pulse" aria-hidden="true"></i><span data-i18n="admin.metrics.monitors">Monitors</span></span>
              <strong><?= $totalMonitors ?></strong>
            </div>
            <div class="admin-metric is-positive">
              <span><i class="fa-solid fa-circle-check" aria-hidden="true"></i><span data-i18n="admin.metrics.operational">Operational</span></span>
              <strong><?= $operational ?></strong>
            </div>
            <div class="admin-metric<?= $issues > 0 ? ' is-warning' : '' ?>">
              <span><i class="fa-solid fa-wave-square" aria-hidden="true"></i><span data-i18n="admin.metrics.issues">Needs review</span></span>
              <strong><?= $issues ?></strong>
            </div>
            <div class="admin-metric<?= $openIncidents > 0 ? ' is-negative' : '' ?>">
              <span><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i><span data-i18n="admin.metrics.openIncidents">Open incidents</span></span>
              <strong><?= $openIncidents ?></strong>
            </div>
          </div>
          <section class="admin-slo-panel" aria-labelledby="admin-slo-title">
            <div class="admin-tool-panel-heading"><div><h2 id="admin-slo-title" data-i18n="admin.slo.title">Service objectives</h2><span data-i18n="admin.slo.description">Rolling 30-day availability and remaining error budget.</span></div><span class="admin-section-count"><?= $sloMet ?>/<?= count($sloRows) ?></span></div>
            <div class="admin-slo-list">
              <?php if ($sloRows === []): ?><div class="admin-empty"><i class="fa-solid fa-bullseye" aria-hidden="true"></i><span data-i18n="admin.slo.empty">No active monitor.</span></div><?php endif; ?>
              <?php foreach ($sloRows as $monitor): ?>
                <?php $slo = (array)($monitor['slo'] ?? []); $sloState = (string)($slo['state'] ?? 'no_data'); $remaining = (int)($slo['remaining_seconds'] ?? 0); ?>
                <article class="admin-slo-row" data-slo-state="<?= insight_admin_escape($sloState) ?>">
                  <div class="admin-slo-service"><span><i class="fa-solid <?= in_array(strtolower((string)($monitor['probe_type'] ?? 'http')), ['icmp', 'ping', 'tcp', 'snmp', 'service'], true) ? 'fa-server' : 'fa-globe' ?>" aria-hidden="true"></i></span><div><strong><?= insight_admin_escape(insight_dashboard_host((string)$monitor['url'])) ?></strong><small><?= insight_dashboard_number((float)($slo['target'] ?? 99.9), 3) ?>% <span data-i18n="admin.slo.target">target</span></small></div></div>
                  <div class="admin-slo-availability"><strong><?= $slo['availability'] === null ? '—' : insight_dashboard_number((float)$slo['availability'], 3) . '%' ?></strong><span data-i18n="admin.slo.availability">Availability</span></div>
                  <div class="admin-slo-budget"><div><strong><?= $slo['remaining_percent'] === null ? '—' : insight_dashboard_number((float)$slo['remaining_percent'], 1) . '%' ?></strong><span><?= $slo['remaining_percent'] === null ? '—' : (($remaining < 0 ? '−' : '') . insight_dashboard_duration($remaining)) ?></span></div><span class="admin-slo-track" aria-hidden="true"><span style="width: <?= max(0, min(100, (float)($slo['remaining_percent'] ?? 0))) ?>%"></span></span><small data-i18n="admin.slo.errorBudget">Error budget remaining</small></div>
                  <span class="admin-status-badge" data-status="<?= $sloState === 'met' ? 'operational' : ($sloState === 'at_risk' ? 'degraded' : ($sloState === 'breached' ? 'offline' : 'unknown')) ?>"><span aria-hidden="true"></span><span data-i18n="admin.slo.state.<?= insight_admin_escape($sloState) ?>"><?= insight_admin_escape($sloState) ?></span></span>
                </article>
              <?php endforeach; ?>
            </div>
          </section>
        </section>

        <section id="monitors" class="admin-section admin-route-section" data-admin-view="monitors" hidden>
          <div class="admin-section-heading">
            <div><p class="admin-eyebrow" data-i18n="admin.monitors.eyebrow">Monitoring</p><h1 data-i18n="admin.monitors.title">Monitors</h1></div>
            <div class="admin-section-actions"><span class="admin-section-count"><?= count($monitors) ?></span><?php if ($canManageMonitors): ?><button class="admin-primary-button" type="button" data-probe-create="http" aria-label="New monitor" title="New monitor" data-i18n-aria-label="admin.probes.createMonitor" data-i18n-title="admin.probes.createMonitor"><i class="fa-solid fa-plus" aria-hidden="true"></i><span data-i18n="admin.probes.createMonitor">New monitor</span></button><?php endif; ?></div>
          </div>
          <div class="admin-monitor-list">
            <div class="admin-monitor-header" aria-hidden="true">
              <span data-i18n="admin.monitors.service">Service</span><span data-i18n="admin.monitors.state">Status</span><span data-i18n="admin.monitors.response">Response</span><span>HTTP</span><span data-i18n="admin.monitors.lastCheck">Last check</span><span></span>
            </div>
            <?php foreach ($monitors as $monitor): ?>
              <?php [$statusClass, $statusKey] = insight_dashboard_status((string)($monitor['status'] ?? 'unknown')); ?>
              <?php $checkedAt = insight_dashboard_iso(isset($monitor['checked_at']) ? (string)$monitor['checked_at'] : null); ?>
              <article class="admin-monitor-row">
                <div class="admin-monitor-identity">
                  <span class="admin-monitor-icon"><i class="fa-solid fa-globe" aria-hidden="true"></i></span>
                  <div><strong><?= insight_admin_escape(insight_dashboard_host((string)$monitor['url'])) ?></strong><span><?= insight_admin_escape((string)$monitor['url']) ?></span></div>
                </div>
                <span class="admin-status-badge" data-status="<?= insight_admin_escape($statusClass) ?>"><span aria-hidden="true"></span><span data-i18n="<?= insight_admin_escape($statusKey) ?>"><?= insight_admin_escape($statusClass) ?></span></span>
                <span class="admin-monitor-value"><small data-i18n="admin.monitors.response">Response</small><?= isset($monitor['response_time']) ? insight_dashboard_number($monitor['response_time']) . ' ms' : '<span data-i18n="admin.common.notAvailable">N/A</span>' ?></span>
                <span class="admin-monitor-value"><small>HTTP</small><?= isset($monitor['http_code']) ? (int)$monitor['http_code'] : '<span data-i18n="admin.common.notAvailable">N/A</span>' ?></span>
                <span class="admin-monitor-time"><small data-i18n="admin.monitors.lastCheck">Last check</small><?php if ($checkedAt !== ''): ?><time datetime="<?= insight_admin_escape($checkedAt) ?>"></time><?php else: ?><span data-i18n="admin.common.never">Never</span><?php endif; ?></span>
                <?php if ($canManageMonitors && (!$isPreview || (int)($monitor['id'] ?? 0) >= 900000)): ?>
                  <div class="admin-row-actions">
                    <?php if ((int)($monitor['diagnostic_id'] ?? 0) > 0): ?><button class="admin-icon-button" type="button" data-probe-diagnostic="<?= (int)$monitor['diagnostic_id'] ?>" data-probe-diagnostic-target="<?= insight_admin_escape((string)$monitor['url']) ?>" aria-label="Open diagnostics" title="Open diagnostics" data-i18n-aria-label="admin.probes.openDiagnostics" data-i18n-title="admin.probes.openDiagnostics"><i class="fa-solid fa-stethoscope" aria-hidden="true"></i></button><?php endif; ?>
                    <button class="admin-icon-button" type="button" data-probe-edit data-probe-json="<?= insight_admin_escape((string)json_encode(insight_dashboard_probe_form_data($monitor), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>" data-probe-id="<?= (int)($monitor['id'] ?? 0) ?>" data-probe-target="<?= insight_admin_escape((string)$monitor['url']) ?>" data-probe-type="<?= insight_admin_escape((string)($monitor['probe_type'] ?? 'http')) ?>" data-probe-interval="<?= (int)($monitor['probe_interval_sec'] ?? 60) ?>" aria-label="Edit monitor" title="Edit monitor" data-i18n-aria-label="admin.probes.edit" data-i18n-title="admin.probes.edit"><i class="fa-solid fa-pen" aria-hidden="true"></i></button>
                    <button class="admin-icon-button is-destructive" type="button" data-probe-delete data-probe-id="<?= (int)($monitor['id'] ?? 0) ?>" data-probe-target="<?= insight_admin_escape((string)$monitor['url']) ?>" aria-label="Delete monitor" title="Delete monitor" data-i18n-aria-label="admin.probes.delete" data-i18n-title="admin.probes.delete"><i class="fa-regular fa-trash-can" aria-hidden="true"></i></button>
                  </div>
                <?php endif; ?>
              </article>
            <?php endforeach; ?>
          </div>
        </section>

        <section id="servers" class="admin-section admin-route-section" data-admin-view="servers" hidden>
          <div class="admin-section-heading">
            <div><p class="admin-eyebrow" data-i18n="admin.servers.eyebrow">Network availability</p><h1 data-i18n="admin.servers.title">Servers</h1></div>
            <div class="admin-section-actions"><span class="admin-section-count"><?= $serversUp ?>/<?= count($servers) ?></span><?php if ($canManageMonitors): ?><button class="admin-primary-button" type="button" data-probe-create="server" aria-label="Add server" title="Add server" data-i18n-aria-label="admin.probes.createServer" data-i18n-title="admin.probes.createServer"><i class="fa-solid fa-plus" aria-hidden="true"></i><span data-i18n="admin.probes.createServer">Add server</span></button><?php endif; ?></div>
          </div>
          <div class="admin-server-list">
            <div class="admin-server-header" aria-hidden="true">
              <span data-i18n="admin.servers.server">Server</span><span data-i18n="admin.servers.state">Status</span><span data-i18n="admin.servers.check">Check</span><span data-i18n="admin.servers.latency">Latency</span><span data-i18n="admin.servers.lastCheck">Last check</span><span></span>
            </div>
            <?php if ($servers === []): ?>
              <div class="admin-empty"><i class="fa-solid fa-server" aria-hidden="true"></i><span data-i18n="admin.servers.empty">No server configured.</span></div>
            <?php endif; ?>
            <?php foreach ($servers as $server): ?>
              <?php [$serverStatusClass, $serverStatusKey] = insight_dashboard_server_status((string)($server['status'] ?? 'unknown')); ?>
              <?php $serverCheckedAt = insight_dashboard_iso(isset($server['checked_at']) ? (string)$server['checked_at'] : null); ?>
              <?php $serverProbeType = strtolower(trim((string)($server['probe_type'] ?? 'icmp'))); ?>
              <article class="admin-server-row">
                <div class="admin-server-identity">
                  <span class="admin-server-icon"><i class="fa-solid fa-server" aria-hidden="true"></i></span>
                  <div><strong><?= insight_admin_escape(insight_dashboard_host((string)$server['url'])) ?></strong><span><?= insight_admin_escape((string)$server['url']) ?></span></div>
                </div>
                <span class="admin-status-badge" data-status="<?= insight_admin_escape($serverStatusClass) ?>"><span aria-hidden="true"></span><span data-i18n="<?= insight_admin_escape($serverStatusKey) ?>"><?= insight_admin_escape($serverStatusClass) ?></span></span>
                <span class="admin-server-value"><small data-i18n="admin.servers.check">Check</small><?= insight_admin_escape(strtoupper($serverProbeType === 'ping' ? 'icmp' : $serverProbeType)) ?></span>
                <span class="admin-server-value"><small data-i18n="admin.servers.latency">Latency</small><?= isset($server['response_time']) ? insight_dashboard_number($server['response_time']) . ' ms' : '<span data-i18n="admin.common.notAvailable">N/A</span>' ?></span>
                <span class="admin-server-time"><small data-i18n="admin.servers.lastCheck">Last check</small><?php if ($serverCheckedAt !== ''): ?><time datetime="<?= insight_admin_escape($serverCheckedAt) ?>"></time><?php else: ?><span data-i18n="admin.common.never">Never</span><?php endif; ?></span>
                <?php if ($canManageMonitors && (!$isPreview || (int)($server['id'] ?? 0) >= 900000)): ?>
                  <div class="admin-row-actions">
                    <?php if ((int)($server['diagnostic_id'] ?? 0) > 0): ?><button class="admin-icon-button" type="button" data-probe-diagnostic="<?= (int)$server['diagnostic_id'] ?>" data-probe-diagnostic-target="<?= insight_admin_escape((string)$server['url']) ?>" aria-label="Open diagnostics" title="Open diagnostics" data-i18n-aria-label="admin.probes.openDiagnostics" data-i18n-title="admin.probes.openDiagnostics"><i class="fa-solid fa-stethoscope" aria-hidden="true"></i></button><?php endif; ?>
                    <button class="admin-icon-button" type="button" data-probe-edit data-probe-json="<?= insight_admin_escape((string)json_encode(insight_dashboard_probe_form_data($server), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>" data-probe-id="<?= (int)($server['id'] ?? 0) ?>" data-probe-target="<?= insight_admin_escape((string)$server['url']) ?>" data-probe-type="<?= insight_admin_escape((string)($server['probe_type'] ?? 'icmp')) ?>" data-probe-interval="<?= (int)($server['probe_interval_sec'] ?? 60) ?>" aria-label="Edit monitor" title="Edit monitor" data-i18n-aria-label="admin.probes.edit" data-i18n-title="admin.probes.edit"><i class="fa-solid fa-pen" aria-hidden="true"></i></button>
                    <button class="admin-icon-button is-destructive" type="button" data-probe-delete data-probe-id="<?= (int)($server['id'] ?? 0) ?>" data-probe-target="<?= insight_admin_escape((string)$server['url']) ?>" aria-label="Delete monitor" title="Delete monitor" data-i18n-aria-label="admin.probes.delete" data-i18n-title="admin.probes.delete"><i class="fa-regular fa-trash-can" aria-hidden="true"></i></button>
                  </div>
                <?php endif; ?>
              </article>
            <?php endforeach; ?>
          </div>
        </section>

        <section id="network" class="admin-section admin-route-section" data-admin-view="network" hidden>
          <div class="admin-section-heading">
            <div><p class="admin-eyebrow" data-i18n="admin.network.eyebrow">Distribution</p><h1 data-i18n="admin.network.title">Probe network</h1><p class="admin-section-description" data-i18n="admin.network.description">Run checks from several independent locations and aggregate their observations.</p></div>
            <div class="admin-section-actions"><span class="admin-section-count"><?= $liveNodes ?>/<?= count($nodes) ?></span><?php if ($canManageNetwork): ?><button class="admin-primary-button" type="button" data-node-create aria-label="Add agent" title="Add agent" data-i18n-aria-label="admin.network.addAgent" data-i18n-title="admin.network.addAgent"><i class="fa-solid fa-plus" aria-hidden="true"></i><span data-i18n="admin.network.addAgent">Add agent</span></button><?php endif; ?></div>
          </div>
          <?php if ($distributedMode !== 'hub'): ?><div class="admin-notification-notice" role="status"><i class="fa-solid fa-circle-info" aria-hidden="true"></i><div><strong data-i18n="admin.network.standaloneTitle">Standalone monitoring is active</strong><span data-i18n="admin.network.standaloneHint">Set INSIGHT_DISTRIBUTED_MODE=hub and restart the worker before relying on remote agents.</span></div></div><?php endif; ?>
          <div class="admin-page-feedback" data-node-page-feedback role="status" hidden></div>
          <div class="admin-network-summary" aria-label="Distributed monitoring summary" data-i18n-aria-label="admin.network.summaryLabel">
            <div><span data-i18n="admin.network.mode">Mode</span><strong data-i18n="<?= $distributedMode === 'hub' ? 'admin.network.modeHub' : 'admin.network.modeStandalone' ?>"><?= $distributedMode === 'hub' ? 'Distributed hub' : 'Standalone' ?></strong></div>
            <div><span data-i18n="admin.network.liveNodes">Active nodes</span><strong><?= $liveNodes ?>/<?= count($nodes) ?></strong></div>
            <div><span data-i18n="admin.network.healthyConsensus">Healthy consensus</span><strong><?= $healthyConsensus ?>/<?= count($consensus) ?></strong></div>
            <div><span data-i18n="admin.network.defaultReplication">Default replication</span><strong><?= max(0, (int)(getenv('INSIGHT_AGENT_DEFAULT_REPLICAS') ?: 3)) ?></strong></div>
          </div>
          <div class="admin-network-grid">
            <div class="admin-network-column">
              <div class="admin-network-column-heading"><strong data-i18n="admin.network.nodes">Agents</strong><span><?= count($nodes) ?></span></div>
              <div class="admin-node-list">
                <?php if ($nodes === []): ?>
                  <div class="admin-empty"><i class="fa-solid fa-server" aria-hidden="true"></i><span data-i18n="admin.network.noNodes">No registered agent.</span></div>
                <?php endif; ?>
                <?php foreach ($nodes as $node): ?>
                  <?php [$nodeStatusClass, $nodeStatusKey] = insight_dashboard_node_status($node); ?>
                  <?php $lastSeenAt = insight_dashboard_iso(isset($node['last_seen_at']) ? (string)$node['last_seen_at'] : null); ?>
                  <?php $nodeLocation = implode(' · ', array_filter([trim((string)($node['region'] ?? '')), trim((string)($node['zone'] ?? ''))])); ?>
                  <article class="admin-node-row" data-node-row data-node-key="<?= insight_admin_escape((string)($node['node_key'] ?? '')) ?>" data-node-status="<?= insight_admin_escape((string)($node['status'] ?? 'active')) ?>">
                    <span class="admin-node-icon"><i class="fa-solid fa-server" aria-hidden="true"></i></span>
                    <div class="admin-node-copy">
                      <strong><?= insight_admin_escape((string)($node['display_name'] ?? $node['node_key'] ?? 'Agent')) ?></strong>
                      <span><?= insight_admin_escape($nodeLocation !== '' ? $nodeLocation : (string)($node['node_key'] ?? '')) ?></span>
                    </div>
                    <div class="admin-node-state">
                      <span class="admin-status-badge" data-status="<?= insight_admin_escape($nodeStatusClass) ?>"><span aria-hidden="true"></span><span data-i18n="<?= insight_admin_escape($nodeStatusKey) ?>"><?= insight_admin_escape($nodeStatusClass) ?></span></span>
                      <small><?= (int)($node['assignments'] ?? 0) ?> <span data-i18n="admin.network.targets">targets</span><?php if ($lastSeenAt !== ''): ?> · <time datetime="<?= insight_admin_escape($lastSeenAt) ?>"></time><?php endif; ?></small>
                    </div>
                    <?php if ($canManageNetwork): ?><div class="admin-row-actions admin-node-actions"><?php if (($node['status'] ?? 'active') === 'active'): ?><button class="admin-icon-button" type="button" data-node-status-action="paused" aria-label="Pause agent" title="Pause agent" data-i18n-aria-label="admin.network.pause" data-i18n-title="admin.network.pause"><i class="fa-solid fa-pause" aria-hidden="true"></i></button><?php else: ?><button class="admin-icon-button" type="button" data-node-status-action="active" aria-label="Activate agent" title="Activate agent" data-i18n-aria-label="admin.network.activate" data-i18n-title="admin.network.activate"><i class="fa-solid fa-play" aria-hidden="true"></i></button><?php endif; ?><?php if (($node['status'] ?? '') !== 'revoked'): ?><button class="admin-icon-button is-destructive" type="button" data-node-status-action="revoked" aria-label="Revoke agent" title="Revoke agent" data-i18n-aria-label="admin.network.revoke" data-i18n-title="admin.network.revoke"><i class="fa-solid fa-ban" aria-hidden="true"></i></button><?php endif; ?></div><?php endif; ?>
                  </article>
                <?php endforeach; ?>
              </div>
            </div>
            <div class="admin-network-column">
              <div class="admin-network-column-heading"><strong data-i18n="admin.network.consensus">Current consensus</strong><span><?= count($consensus) ?></span></div>
              <div class="admin-consensus-list">
                <?php if ($consensus === []): ?>
                  <div class="admin-empty"><i class="fa-solid fa-code-branch" aria-hidden="true"></i><span data-i18n="admin.network.noConsensus">No consensus computed.</span></div>
                <?php endif; ?>
                <?php foreach ($consensus as $current): ?>
                  <?php [$consensusStatusClass, $consensusStatusKey] = insight_dashboard_status((string)($current['status'] ?? 'unknown')); ?>
                  <article class="admin-consensus-row">
                    <div class="admin-consensus-copy">
                      <strong><?= insight_admin_escape(insight_dashboard_host((string)($current['url'] ?? 'Service'))) ?></strong>
                      <span><?= (int)($current['nodes_fresh'] ?? 0) ?>/<?= (int)($current['nodes_expected'] ?? 0) ?> <span data-i18n="admin.network.responses">responses</span> · <?= insight_dashboard_number(((float)($current['confidence'] ?? 0)) * 100) ?>%</span>
                      <?php if (!empty($current['reinforced_ends_at'])): ?><span><span data-i18n="admin.runtime.reinforcedUntil">Reinforced until</span> <time datetime="<?= insight_admin_escape(insight_dashboard_iso((string)$current['reinforced_ends_at'])) ?>"></time> · <?= (int)($current['reinforced_interval_sec'] ?? 10) ?> s</span><?php endif; ?>
                    </div>
                    <div class="admin-consensus-counts" aria-label="Response distribution" data-i18n-aria-label="admin.network.distributionLabel">
                      <span data-kind="online"><i aria-hidden="true"></i><?= (int)($current['nodes_online'] ?? 0) ?></span>
                      <span data-kind="degraded"><i aria-hidden="true"></i><?= (int)($current['nodes_degraded'] ?? 0) ?></span>
                      <span data-kind="offline"><i aria-hidden="true"></i><?= (int)($current['nodes_offline'] ?? 0) ?></span>
                      <span data-kind="missing"><i aria-hidden="true"></i><?= (int)($current['nodes_missing'] ?? 0) ?></span>
                    </div>
                    <span class="admin-status-badge" data-status="<?= insight_admin_escape($consensusStatusClass) ?>"><span aria-hidden="true"></span><span data-i18n="<?= insight_admin_escape($consensusStatusKey) ?>"><?= insight_admin_escape($consensusStatusClass) ?></span></span>
                  </article>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
        </section>

        <div class="admin-view-fragments">
          <section id="incidents" class="admin-section admin-detail-section admin-route-section" data-admin-view="incidents" hidden>
            <div class="admin-section-heading">
              <div><p class="admin-eyebrow" data-i18n="admin.incidents.eyebrow">Events</p><h1 data-i18n="admin.incidents.title">Recent incidents</h1><p class="admin-section-description" data-i18n="admin.incidents.description">Publish updates from detection through resolution.</p></div>
              <div class="admin-section-actions"><span class="admin-section-count"><?= count($incidents) ?></span><?php if ($canManageIncidents): ?><button class="admin-primary-button" type="button" data-incident-create aria-label="New incident" title="New incident" data-i18n-aria-label="admin.incidents.create" data-i18n-title="admin.incidents.create"><i class="fa-solid fa-plus" aria-hidden="true"></i><span data-i18n="admin.incidents.create">New incident</span></button><?php endif; ?></div>
            </div>
            <div class="admin-event-list">
              <?php if ($incidents === []): ?>
                <div class="admin-empty"><i class="fa-regular fa-circle-check" aria-hidden="true"></i><span data-i18n="admin.incidents.empty">No recent incidents.</span></div>
              <?php endif; ?>
              <?php foreach ($incidents as $incident): ?>
                <?php $lifecycle = (string)($incident['lifecycle_status'] ?? (empty($incident['ended_at']) ? 'started' : 'resolved')); ?>
                <?php $isOpen = $lifecycle !== 'resolved'; ?>
                <?php $startedAt = insight_dashboard_iso(isset($incident['started_at']) ? (string)$incident['started_at'] : null); ?>
                <?php $postmortem = trim((string)($incident['postmortem'] ?? '')); ?>
                <?php $incidentTitle = trim((string)($incident['title'] ?? '')) ?: insight_dashboard_host((string)$incident['url']); ?>
                <?php $sitesLabel = trim((string)($incident['sites_label'] ?? '')) ?: insight_dashboard_host((string)$incident['url']); ?>
                <?php $incidentJson = json_encode($incident, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>
                <article class="admin-event-row" data-incident-row data-incident-json="<?= insight_admin_escape((string)$incidentJson) ?>">
                  <span class="admin-event-marker" data-event="<?= $isOpen ? 'open' : 'resolved' ?>"><i class="fa-solid <?= $isOpen ? 'fa-triangle-exclamation' : 'fa-check' ?>" aria-hidden="true"></i></span>
                  <div class="admin-event-copy">
                    <div><strong><?= insight_admin_escape($incidentTitle) ?></strong><span class="admin-event-badges"><span class="admin-event-state" data-event="<?= insight_admin_escape($lifecycle) ?>" data-i18n="admin.incidents.lifecycle.<?= insight_admin_escape($lifecycle) ?>"><?= insight_admin_escape($lifecycle) ?></span><span class="admin-severity-badge" data-severity="<?= insight_admin_escape((string)($incident['severity'] ?? 'major')) ?>" data-i18n="admin.incidents.severity.<?= insight_admin_escape((string)($incident['severity'] ?? 'major')) ?>"><?= insight_admin_escape((string)($incident['severity'] ?? 'major')) ?></span><?php if (!$isOpen): ?><span class="admin-status-badge" data-status="<?= $postmortem !== '' ? 'operational' : 'unknown' ?>" data-postmortem-state><span aria-hidden="true"></span><span data-i18n="<?= $postmortem !== '' ? 'admin.incidents.postmortemReady' : 'admin.incidents.postmortemMissing' ?>"><?= $postmortem !== '' ? 'Postmortem ready' : 'To write' ?></span></span><?php endif; ?></span></div>
                    <p><?= insight_admin_escape(trim((string)($incident['summary'] ?? '')) ?: $sitesLabel) ?></p>
                    <span class="admin-event-meta"><span><i class="fa-solid fa-layer-group" aria-hidden="true"></i><?= insight_admin_escape($sitesLabel) ?></span><?php if ($startedAt !== ''): ?><time datetime="<?= insight_admin_escape($startedAt) ?>"></time><?php endif; ?><?php if (isset($incident['http_code'])): ?><span>HTTP <?= (int)$incident['http_code'] ?></span><?php endif; ?><?php if (!empty($incident['group_title'])): ?><span><i class="fa-solid fa-link" aria-hidden="true"></i><?= insight_admin_escape((string)$incident['group_title']) ?> · <?= (int)($incident['group_occurrence_count'] ?? 1) ?></span><?php endif; ?><?php if (!empty($incident['runbook_name'])): ?><span><i class="fa-solid fa-book" aria-hidden="true"></i><?= insight_admin_escape((string)$incident['runbook_name']) ?></span><?php endif; ?></span>
                    <?php $recentUpdates = array_slice((array)($incident['updates'] ?? []), -2); ?>
                    <?php if ($recentUpdates !== []): ?><div class="admin-incident-timeline"><?php foreach ($recentUpdates as $update): ?><div><span aria-hidden="true"></span><p><?= insight_admin_escape((string)($update['message'] ?? '')) ?></p><time datetime="<?= insight_admin_escape(insight_dashboard_iso((string)($update['created_at'] ?? ''))) ?>"></time></div><?php endforeach; ?></div><?php endif; ?>
                    <?php if (!empty($incident['comments'])): ?><div class="admin-incident-comments"><?php foreach (array_slice((array)$incident['comments'], -2) as $comment): ?><div><i class="fa-regular fa-comment" aria-hidden="true"></i><p><?= insight_admin_escape((string)($comment['body'] ?? '')) ?></p><span><?= insight_admin_escape((string)($comment['author_name'] ?? 'Insight')) ?> · <time datetime="<?= insight_admin_escape(insight_dashboard_iso((string)($comment['created_at'] ?? ''))) ?>"></time></span></div><?php endforeach; ?></div><?php endif; ?>
                    <?php if (!empty($incident['attachments'])): ?><div class="admin-incident-attachments"><?php foreach ((array)$incident['attachments'] as $attachment): ?><a href="/admin/incident-attachment.php?id=<?= (int)($attachment['id'] ?? 0) ?>"><i class="fa-solid fa-paperclip" aria-hidden="true"></i><span><?= insight_admin_escape((string)($attachment['original_name'] ?? 'Attachment')) ?></span></a><?php endforeach; ?></div><?php endif; ?>
                  </div>
                  <?php if ($canManageIncidents): ?><div class="admin-row-actions admin-incident-actions"><button class="admin-icon-button" type="button" data-incident-action="comment" aria-label="Internal comment" title="Internal comment" data-i18n-aria-label="admin.incidents.comment" data-i18n-title="admin.incidents.comment"><i class="fa-regular fa-comment" aria-hidden="true"></i></button><button class="admin-icon-button" type="button" data-incident-action="update" aria-label="Publish update" title="Publish update" data-i18n-aria-label="admin.incidents.addUpdate" data-i18n-title="admin.incidents.addUpdate"><i class="fa-solid fa-message" aria-hidden="true"></i></button><?php if ($lifecycle === 'started'): ?><button class="admin-icon-button" type="button" data-incident-action="acknowledge" aria-label="Acknowledge" title="Acknowledge" data-i18n-aria-label="admin.incidents.acknowledge" data-i18n-title="admin.incidents.acknowledge"><i class="fa-solid fa-user-check" aria-hidden="true"></i></button><?php endif; ?><?php if ($isOpen && $lifecycle !== 'monitoring'): ?><button class="admin-icon-button" type="button" data-incident-action="monitoring" aria-label="Monitoring" title="Monitoring" data-i18n-aria-label="admin.incidents.monitoring" data-i18n-title="admin.incidents.monitoring"><i class="fa-solid fa-binoculars" aria-hidden="true"></i></button><?php endif; ?><?php if ($isOpen): ?><button class="admin-icon-button" type="button" data-incident-action="resolve" aria-label="Resolve" title="Resolve" data-i18n-aria-label="admin.incidents.resolve" data-i18n-title="admin.incidents.resolve"><i class="fa-solid fa-check" aria-hidden="true"></i></button><?php endif; ?><button class="admin-icon-button" type="button" data-incident-action="edit" aria-label="Edit incident" title="Edit incident" data-i18n-aria-label="admin.incidents.edit" data-i18n-title="admin.incidents.edit"><i class="fa-solid fa-pen" aria-hidden="true"></i></button><?php if (!$isOpen): ?><button class="admin-icon-button" type="button" data-incident-postmortem-edit aria-label="Edit postmortem" title="Edit postmortem" data-i18n-aria-label="admin.incidents.editPostmortem" data-i18n-title="admin.incidents.editPostmortem"><i class="fa-regular fa-file-lines" aria-hidden="true"></i></button><?php endif; ?><button class="admin-icon-button is-destructive" type="button" data-incident-action="delete" aria-label="Delete incident" title="Delete incident" data-i18n-aria-label="admin.incidents.delete" data-i18n-title="admin.incidents.delete"><i class="fa-regular fa-trash-can" aria-hidden="true"></i></button></div><?php endif; ?>
                </article>
              <?php endforeach; ?>
            </div>
            <section class="admin-runbook-section"><div class="admin-access-list-heading"><div><strong data-i18n="admin.incidents.runbooks">Runbooks</strong><span data-i18n="admin.incidents.runbooksHint">Internal procedures linked to incidents.</span></div><?php if ($canManageIncidents): ?><button class="admin-secondary-button" type="button" data-runbook-create><i class="fa-solid fa-plus" aria-hidden="true"></i><span data-i18n="admin.incidents.newRunbook">New runbook</span></button><?php endif; ?></div><div class="admin-runbook-list"><?php if ($runbooks === []): ?><div class="admin-empty"><i class="fa-solid fa-book" aria-hidden="true"></i><span data-i18n="admin.incidents.noRunbooks">No runbook.</span></div><?php endif; ?><?php foreach ($runbooks as $runbook): ?><?php $runbookJson = json_encode($runbook, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?><article class="admin-runbook-row" data-runbook-row data-runbook-json="<?= insight_admin_escape((string)$runbookJson) ?>"><span class="admin-notification-icon"><i class="fa-solid fa-book" aria-hidden="true"></i></span><div><strong><?= insight_admin_escape((string)$runbook['name']) ?></strong><span><?= insight_admin_escape((string)$runbook['slug']) ?></span></div><span class="admin-status-badge" data-status="<?= (int)($runbook['enabled'] ?? 1) === 1 ? 'operational' : 'unknown' ?>"><span aria-hidden="true"></span><span data-i18n="<?= (int)($runbook['enabled'] ?? 1) === 1 ? 'common.enabled' : 'common.disabled' ?>"><?= (int)($runbook['enabled'] ?? 1) === 1 ? 'Enabled' : 'Disabled' ?></span></span><?php if ($canManageIncidents): ?><div class="admin-row-actions"><button class="admin-icon-button" type="button" data-runbook-edit aria-label="Edit runbook" title="Edit runbook" data-i18n-aria-label="admin.incidents.editRunbook" data-i18n-title="admin.incidents.editRunbook"><i class="fa-solid fa-pen" aria-hidden="true"></i></button><button class="admin-icon-button is-destructive" type="button" data-runbook-delete aria-label="Delete runbook" title="Delete runbook" data-i18n-aria-label="admin.incidents.deleteRunbook" data-i18n-title="admin.incidents.deleteRunbook"><i class="fa-regular fa-trash-can" aria-hidden="true"></i></button></div><?php endif; ?></article><?php endforeach; ?></div></section>
          </section>

          <section id="notifications" class="admin-section admin-route-section" data-admin-view="notifications" hidden>
            <div class="admin-section-heading">
              <div><p class="admin-eyebrow" data-i18n="admin.notifications.eyebrow">Delivery</p><h1 data-i18n="admin.notifications.title">Alerts</h1><p class="admin-section-description" data-i18n="admin.notifications.description">Notify the right people as soon as a service changes status.</p></div>
              <div class="admin-section-actions"><span class="admin-section-count" data-notification-count><?= count($notificationChannels) ?></span><?php if ($canManageNotifications): ?><button class="admin-primary-button" type="button" data-notification-create aria-label="New channel" title="New channel" data-i18n-aria-label="admin.notifications.newChannel" data-i18n-title="admin.notifications.newChannel"><i class="fa-solid fa-plus" aria-hidden="true"></i><span data-i18n="admin.notifications.newChannel">New channel</span></button><?php endif; ?></div>
            </div>
            <?php if ($notificationsDisabled): ?>
              <div class="admin-notification-notice" data-notification-disabled role="status">
                <i class="fa-solid fa-pause" aria-hidden="true"></i>
                <div><strong data-i18n="admin.notifications.disabledTitle">Automatic delivery disabled</strong><span data-i18n="admin.notifications.disabledDescription">Tests remain available. Set INSIGHT_DISABLE_NOTIFICATIONS=0 to deliver monitoring alerts.</span></div>
              </div>
            <?php endif; ?>
            <div class="admin-page-feedback" data-notification-page-feedback role="status" hidden></div>
            <div class="admin-notification-grid">
              <section class="admin-tool-panel" aria-labelledby="notification-channels-title">
                <div class="admin-tool-panel-heading"><div><h2 id="notification-channels-title" data-i18n="admin.notifications.channels">Channels</h2><span data-i18n="admin.notifications.channelsHint">The same alert can be delivered to several destinations.</span></div><i class="fa-solid fa-tower-broadcast" aria-hidden="true"></i></div>
                <div class="admin-notification-list" data-notification-list>
                  <?php if ($notificationChannels === []): ?>
                    <div class="admin-empty admin-notification-empty" data-notification-empty><i class="fa-regular fa-bell-slash" aria-hidden="true"></i><span data-i18n="admin.notifications.empty">No channel configured.</span></div>
                  <?php endif; ?>
                  <?php foreach ($notificationChannels as $channel): ?>
                    <?php $channelJson = json_encode($channel, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>
                    <?php $channelStatus = (string)($channel['last_status'] ?? 'unknown'); ?>
                    <article class="admin-notification-row" data-notification-channel data-channel-id="<?= (int)$channel['id'] ?>" data-channel-json="<?= insight_admin_escape((string)$channelJson) ?>">
                      <span class="admin-notification-icon"><i class="<?= insight_admin_escape((string)$channel['provider_icon']) ?>" aria-hidden="true"></i></span>
                      <div class="admin-notification-copy"><strong><?= insight_admin_escape((string)$channel['name']) ?></strong><span><?= insight_admin_escape((string)$channel['provider_label']) ?> · <?= count((array)$channel['events']) ?> <span data-i18n="admin.notifications.eventsShort">events</span></span></div>
                      <span class="admin-status-badge" data-status="<?= $channel['enabled'] ? ($channelStatus === 'error' ? 'offline' : ($channelStatus === 'success' ? 'operational' : 'unknown')) : 'unknown' ?>"><span aria-hidden="true"></span><span data-i18n="<?= $channel['enabled'] ? ($channelStatus === 'error' ? 'admin.notifications.error' : 'admin.notifications.active') : 'admin.notifications.inactive' ?>"><?= $channel['enabled'] ? ($channelStatus === 'error' ? 'Error' : 'Active') : 'Inactive' ?></span></span>
                      <?php if ($canManageNotifications): ?><div class="admin-row-actions">
                        <button class="admin-icon-button" type="button" data-notification-test aria-label="Test channel" title="Test channel" data-i18n-aria-label="admin.notifications.test" data-i18n-title="admin.notifications.test"><i class="fa-solid fa-paper-plane" aria-hidden="true"></i></button>
                        <button class="admin-icon-button" type="button" data-notification-edit aria-label="Edit channel" title="Edit channel" data-i18n-aria-label="admin.notifications.edit" data-i18n-title="admin.notifications.edit"><i class="fa-solid fa-pen" aria-hidden="true"></i></button>
                        <button class="admin-icon-button is-destructive" type="button" data-notification-delete aria-label="Delete channel" title="Delete channel" data-i18n-aria-label="admin.notifications.delete" data-i18n-title="admin.notifications.delete"><i class="fa-regular fa-trash-can" aria-hidden="true"></i></button>
                      </div><?php endif; ?>
                    </article>
                  <?php endforeach; ?>
                </div>
              </section>
              <section class="admin-tool-panel" aria-labelledby="notification-templates-title">
                <div class="admin-tool-panel-heading"><div><h2 id="notification-templates-title" data-i18n="admin.notifications.templates">Messages</h2><span data-i18n="admin.notifications.templatesHint">Each event type has its own message.</span></div><i class="fa-solid fa-pen-to-square" aria-hidden="true"></i></div>
                <form class="admin-template-form" data-notification-template-form<?= $canManageNotifications ? '' : ' data-readonly="true"' ?>>
                  <label class="admin-field">
                    <span data-i18n="admin.notifications.event">Event</span>
                    <span class="admin-select-wrap"><i class="fa-solid fa-wave-square" aria-hidden="true"></i><select name="event"><?php foreach (insight_notifications_events() as $event): ?><option value="<?= insight_admin_escape($event) ?>" data-i18n="admin.notifications.event.<?= insight_admin_escape($event) ?>"><?= insight_admin_escape(str_replace('_', ' ', ucfirst($event))) ?></option><?php endforeach; ?></select></span>
                  </label>
                  <label class="admin-field">
                    <span data-i18n="admin.notifications.subject">Title</span>
                    <span class="admin-input-wrap"><i class="fa-solid fa-heading" aria-hidden="true"></i><input type="text" name="title" maxlength="500" required value="<?= insight_admin_escape((string)($notificationTemplates['monitor_down']['title'] ?? '')) ?>"<?= $canManageNotifications ? '' : ' readonly' ?>></span>
                  </label>
                  <label class="admin-field">
                    <span data-i18n="admin.notifications.message">Message</span>
                    <textarea class="admin-textarea" name="body" maxlength="10000" rows="7" required<?= $canManageNotifications ? '' : ' readonly' ?>><?= insight_admin_escape((string)($notificationTemplates['monitor_down']['body'] ?? '')) ?></textarea>
                  </label>
                  <div class="admin-template-tokens" aria-label="Available variables" data-i18n-aria-label="admin.notifications.variables"><button type="button" data-template-token="{{ app_name }}">app_name</button><button type="button" data-template-token="{{ domain }}">domain</button><button type="button" data-template-token="{{ sites }}">sites</button><button type="button" data-template-token="{{ status }}">status</button><button type="button" data-template-token="{{ message }}">message</button><button type="button" data-template-token="{{ timestamp }}">timestamp</button></div>
                  <div class="admin-probe-feedback" data-notification-template-feedback role="alert" hidden></div>
                  <div class="admin-template-actions"><span data-i18n="admin.notifications.liquidHint">Liquid syntax compatible with Uptime Kuma.</span><?php if ($canManageNotifications): ?><button class="admin-secondary-button" type="submit"><i class="fa-solid fa-check" aria-hidden="true"></i><span data-i18n="admin.notifications.saveMessage">Save</span></button><?php endif; ?></div>
                </form>
              </section>
            </div>
            <section class="admin-delivery-panel admin-oncall-panel" aria-labelledby="oncall-title">
              <div class="admin-tool-panel-heading">
                <div><h2 id="oncall-title" data-i18n="admin.oncall.title">On-call rotations</h2><span data-i18n="admin.oncall.description">Escalate unacknowledged incidents to the active destination.</span></div>
                <div class="admin-section-actions"><span class="admin-section-count" data-oncall-count><?= count($oncallSchedules) ?></span><?php if ($canManageNotifications): ?><button class="admin-secondary-button" type="button" data-oncall-create><i class="fa-solid fa-plus" aria-hidden="true"></i><span data-i18n="admin.oncall.create">New rotation</span></button><?php endif; ?></div>
              </div>
              <div class="admin-oncall-list" data-oncall-list>
                <?php if ($oncallSchedules === []): ?><div class="admin-empty" data-oncall-empty><i class="fa-solid fa-user-clock" aria-hidden="true"></i><span data-i18n="admin.oncall.empty">No on-call rotation configured.</span></div><?php endif; ?>
                <?php foreach ($oncallSchedules as $schedule): ?>
                  <?php $scheduleJson = json_encode($schedule, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>
                  <article class="admin-oncall-row" data-oncall-row data-oncall-id="<?= (int)$schedule['id'] ?>" data-oncall-json="<?= insight_admin_escape((string)$scheduleJson) ?>">
                    <span class="admin-notification-icon"><i class="fa-solid fa-user-clock" aria-hidden="true"></i></span>
                    <div class="admin-notification-copy"><strong><?= insight_admin_escape((string)$schedule['name']) ?></strong><span><?= count((array)($schedule['members'] ?? [])) ?> <span data-i18n="admin.oncall.membersShort">shifts</span> · <?= (int)($schedule['escalation_delay_minutes'] ?? 5) ?> min · <?= insight_admin_escape((string)($schedule['timezone'] ?? 'UTC')) ?></span></div>
                    <span class="admin-status-badge" data-status="<?= !empty($schedule['enabled']) ? 'operational' : 'unknown' ?>"><span aria-hidden="true"></span><span data-i18n="<?= !empty($schedule['enabled']) ? 'admin.oncall.active' : 'admin.oncall.inactive' ?>"><?= !empty($schedule['enabled']) ? 'Active' : 'Inactive' ?></span></span>
                    <?php if ($canManageNotifications): ?><div class="admin-row-actions"><button class="admin-icon-button" type="button" data-oncall-edit aria-label="Edit rotation" title="Edit rotation" data-i18n-aria-label="admin.oncall.edit" data-i18n-title="admin.oncall.edit"><i class="fa-solid fa-pen" aria-hidden="true"></i></button><button class="admin-icon-button is-destructive" type="button" data-oncall-delete aria-label="Delete rotation" title="Delete rotation" data-i18n-aria-label="admin.oncall.delete" data-i18n-title="admin.oncall.delete"><i class="fa-regular fa-trash-can" aria-hidden="true"></i></button></div><?php endif; ?>
                  </article>
                <?php endforeach; ?>
              </div>
            </section>
            <section class="admin-delivery-panel" aria-labelledby="notification-deliveries-title">
              <div class="admin-tool-panel-heading"><div><h2 id="notification-deliveries-title" data-i18n="admin.notifications.history">Recent deliveries</h2><span data-i18n="admin.notifications.historyHint">Deliveries are retained for 90 days.</span></div><i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i></div>
              <div class="admin-delivery-list" data-notification-deliveries>
                <?php if ($notificationDeliveries === []): ?>
                  <div class="admin-empty" data-delivery-empty><i class="fa-regular fa-clock" aria-hidden="true"></i><span data-i18n="admin.notifications.noHistory">No delivery yet.</span></div>
                <?php endif; ?>
                <?php foreach ($notificationDeliveries as $delivery): ?>
                  <?php $deliveryTime = insight_dashboard_iso(isset($delivery['attempted_at']) ? (string)$delivery['attempted_at'] : null); ?>
                  <div class="admin-delivery-row"><span class="admin-delivery-state" data-status="<?= ($delivery['status'] ?? '') === 'sent' ? 'success' : 'error' ?>"><i class="fa-solid <?= ($delivery['status'] ?? '') === 'sent' ? 'fa-check' : 'fa-xmark' ?>" aria-hidden="true"></i></span><div><strong><?= insight_admin_escape((string)($delivery['channel_name'] ?? 'Canal')) ?></strong><span><?= insight_admin_escape((string)($delivery['title_rendered'] ?? $delivery['event_key'] ?? '')) ?></span></div><span class="admin-delivery-event" data-i18n="admin.notifications.event.<?= insight_admin_escape((string)($delivery['event_key'] ?? 'test')) ?>"><?= insight_admin_escape((string)($delivery['event_key'] ?? 'test')) ?></span><?php if ($deliveryTime !== ''): ?><time datetime="<?= insight_admin_escape($deliveryTime) ?>"></time><?php endif; ?></div>
                <?php endforeach; ?>
              </div>
            </section>
          </section>

          <section id="maintenance" class="admin-section admin-detail-section admin-route-section" data-admin-view="maintenance" hidden>
            <div class="admin-section-heading">
              <div><p class="admin-eyebrow" data-i18n="admin.maintenance.eyebrow">Schedule</p><h1 data-i18n="admin.maintenance.title">Maintenance</h1><p class="admin-section-description" data-i18n="admin.maintenance.description">Plan windows, recurrence, and affected monitors.</p></div>
              <div class="admin-section-actions"><span class="admin-section-count"><?= count($maintenances) ?></span><?php if ($canManageMaintenance): ?><button class="admin-primary-button" type="button" data-maintenance-create aria-label="Plan maintenance" title="Plan maintenance" data-i18n-aria-label="admin.maintenance.create" data-i18n-title="admin.maintenance.create"><i class="fa-solid fa-plus" aria-hidden="true"></i><span data-i18n="admin.maintenance.create">Plan maintenance</span></button><?php endif; ?></div>
            </div>
            <div class="admin-event-list">
              <?php if ($maintenances === []): ?>
                <div class="admin-empty"><i class="fa-regular fa-calendar" aria-hidden="true"></i><span data-i18n="admin.maintenance.empty">No upcoming maintenance.</span></div>
              <?php endif; ?>
              <?php foreach ($maintenances as $maintenance): ?>
                <?php $startsAt = insight_dashboard_iso(isset($maintenance['starts_at']) ? (string)$maintenance['starts_at'] : null); ?>
                <?php $endsAt = insight_dashboard_iso(isset($maintenance['ends_at']) ? (string)$maintenance['ends_at'] : null); ?>
                <?php $maintenanceJson = json_encode($maintenance, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>
                <article class="admin-event-row" data-maintenance-row data-maintenance-json="<?= insight_admin_escape((string)$maintenanceJson) ?>">
                  <span class="admin-event-marker" data-event="maintenance"><i class="fa-solid fa-wrench" aria-hidden="true"></i></span>
                  <div class="admin-event-copy">
                    <div><strong><?= insight_admin_escape((string)$maintenance['title']) ?></strong><span class="admin-event-state" data-event="maintenance" data-i18n="admin.maintenance.status.<?= insight_admin_escape((string)($maintenance['status'] ?? 'planned')) ?>"><?= insight_admin_escape((string)($maintenance['status'] ?? 'planned')) ?></span><?php if (($maintenance['recurrence'] ?? 'none') !== 'none'): ?><span class="admin-status-badge" data-status="unknown"><i class="fa-solid fa-repeat" aria-hidden="true"></i><span><?= insight_admin_escape((string)$maintenance['recurrence']) ?></span></span><?php endif; ?></div>
                    <p><?= insight_admin_escape(trim((string)($maintenance['description'] ?? '')) ?: insight_dashboard_host((string)$maintenance['url'])) ?></p>
                    <span class="admin-event-meta"><?php if ($startsAt !== ''): ?><time datetime="<?= insight_admin_escape($startsAt) ?>"></time><?php endif; ?><?php if ($endsAt !== ''): ?><span><span data-i18n="admin.maintenance.until">until</span> <time datetime="<?= insight_admin_escape($endsAt) ?>"></time></span><?php endif; ?></span>
                  </div>
                  <?php if ($canManageMaintenance): ?><div class="admin-row-actions"><button class="admin-icon-button" type="button" data-maintenance-edit aria-label="Edit maintenance" title="Edit maintenance" data-i18n-aria-label="admin.maintenance.edit" data-i18n-title="admin.maintenance.edit"><i class="fa-solid fa-pen" aria-hidden="true"></i></button><button class="admin-icon-button is-destructive" type="button" data-maintenance-delete aria-label="Delete maintenance" title="Delete maintenance" data-i18n-aria-label="admin.maintenance.delete" data-i18n-title="admin.maintenance.delete"><i class="fa-regular fa-trash-can" aria-hidden="true"></i></button></div><?php endif; ?>
                </article>
              <?php endforeach; ?>
            </div>
          </section>

          <section id="status-pages" class="admin-section admin-detail-section admin-route-section" data-admin-view="status-pages" hidden>
            <div class="admin-section-heading"><div><p class="admin-eyebrow" data-i18n="admin.statusPages.eyebrow">Publishing</p><h1 data-i18n="admin.statusPages.title">Status pages</h1><p class="admin-section-description" data-i18n="admin.statusPages.description">Publish separate public or private views for each audience.</p></div><div class="admin-section-actions"><span class="admin-section-count"><?= count($statusPages) ?></span><?php if ($canManageStatusPages): ?><button class="admin-primary-button" type="button" data-status-page-create aria-label="New page" title="New page" data-i18n-aria-label="admin.statusPages.create" data-i18n-title="admin.statusPages.create"><i class="fa-solid fa-plus" aria-hidden="true"></i><span data-i18n="admin.statusPages.create">New page</span></button><?php endif; ?></div></div>
            <div class="admin-status-page-list">
              <?php if ($statusPages === []): ?><div class="admin-empty"><i class="fa-solid fa-window-maximize" aria-hidden="true"></i><span data-i18n="admin.statusPages.empty">No status page configured.</span></div><?php endif; ?>
              <?php foreach ($statusPages as $page): ?>
                <?php $pageJson = json_encode($page, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>
                <?php $pageUrl = (string)($insightAdminConfig['public_url'] ?? '/'); $pageUrl .= (string)$page['slug'] === 'default' ? '' : '?page=' . rawurlencode((string)$page['slug']); ?>
                <article class="admin-status-page-row" data-status-page-row data-status-page-json="<?= insight_admin_escape((string)$pageJson) ?>"><span class="admin-status-page-icon"><i class="fa-solid <?= ($page['visibility'] ?? 'public') === 'private' ? 'fa-lock' : 'fa-globe' ?>" aria-hidden="true"></i></span><div><strong><?= insight_admin_escape((string)$page['name']) ?></strong><span><?= insight_admin_escape((string)$page['slug']) ?> · <?= count((array)($page['monitors'] ?? [])) ?> <span data-i18n="admin.statusPages.monitorsShort">monitors</span> · <?= (int)($page['subscribers_count'] ?? 0) ?> <span data-i18n="admin.statusPages.subscribersShort">subscribers</span></span></div><span class="admin-status-badge" data-status="<?= (int)($page['enabled'] ?? 0) === 1 ? 'operational' : 'unknown' ?>"><span aria-hidden="true"></span><span data-i18n="<?= (int)($page['enabled'] ?? 0) === 1 ? 'admin.statusPages.enabled' : 'admin.statusPages.disabled' ?>"><?= (int)($page['enabled'] ?? 0) === 1 ? 'Enabled' : 'Disabled' ?></span></span><div class="admin-row-actions"><a class="admin-icon-button" href="<?= insight_admin_escape($pageUrl) ?>" target="_blank" rel="noopener" aria-label="Open page" title="Open page" data-i18n-aria-label="admin.statusPages.open" data-i18n-title="admin.statusPages.open"><i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i></a><?php if ($canManageStatusPages): ?><button class="admin-icon-button" type="button" data-status-page-edit aria-label="Edit page" title="Edit page" data-i18n-aria-label="admin.statusPages.edit" data-i18n-title="admin.statusPages.edit"><i class="fa-solid fa-pen" aria-hidden="true"></i></button><?php if ((string)$page['slug'] !== 'default'): ?><button class="admin-icon-button is-destructive" type="button" data-status-page-delete aria-label="Delete page" title="Delete page" data-i18n-aria-label="admin.statusPages.delete" data-i18n-title="admin.statusPages.delete"><i class="fa-regular fa-trash-can" aria-hidden="true"></i></button><?php endif; ?><?php endif; ?></div></article>
              <?php endforeach; ?>
            </div>
          </section>
        </div>

        <div class="admin-view-fragments">
          <section class="admin-section admin-detail-section" data-admin-view="overview">
            <div class="admin-section-heading"><div><p class="admin-eyebrow" data-i18n="admin.runtime.eyebrow">Runtime</p><h2 data-i18n="admin.runtime.title">Monitoring engine</h2></div><span class="admin-status-badge" data-status="<?= insight_admin_escape($runtimeStatusClass) ?>"><span aria-hidden="true"></span><span data-i18n="<?= insight_admin_escape($runtimeStatusKey) ?>"><?= insight_admin_escape($runtimeStatusClass) ?></span></span></div>
            <dl class="admin-runtime-list">
              <div><dt data-i18n="admin.runtime.engine">Active engine</dt><dd><i class="fa-solid fa-code" aria-hidden="true"></i><?= insight_admin_escape($runtimeEngine) ?></dd></div>
              <div><dt data-i18n="admin.runtime.checked">Sites checked</dt><dd><?= (int)($runtime['sites_checked'] ?? $totalMonitors) ?></dd></div>
              <div><dt data-i18n="admin.runtime.errors">Errors on last run</dt><dd><?= (int)($runtime['errors_count'] ?? 0) ?></dd></div>
              <div><dt data-i18n="admin.runtime.reinforced">Reinforced monitoring</dt><dd><?= $reinforcedSites ?></dd></div>
              <div><dt data-i18n="admin.runtime.lastRun">Last run</dt><dd><?php $lastMonitor = insight_dashboard_iso(isset($runtime['last_monitor_at']) ? (string)$runtime['last_monitor_at'] : null); ?><?php if ($lastMonitor !== ''): ?><time datetime="<?= insight_admin_escape($lastMonitor) ?>"></time><?php else: ?><span data-i18n="admin.common.never">Never</span><?php endif; ?></dd></div>
            </dl>
          </section>

          <section id="account" class="admin-section admin-detail-section admin-route-section" data-admin-view="account" hidden>
            <div class="admin-section-heading"><div><p class="admin-eyebrow" data-i18n="admin.access.eyebrow">Security and integrations</p><h1 data-i18n="admin.access.title">Access</h1><p class="admin-section-description" data-i18n="admin.access.description">Choose who signs in to Insight and how your tools control it.</p></div><?php if ($canManageAccess): ?><a class="admin-secondary-button" href="/admin/integrations.php"><i class="fa-solid fa-book" aria-hidden="true"></i><span data-i18n="admin.access.integrationGuide">Integration guide</span></a><?php endif; ?></div>
            <div class="admin-access-identity">
              <span class="admin-account-icon"><i class="fa-solid <?= ($user['source'] ?? 'local') === 'oidc' ? 'fa-building-shield' : ($isDevBypass ? 'fa-unlock' : 'fa-user-shield') ?>" aria-hidden="true"></i></span>
              <div><strong><?= insight_admin_escape((string)$user['username']) ?></strong><span data-i18n="<?= ($user['source'] ?? 'local') === 'oidc' ? 'admin.account.ssoAdmin' : ($isDevBypass ? 'admin.account.devAdmin' : 'admin.account.localAdmin') ?>"><?= ($user['source'] ?? 'local') === 'oidc' ? 'SSO administrator' : ($isDevBypass ? 'Virtual development administrator' : 'Local administrator') ?></span></div>
              <span class="admin-status-badge" data-status="operational"><span aria-hidden="true"></span><span data-i18n="admin.access.sessionActive">Active session</span></span>
            </div>
            <div class="admin-page-feedback" data-security-feedback role="status" hidden></div>
            <div class="admin-security-grid">
              <section class="admin-tool-panel" aria-labelledby="security-account-title">
                <div class="admin-tool-panel-heading"><div><h2 id="security-account-title" data-i18n="admin.security.accountTitle">Account protection</h2><span data-i18n="admin.security.accountHint">Protect the local fallback account independently from SSO.</span></div><i class="fa-solid fa-shield-halved" aria-hidden="true"></i></div>
                <div class="admin-security-panel-body">
                  <?php if ($securityState['local_account'] ?? false): ?>
                    <div class="admin-security-setting"><span class="admin-security-setting-icon"><i class="fa-solid fa-mobile-screen-button" aria-hidden="true"></i></span><div><strong data-i18n="admin.security.totpTitle">Two-factor authentication</strong><span data-i18n="<?= ($securityState['totp_enabled'] ?? false) ? 'admin.security.totpEnabledHint' : 'admin.security.totpDisabledHint' ?>"><?= ($securityState['totp_enabled'] ?? false) ? 'Required at each local sign-in.' : 'Add a code from an authenticator app.' ?></span></div><span class="admin-status-badge" data-status="<?= ($securityState['totp_enabled'] ?? false) ? 'operational' : 'unknown' ?>"><span aria-hidden="true"></span><span data-i18n="<?= ($securityState['totp_enabled'] ?? false) ? 'admin.security.enabled' : 'admin.security.disabled' ?>"><?= ($securityState['totp_enabled'] ?? false) ? 'Enabled' : 'Disabled' ?></span></span></div>
                    <div class="admin-security-actions"><?php if ($securityState['totp_enabled'] ?? false): ?><button class="admin-secondary-button" type="button" data-security-recovery><i class="fa-solid fa-key" aria-hidden="true"></i><span data-i18n="admin.security.regenerateRecovery">New recovery codes</span></button><button class="admin-secondary-button is-destructive" type="button" data-security-totp-disable><i class="fa-solid fa-shield" aria-hidden="true"></i><span data-i18n="admin.security.disableTotp">Disable 2FA</span></button><?php else: ?><button class="admin-primary-button" type="button" data-security-totp-begin><i class="fa-solid fa-shield" aria-hidden="true"></i><span data-i18n="admin.security.enableTotp">Enable 2FA</span></button><?php endif; ?><button class="admin-secondary-button" type="button" data-security-password><i class="fa-solid fa-lock" aria-hidden="true"></i><span data-i18n="admin.security.changePassword">Change password</span></button></div>
                  <?php else: ?>
                    <div class="admin-access-config-note"><i class="fa-solid <?= $isDevBypass ? 'fa-unlock' : 'fa-building-shield' ?>" aria-hidden="true"></i><div><strong data-i18n="admin.security.localUnavailable">Local account unavailable</strong><span data-i18n="<?= $isDevBypass ? 'admin.security.devBypassHint' : 'admin.security.ssoAccountHint' ?>"><?= $isDevBypass ? 'Development bypass does not create a persistent account.' : 'Account protection is managed by your identity provider.' ?></span></div></div>
                  <?php endif; ?>
                </div>
              </section>
              <?php if ($canManageUsers): ?>
                <section class="admin-tool-panel admin-security-users-panel" aria-labelledby="security-users-title">
                  <div class="admin-tool-panel-heading"><div><h2 id="security-users-title" data-i18n="admin.security.usersTitle">Local users</h2><span data-i18n="admin.security.usersHint">Grant the minimum role required for each operator.</span></div><button class="admin-secondary-button" type="button" data-security-user-create><i class="fa-solid fa-user-plus" aria-hidden="true"></i><span data-i18n="admin.security.addUser">Add user</span></button></div>
                  <div class="admin-security-user-list">
                    <?php foreach ($securityUsers as $securityUser): ?>
                      <div class="admin-security-user-row" data-security-user data-user-id="<?= (int)$securityUser['id'] ?>">
                        <span class="admin-account-icon"><i class="fa-solid fa-user-shield" aria-hidden="true"></i></span>
                        <div><strong><?= insight_admin_escape((string)$securityUser['username']) ?></strong><span><?php if ($securityUser['last_login_at']): ?><span data-i18n="admin.security.lastLogin">Last login</span> <time datetime="<?= insight_admin_escape(insight_dashboard_utc_iso((string)$securityUser['last_login_at'])) ?>"></time><?php else: ?><span data-i18n="admin.security.neverSignedIn">Never signed in</span><?php endif; ?><?php if ($securityUser['totp_enabled']): ?> · <span data-i18n="admin.security.twoFactorShort">2FA</span><?php endif; ?></span></div>
                        <label class="admin-security-role"><span class="admin-select-wrap"><i class="fa-solid fa-user-tag" aria-hidden="true"></i><select data-security-user-role aria-label="Role" data-i18n-aria-label="admin.security.role"><?php foreach (['admin', 'operator', 'viewer'] as $role): ?><option value="<?= $role ?>"<?= $securityUser['role'] === $role ? ' selected' : '' ?> data-i18n="admin.security.role.<?= $role ?>"><?= ucfirst($role) ?></option><?php endforeach; ?></select></span></label>
                        <label class="admin-switch admin-security-active"><input type="checkbox" data-security-user-active<?= $securityUser['active'] ? ' checked' : '' ?>><span aria-hidden="true"></span><span><strong data-i18n="admin.security.active">Active</strong></span></label>
                        <div class="admin-row-actions"><button class="admin-icon-button" type="button" data-security-user-save aria-label="Save user" title="Save user" data-i18n-aria-label="admin.security.saveUser" data-i18n-title="admin.security.saveUser"><i class="fa-solid fa-check" aria-hidden="true"></i></button><?php if ((int)$securityUser['id'] !== $localUserId): ?><button class="admin-icon-button is-destructive" type="button" data-security-user-delete aria-label="Delete user" title="Delete user" data-i18n-aria-label="admin.security.deleteUser" data-i18n-title="admin.security.deleteUser"><i class="fa-regular fa-trash-can" aria-hidden="true"></i></button><?php endif; ?></div>
                      </div>
                    <?php endforeach; ?>
                  </div>
                </section>
              <?php endif; ?>
            </div>
            <?php if ($canManageAccess): ?>
            <div class="admin-access-mode-map" aria-label="Access modes" data-i18n-aria-label="admin.access.modesLabel">
              <div><span class="admin-access-mode-icon"><i class="fa-solid fa-terminal" aria-hidden="true"></i></span><div><strong data-i18n="admin.access.modeApiTitle">Automate Insight</strong><span data-i18n="admin.access.modeApiDescription">Scripts, CI/CD, and backends use API tokens.</span></div><code>API</code></div>
              <div><span class="admin-access-mode-icon"><i class="fa-solid fa-arrow-right-to-bracket" aria-hidden="true"></i></span><div><strong data-i18n="admin.access.modeProviderTitle">Connect another application</strong><span data-i18n="admin.access.modeProviderDescription">Insight becomes its sign-in provider.</span></div><code>OIDC</code></div>
              <div><span class="admin-access-mode-icon"><i class="fa-solid fa-building-shield" aria-hidden="true"></i></span><div><strong data-i18n="admin.access.modeSsoTitle">Sign in to Insight</strong><span data-i18n="admin.access.modeSsoDescription">Your identity provider protects this dashboard.</span></div><code>SSO</code></div>
            </div>
            <div class="admin-page-feedback" data-access-feedback role="status" hidden></div>
            <div class="admin-access-grid">
              <section class="admin-tool-panel" aria-labelledby="access-api-title">
                <div class="admin-tool-panel-heading"><div><h2 id="access-api-title" data-i18n="admin.access.apiTitle">Control Insight by API</h2><span data-i18n="admin.access.apiHint">For scripts, CI/CD, and backend services.</span></div><div class="admin-access-heading-meta"><span class="admin-status-badge" data-access-feature-status="api" data-status="<?= ($accessState['api_enabled'] ?? false) ? 'operational' : 'unknown' ?>"><span aria-hidden="true"></span><span data-access-feature-label data-i18n="<?= ($accessState['api_enabled'] ?? false) ? 'admin.access.active' : 'admin.access.inactive' ?>"><?= ($accessState['api_enabled'] ?? false) ? 'Actif' : 'Inactif' ?></span></span><i class="fa-solid fa-code" aria-hidden="true"></i></div></div>
                <div class="admin-access-panel-body">
                  <label class="admin-switch"><input type="checkbox" data-access-toggle="api"<?= ($accessState['api_enabled'] ?? false) ? ' checked' : '' ?>><span aria-hidden="true"></span><span><strong data-i18n="admin.access.apiEnabled">Allow API tokens</strong><small data-i18n="admin.access.apiEnabledHint">Disabling this immediately cuts off every token.</small></span></label>
                  <div class="admin-access-endpoint"><span data-i18n="admin.access.baseUrl">Base URL</span><code data-access-api-url><?= insight_admin_escape((string)$accessState['api_base_url']) ?></code><button class="admin-icon-button" type="button" data-copy-value="<?= insight_admin_escape((string)$accessState['api_base_url']) ?>" aria-label="Copier" title="Copier" data-i18n-aria-label="admin.access.copy" data-i18n-title="admin.access.copy"><i class="fa-regular fa-copy" aria-hidden="true"></i></button></div>
                  <div class="admin-access-list-heading"><strong data-i18n="admin.access.tokens">Tokens</strong><button class="admin-secondary-button" type="button" data-access-create-token><i class="fa-solid fa-plus" aria-hidden="true"></i><span data-i18n="admin.access.newToken">New token</span></button></div>
                  <div class="admin-access-list" data-access-token-list></div>
                </div>
              </section>
              <section class="admin-tool-panel" aria-labelledby="access-oauth-title">
                <div class="admin-tool-panel-heading"><div><h2 id="access-oauth-title" data-i18n="admin.access.oauthTitle">Connect another application</h2><span data-i18n="admin.access.oauthHint">Insight authenticates its users with OpenID Connect.</span></div><div class="admin-access-heading-meta"><span class="admin-status-badge" data-access-feature-status="oauth" data-status="<?= ($accessState['oauth_provider_enabled'] ?? false) ? 'operational' : 'unknown' ?>"><span aria-hidden="true"></span><span data-access-feature-label data-i18n="<?= ($accessState['oauth_provider_enabled'] ?? false) ? 'admin.access.active' : 'admin.access.inactive' ?>"><?= ($accessState['oauth_provider_enabled'] ?? false) ? 'Actif' : 'Inactif' ?></span></span><i class="fa-solid fa-link" aria-hidden="true"></i></div></div>
                <div class="admin-access-panel-body">
                  <label class="admin-switch"><input type="checkbox" data-access-toggle="oauth"<?= ($accessState['oauth_provider_enabled'] ?? false) ? ' checked' : '' ?><?= ($accessState['issuer_ready'] ?? false) ? '' : ' disabled' ?>><span aria-hidden="true"></span><span><strong data-i18n="admin.access.oauthEnabled">Allow OIDC connections</strong><small data-i18n="admin.access.oauthEnabledHint">Each application receives its own ID and secret.</small></span></label>
                  <div class="admin-access-endpoint"><span data-i18n="admin.access.discoveryUrl">Discovery</span><code data-access-discovery-url><?= insight_admin_escape((string)$accessState['discovery_url']) ?></code><button class="admin-icon-button" type="button" data-copy-value="<?= insight_admin_escape((string)$accessState['discovery_url']) ?>" aria-label="Copier" title="Copier" data-i18n-aria-label="admin.access.copy" data-i18n-title="admin.access.copy"><i class="fa-regular fa-copy" aria-hidden="true"></i></button></div>
                  <?php if (!($accessState['issuer_ready'] ?? false)): ?><div class="admin-access-warning"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i><span data-i18n="admin.access.publicUrlRequired">Set a public HTTPS URL before enabling it.</span></div><?php endif; ?>
                  <div class="admin-access-list-heading"><strong data-i18n="admin.access.clients">Applications</strong><button class="admin-secondary-button" type="button" data-access-create-client><i class="fa-solid fa-plus" aria-hidden="true"></i><span data-i18n="admin.access.newClient">New dashboard</span></button></div>
                  <div class="admin-access-list" data-access-client-list></div>
                </div>
              </section>
              <section class="admin-tool-panel admin-access-sso-panel" aria-labelledby="access-sso-title">
                <div class="admin-tool-panel-heading"><div><h2 id="access-sso-title" data-i18n="admin.access.ssoTitle">This dashboard’s sign-in</h2><span data-i18n="admin.access.ssoHint">Users sign in with your organization’s identity provider.</span></div><i class="fa-solid fa-building-shield" aria-hidden="true"></i></div>
                <div class="admin-access-panel-body admin-access-sso-body">
                  <div class="admin-access-sso-status">
                    <span class="admin-status-badge" data-status="<?= ($ssoState['enabled'] ?? false) ? (($ssoState['valid'] ?? false) ? 'operational' : 'offline') : 'unknown' ?>"><span aria-hidden="true"></span><span data-i18n="<?= ($ssoState['enabled'] ?? false) ? (($ssoState['valid'] ?? false) ? 'admin.access.configured' : 'admin.access.invalid') : 'admin.access.disabled' ?>"><?= ($ssoState['enabled'] ?? false) ? (($ssoState['valid'] ?? false) ? 'Configured' : 'Invalid configuration') : 'Disabled' ?></span></span>
                    <div><strong><?= insight_admin_escape((string)($ssoState['provider_name'] ?? 'SSO')) ?></strong><span><?= insight_admin_escape((string)($ssoState['issuer'] ?? '')) ?></span></div>
                  </div>
                  <div class="admin-access-endpoint"><span data-i18n="admin.access.callbackUrl">Callback URL</span><code><?= insight_admin_escape((string)$ssoState['callback_url']) ?></code><button class="admin-icon-button" type="button" data-copy-value="<?= insight_admin_escape((string)$ssoState['callback_url']) ?>" aria-label="Copier" title="Copier" data-i18n-aria-label="admin.access.copy" data-i18n-title="admin.access.copy"><i class="fa-regular fa-copy" aria-hidden="true"></i></button></div>
                  <div class="admin-access-config-note"><i class="fa-solid fa-sliders" aria-hidden="true"></i><div><strong data-i18n="admin.access.ssoConfigTitle">Configured in .env</strong><span data-i18n="admin.access.ssoConfigDescription">Issuer, OIDC client, and access rules remain server-side.</span></div></div>
                </div>
              </section>
              <section class="admin-tool-panel admin-access-audit-panel" aria-labelledby="access-audit-title">
                <div class="admin-tool-panel-heading"><div><h2 id="access-audit-title" data-i18n="admin.access.auditTitle">Security activity</h2><span data-i18n="admin.access.auditHint">Latest events for this identity.</span></div><i class="fa-solid fa-shield-halved" aria-hidden="true"></i></div>
                <div class="admin-audit-list">
                  <?php if ($isDevBypass): ?>
                    <div><span><i class="fa-solid fa-unlock" aria-hidden="true"></i><span data-i18n="admin.dev.audit">Access control disabled by local configuration</span></span></div>
                  <?php else: ?>
                    <?php foreach ($auditEvents as $event): ?>
                      <?php $eventTime = insight_dashboard_utc_iso((string)($event['created_at'] ?? '')); ?>
                      <?php $eventKey = match ((string)($event['event'] ?? '')) { 'setup_completed' => 'admin.audit.setup', 'login_succeeded', 'sso_login_succeeded' => 'admin.audit.login', 'logout' => 'admin.audit.logout', default => 'admin.audit.activity' }; ?>
                      <div><span><i class="fa-solid fa-shield" aria-hidden="true"></i><span data-i18n="<?= insight_admin_escape($eventKey) ?>">Local activity</span></span><?php if ($eventTime !== ''): ?><time datetime="<?= insight_admin_escape($eventTime) ?>"></time><?php endif; ?></div>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </div>
              </section>
            </div>
            <?php endif; ?>
          </section>
        </div>
      </div>
    </main>
  </div>
  <dialog class="admin-access-dialog" data-node-dialog aria-labelledby="admin-node-dialog-title">
    <form class="admin-access-form" data-node-form>
      <div class="admin-probe-dialog-heading"><span class="admin-probe-dialog-icon"><i class="fa-solid fa-code-branch" aria-hidden="true"></i></span><div><p class="admin-eyebrow" data-i18n="admin.network.enrollment">Agent enrollment</p><h2 id="admin-node-dialog-title" data-i18n="admin.network.addAgent">Add agent</h2></div><button class="admin-icon-button" type="button" data-node-close aria-label="Close" title="Close" data-i18n-aria-label="common.close" data-i18n-title="common.close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div>
      <p class="admin-security-dialog-copy" data-i18n="admin.network.enrollmentHint">Create the identity here, then deploy the generated configuration on the remote machine.</p>
      <div class="admin-notification-primary-fields"><label class="admin-field"><span data-i18n="admin.network.nodeKey">Stable identifier</span><span class="admin-input-wrap"><i class="fa-solid fa-fingerprint" aria-hidden="true"></i><input type="text" name="node_key" minlength="3" maxlength="64" pattern="[a-z0-9][a-z0-9._-]{2,63}" autocomplete="off" required placeholder="paris-1"></span></label><label class="admin-field"><span data-i18n="admin.network.displayName">Display name</span><span class="admin-input-wrap"><i class="fa-solid fa-tag" aria-hidden="true"></i><input type="text" name="display_name" maxlength="120" autocomplete="off" placeholder="Paris 1"></span></label></div>
      <div class="admin-notification-primary-fields"><label class="admin-field"><span data-i18n="admin.network.region">Region</span><span class="admin-input-wrap"><i class="fa-solid fa-earth-europe" aria-hidden="true"></i><input type="text" name="region" maxlength="64" autocomplete="off" placeholder="fr-par"></span></label><label class="admin-field"><span data-i18n="admin.network.zone">Zone</span><span class="admin-input-wrap"><i class="fa-solid fa-location-dot" aria-hidden="true"></i><input type="text" name="zone" maxlength="64" autocomplete="off" placeholder="fr-par-1"></span></label></div>
      <div class="admin-probe-feedback" data-node-feedback role="alert" hidden></div>
      <div class="admin-probe-form-actions"><button class="admin-secondary-button" type="button" data-node-close data-i18n="admin.probes.cancel">Cancel</button><button class="admin-primary-button" type="submit"><i class="fa-solid fa-key" aria-hidden="true"></i><span data-i18n="admin.network.createCredentials">Create credentials</span></button></div>
    </form>
  </dialog>
  <dialog class="admin-access-dialog admin-node-secret-dialog" data-node-secret-dialog aria-labelledby="admin-node-secret-title">
    <div class="admin-access-secret-content"><div class="admin-probe-dialog-heading"><span class="admin-probe-dialog-icon"><i class="fa-solid fa-key" aria-hidden="true"></i></span><div><p class="admin-eyebrow" data-i18n="admin.network.credentialsCreated">Agent credentials created</p><h2 id="admin-node-secret-title" data-i18n="admin.network.deployAgent">Deploy this agent</h2></div><button class="admin-icon-button" type="button" data-node-secret-close aria-label="Close" title="Close" data-i18n-aria-label="common.close" data-i18n-title="common.close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div><p data-i18n="admin.network.secretOnce">The secret is displayed once. Keep the existing agent volume during updates and outages.</p><div class="admin-node-secret-block"><div><span data-i18n="admin.network.environment">Agent environment</span><button class="admin-icon-button" type="button" data-node-copy="env" aria-label="Copy" title="Copy" data-i18n-aria-label="admin.access.copy" data-i18n-title="admin.access.copy"><i class="fa-regular fa-copy" aria-hidden="true"></i></button></div><pre data-node-secret-env></pre></div><div class="admin-node-secret-block"><div><span data-i18n="admin.network.startCommand">Start command</span><button class="admin-icon-button" type="button" data-node-copy="command" aria-label="Copy" title="Copy" data-i18n-aria-label="admin.access.copy" data-i18n-title="admin.access.copy"><i class="fa-regular fa-copy" aria-hidden="true"></i></button></div><pre data-node-secret-command></pre></div><div class="admin-probe-form-actions"><button class="admin-primary-button" type="button" data-node-secret-close><i class="fa-solid fa-check" aria-hidden="true"></i><span data-i18n="admin.access.done">Done</span></button></div></div>
  </dialog>
  <dialog class="admin-access-dialog" data-security-totp-dialog aria-labelledby="admin-security-totp-title">
    <form class="admin-access-form" data-security-totp-form>
      <div class="admin-probe-dialog-heading"><span class="admin-probe-dialog-icon"><i class="fa-solid fa-shield" aria-hidden="true"></i></span><div><p class="admin-eyebrow" data-i18n="admin.security.twoFactor">Two-factor authentication</p><h2 id="admin-security-totp-title" data-i18n="admin.security.configureTotp">Configure your authenticator</h2></div><button class="admin-icon-button" type="button" data-security-dialog-close aria-label="Close" title="Close" data-i18n-aria-label="common.close" data-i18n-title="common.close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div>
      <p class="admin-security-dialog-copy" data-i18n="admin.security.totpInstructions">Open the link with your authenticator app, or enter the secret manually, then confirm with a generated code.</p>
      <a class="admin-secondary-button admin-security-authenticator-link" href="#" data-security-otpauth><i class="fa-solid fa-mobile-screen-button" aria-hidden="true"></i><span data-i18n="admin.security.openAuthenticator">Open authenticator app</span></a>
      <div class="admin-access-secret-value"><span data-i18n="admin.security.manualSecret">Manual secret</span><code data-security-totp-secret></code><button class="admin-icon-button" type="button" data-security-copy-secret aria-label="Copy" title="Copy" data-i18n-aria-label="admin.access.copy" data-i18n-title="admin.access.copy"><i class="fa-regular fa-copy" aria-hidden="true"></i></button></div>
      <label class="admin-field"><span data-i18n="admin.auth.totpCode">Authentication code</span><span class="admin-input-wrap"><i class="fa-solid fa-key" aria-hidden="true"></i><input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" minlength="6" maxlength="32" required placeholder="123456"></span></label>
      <div class="admin-probe-feedback" data-security-totp-feedback role="alert" hidden></div>
      <div class="admin-probe-form-actions"><button class="admin-secondary-button" type="button" data-security-dialog-close data-i18n="admin.probes.cancel">Cancel</button><button class="admin-primary-button" type="submit"><i class="fa-solid fa-check" aria-hidden="true"></i><span data-i18n="admin.security.confirmTotp">Confirm 2FA</span></button></div>
    </form>
  </dialog>
  <dialog class="admin-access-dialog" data-security-verify-dialog aria-labelledby="admin-security-verify-title">
    <form class="admin-access-form" data-security-verify-form>
      <div class="admin-probe-dialog-heading"><span class="admin-probe-dialog-icon"><i class="fa-solid fa-user-check" aria-hidden="true"></i></span><div><p class="admin-eyebrow" data-i18n="admin.security.identityCheck">Identity check</p><h2 id="admin-security-verify-title" data-security-verify-title></h2></div><button class="admin-icon-button" type="button" data-security-dialog-close aria-label="Close" title="Close" data-i18n-aria-label="common.close" data-i18n-title="common.close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div>
      <p class="admin-security-dialog-copy" data-i18n="admin.security.verifyDescription">Enter a current authentication or recovery code to continue.</p>
      <label class="admin-field"><span data-i18n="admin.auth.totpCode">Authentication code</span><span class="admin-input-wrap"><i class="fa-solid fa-key" aria-hidden="true"></i><input type="text" name="code" autocomplete="one-time-code" minlength="6" maxlength="32" required></span></label>
      <div class="admin-probe-feedback" data-security-verify-feedback role="alert" hidden></div>
      <div class="admin-probe-form-actions"><button class="admin-secondary-button" type="button" data-security-dialog-close data-i18n="admin.probes.cancel">Cancel</button><button class="admin-primary-button" type="submit"><i class="fa-solid fa-check" aria-hidden="true"></i><span data-i18n="admin.security.continue">Continue</span></button></div>
    </form>
  </dialog>
  <dialog class="admin-access-dialog" data-security-recovery-dialog aria-labelledby="admin-security-recovery-title">
    <div class="admin-access-secret-content"><div class="admin-probe-dialog-heading"><span class="admin-probe-dialog-icon"><i class="fa-solid fa-key" aria-hidden="true"></i></span><div><p class="admin-eyebrow" data-i18n="admin.security.recoveryEyebrow">Emergency access</p><h2 id="admin-security-recovery-title" data-i18n="admin.security.recoveryTitle">Store your recovery codes</h2></div><button class="admin-icon-button" type="button" data-security-dialog-close aria-label="Close" title="Close" data-i18n-aria-label="common.close" data-i18n-title="common.close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div><p data-i18n="admin.security.recoveryOnce">Each code can be used once. They will not be displayed again.</p><div class="admin-security-recovery-codes" data-security-recovery-codes></div><div class="admin-probe-form-actions"><button class="admin-secondary-button" type="button" data-security-copy-recovery><i class="fa-regular fa-copy" aria-hidden="true"></i><span data-i18n="admin.security.copyCodes">Copy codes</span></button><button class="admin-primary-button" type="button" data-security-dialog-close><i class="fa-solid fa-check" aria-hidden="true"></i><span data-i18n="admin.access.done">Done</span></button></div></div>
  </dialog>
  <dialog class="admin-access-dialog" data-security-password-dialog aria-labelledby="admin-security-password-title">
    <form class="admin-access-form" data-security-password-form>
      <div class="admin-probe-dialog-heading"><span class="admin-probe-dialog-icon"><i class="fa-solid fa-lock" aria-hidden="true"></i></span><div><p class="admin-eyebrow" data-i18n="admin.security.localAccount">Local account</p><h2 id="admin-security-password-title" data-i18n="admin.security.changePassword">Change password</h2></div><button class="admin-icon-button" type="button" data-security-dialog-close aria-label="Close" title="Close" data-i18n-aria-label="common.close" data-i18n-title="common.close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div>
      <label class="admin-field"><span data-i18n="admin.security.currentPassword">Current password</span><span class="admin-input-wrap"><i class="fa-solid fa-key" aria-hidden="true"></i><input type="password" name="current_password" autocomplete="current-password" required></span></label>
      <label class="admin-field"><span data-i18n="admin.security.newPassword">New password</span><span class="admin-input-wrap"><i class="fa-solid fa-lock" aria-hidden="true"></i><input type="password" name="password" autocomplete="new-password" minlength="12" required></span></label>
      <label class="admin-field"><span data-i18n="admin.auth.passwordConfirmation">Confirm password</span><span class="admin-input-wrap"><i class="fa-solid fa-check" aria-hidden="true"></i><input type="password" name="password_confirmation" autocomplete="new-password" minlength="12" required></span></label>
      <div class="admin-probe-feedback" data-security-password-feedback role="alert" hidden></div>
      <div class="admin-probe-form-actions"><button class="admin-secondary-button" type="button" data-security-dialog-close data-i18n="admin.probes.cancel">Cancel</button><button class="admin-primary-button" type="submit"><i class="fa-solid fa-check" aria-hidden="true"></i><span data-i18n="admin.security.savePassword">Save password</span></button></div>
    </form>
  </dialog>
  <dialog class="admin-access-dialog" data-security-user-dialog aria-labelledby="admin-security-user-title">
    <form class="admin-access-form" data-security-user-form>
      <div class="admin-probe-dialog-heading"><span class="admin-probe-dialog-icon"><i class="fa-solid fa-user-plus" aria-hidden="true"></i></span><div><p class="admin-eyebrow" data-i18n="admin.security.usersTitle">Local users</p><h2 id="admin-security-user-title" data-i18n="admin.security.addUser">Add user</h2></div><button class="admin-icon-button" type="button" data-security-dialog-close aria-label="Close" title="Close" data-i18n-aria-label="common.close" data-i18n-title="common.close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div>
      <label class="admin-field"><span data-i18n="admin.auth.username">Username</span><span class="admin-input-wrap"><i class="fa-solid fa-user" aria-hidden="true"></i><input type="text" name="username" autocomplete="off" minlength="3" maxlength="64" required></span></label>
      <label class="admin-field"><span data-i18n="admin.security.role">Role</span><span class="admin-select-wrap"><i class="fa-solid fa-user-tag" aria-hidden="true"></i><select name="role"><option value="viewer" data-i18n="admin.security.role.viewer">Viewer</option><option value="operator" data-i18n="admin.security.role.operator">Operator</option><option value="admin" data-i18n="admin.security.role.admin">Administrator</option></select></span></label>
      <label class="admin-field"><span data-i18n="admin.auth.password">Password</span><span class="admin-input-wrap"><i class="fa-solid fa-lock" aria-hidden="true"></i><input type="password" name="password" autocomplete="new-password" minlength="12" required></span></label>
      <label class="admin-field"><span data-i18n="admin.auth.passwordConfirmation">Confirm password</span><span class="admin-input-wrap"><i class="fa-solid fa-check" aria-hidden="true"></i><input type="password" name="password_confirmation" autocomplete="new-password" minlength="12" required></span></label>
      <div class="admin-probe-feedback" data-security-user-feedback role="alert" hidden></div>
      <div class="admin-probe-form-actions"><button class="admin-secondary-button" type="button" data-security-dialog-close data-i18n="admin.probes.cancel">Cancel</button><button class="admin-primary-button" type="submit"><i class="fa-solid fa-user-plus" aria-hidden="true"></i><span data-i18n="admin.security.createUser">Create user</span></button></div>
    </form>
  </dialog>
  <dialog class="admin-access-dialog" data-access-token-dialog aria-labelledby="admin-access-token-title">
    <form class="admin-access-form" data-access-token-form>
      <div class="admin-probe-dialog-heading"><span class="admin-probe-dialog-icon"><i class="fa-solid fa-key" aria-hidden="true"></i></span><div><p class="admin-eyebrow" data-i18n="admin.access.apiTitle">Control Insight by API</p><h2 id="admin-access-token-title" data-i18n="admin.access.createTokenTitle">Create token</h2></div><button class="admin-icon-button" type="button" data-access-dialog-close aria-label="Close" title="Close" data-i18n-aria-label="common.close" data-i18n-title="common.close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div>
      <label class="admin-field"><span data-i18n="admin.access.name">Name</span><span class="admin-input-wrap"><i class="fa-solid fa-tag" aria-hidden="true"></i><input type="text" name="name" minlength="2" maxlength="120" required autocomplete="off" placeholder="Production automation" data-i18n-placeholder="admin.access.tokenNamePlaceholder"></span></label>
      <label class="admin-field"><span data-i18n="admin.access.expiry">Expiration</span><span class="admin-select-wrap"><i class="fa-regular fa-clock" aria-hidden="true"></i><select name="expires_in_days"><option value="30" data-i18n="admin.access.days30">30 days</option><option value="90" selected data-i18n="admin.access.days90">90 days</option><option value="365" data-i18n="admin.access.year1">1 year</option><option value="0" data-i18n="admin.access.never">Never</option></select></span></label>
      <fieldset class="admin-access-scopes"><legend data-i18n="admin.access.permissions">Permissions</legend><?php foreach (insight_access_scope_catalog() as $scope => $label): ?><label><input type="checkbox" name="scopes" value="<?= insight_admin_escape($scope) ?>"<?= in_array($scope, ['status:read', 'monitors:read', 'incidents:read'], true) ? ' checked' : '' ?>><span><code><?= insight_admin_escape($scope) ?></code><small data-i18n="<?= insight_admin_escape(insight_access_scope_i18n_key($scope)) ?>"><?= insight_admin_escape($label) ?></small></span></label><?php endforeach; ?></fieldset>
      <div class="admin-probe-feedback" data-access-token-feedback role="alert" hidden></div>
      <div class="admin-probe-form-actions"><button class="admin-secondary-button" type="button" data-access-dialog-close data-i18n="admin.probes.cancel">Cancel</button><button class="admin-primary-button" type="submit"><i class="fa-solid fa-plus" aria-hidden="true"></i><span data-i18n="admin.access.create">Create</span></button></div>
    </form>
  </dialog>
  <dialog class="admin-access-dialog" data-access-client-dialog aria-labelledby="admin-access-client-title">
    <form class="admin-access-form" data-access-client-form>
      <div class="admin-probe-dialog-heading"><span class="admin-probe-dialog-icon"><i class="fa-solid fa-link" aria-hidden="true"></i></span><div><p class="admin-eyebrow">OpenID Connect</p><h2 id="admin-access-client-title" data-i18n="admin.access.createClientTitle">Connect a dashboard</h2></div><button class="admin-icon-button" type="button" data-access-dialog-close aria-label="Close" title="Close" data-i18n-aria-label="common.close" data-i18n-title="common.close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div>
      <label class="admin-field"><span data-i18n="admin.access.name">Name</span><span class="admin-input-wrap"><i class="fa-solid fa-tag" aria-hidden="true"></i><input type="text" name="name" minlength="2" maxlength="120" required autocomplete="off" placeholder="Operations dashboard" data-i18n-placeholder="admin.access.clientNamePlaceholder"></span></label>
      <label class="admin-field"><span data-i18n="admin.access.redirectUris">Exact redirect URIs</span><textarea class="admin-textarea admin-code-textarea" name="redirect_uris" rows="3" maxlength="10000" required placeholder="https://dashboard.example.com/auth/callback"></textarea></label>
      <fieldset class="admin-access-scopes"><legend data-i18n="admin.access.permissions">Permissions</legend><?php foreach (insight_access_oauth_scope_catalog() as $scope => $label): ?><label><input type="checkbox" name="scopes" value="<?= insight_admin_escape($scope) ?>"<?= in_array($scope, ['openid', 'profile'], true) ? ' checked' : '' ?><?= $scope === 'openid' ? ' disabled' : '' ?>><span><code><?= insight_admin_escape($scope) ?></code><small data-i18n="<?= insight_admin_escape(insight_access_scope_i18n_key($scope)) ?>"><?= insight_admin_escape($label) ?></small></span></label><?php endforeach; ?></fieldset>
      <div class="admin-probe-feedback" data-access-client-feedback role="alert" hidden></div>
      <div class="admin-probe-form-actions"><button class="admin-secondary-button" type="button" data-access-dialog-close data-i18n="admin.probes.cancel">Cancel</button><button class="admin-primary-button" type="submit"><i class="fa-solid fa-link" aria-hidden="true"></i><span data-i18n="admin.access.create">Create</span></button></div>
    </form>
  </dialog>
  <dialog class="admin-access-dialog" data-access-secret-dialog aria-labelledby="admin-access-secret-title">
    <div class="admin-access-secret-content"><div class="admin-probe-dialog-heading"><span class="admin-probe-dialog-icon"><i class="fa-solid fa-key" aria-hidden="true"></i></span><div><p class="admin-eyebrow" data-i18n="admin.access.secretEyebrow">Secret created</p><h2 id="admin-access-secret-title" data-i18n="admin.access.secretTitle">Store these credentials</h2></div><button class="admin-icon-button" type="button" data-access-secret-close aria-label="Close" title="Close" data-i18n-aria-label="common.close" data-i18n-title="common.close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div><p data-i18n="admin.access.secretOnce">They will not be displayed again after closing.</p><div class="admin-access-secret-values" data-access-secret-values></div><div class="admin-probe-form-actions"><button class="admin-primary-button" type="button" data-access-secret-close><i class="fa-solid fa-check" aria-hidden="true"></i><span data-i18n="admin.access.done">Done</span></button></div></div>
  </dialog>
  <dialog class="admin-probe-dialog admin-workflow-dialog" data-incident-details-dialog aria-labelledby="admin-incident-details-title">
    <form class="admin-probe-form" data-incident-details-form>
      <div class="admin-probe-dialog-heading"><span class="admin-probe-dialog-icon"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i></span><div><p class="admin-eyebrow" data-i18n="admin.incidents.eyebrow">Events</p><h2 id="admin-incident-details-title" data-incident-details-title data-i18n="admin.incidents.create">New incident</h2></div><button class="admin-icon-button" type="button" data-incident-details-close aria-label="Close" title="Close" data-i18n-aria-label="common.close" data-i18n-title="common.close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div>
      <div class="admin-notification-primary-fields"><label class="admin-field"><span data-i18n="admin.incidents.fieldTitle">Title</span><span class="admin-input-wrap"><i class="fa-solid fa-heading" aria-hidden="true"></i><input type="text" name="title" maxlength="200" required></span></label><label class="admin-field"><span data-i18n="admin.incidents.severityLabel">Severity</span><span class="admin-select-wrap"><i class="fa-solid fa-gauge-high" aria-hidden="true"></i><select name="severity"><option value="info" data-i18n="admin.incidents.severity.info">Info</option><option value="minor" data-i18n="admin.incidents.severity.minor">Minor</option><option value="major" selected data-i18n="admin.incidents.severity.major">Major</option><option value="critical" data-i18n="admin.incidents.severity.critical">Critical</option></select></span></label></div>
      <label class="admin-field"><span data-i18n="admin.incidents.summaryLabel">Public summary</span><textarea class="admin-textarea" name="summary" rows="5" maxlength="20000"></textarea></label>
      <div class="admin-notification-primary-fields"><label class="admin-field"><span data-i18n="admin.incidents.runbook">Runbook</span><span class="admin-select-wrap"><i class="fa-solid fa-book" aria-hidden="true"></i><select name="runbook_id"><option value="0" data-i18n="admin.incidents.noRunbook">No runbook</option><?php foreach ($runbooks as $runbook): ?><option value="<?= (int)$runbook['id'] ?>"><?= insight_admin_escape((string)$runbook['name']) ?></option><?php endforeach; ?></select></span></label><label class="admin-field"><span data-i18n="admin.incidents.metadata">Internal metadata (JSON)</span><textarea class="admin-textarea admin-code-textarea" name="metadata" rows="3" maxlength="20000" placeholder='{"ticket":"OPS-42"}'></textarea></label></div>
      <fieldset class="admin-workflow-targets"><legend data-i18n="admin.common.affectedMonitors">Affected monitors</legend><div class="admin-workflow-target-grid"><?php foreach (array_merge($monitors, $servers) as $target): ?><label><input type="checkbox" name="site_ids" value="<?= (int)$target['id'] ?>"><span><i class="fa-solid <?= in_array(strtolower((string)($target['probe_type'] ?? 'http')), ['icmp', 'tcp', 'snmp', 'service'], true) ? 'fa-server' : 'fa-heart-pulse' ?>" aria-hidden="true"></i><?= insight_admin_escape(insight_dashboard_host((string)$target['url'])) ?></span></label><?php endforeach; ?></div></fieldset>
      <label class="admin-switch"><input type="checkbox" name="published" checked><span aria-hidden="true"></span><span><strong data-i18n="admin.incidents.published">Visible on status pages</strong><small data-i18n="admin.incidents.publishedHint">Public updates are shown immediately.</small></span></label>
      <div class="admin-probe-feedback" data-incident-details-feedback role="alert" hidden></div><div class="admin-probe-form-actions"><button class="admin-secondary-button" type="button" data-incident-details-close data-i18n="admin.probes.cancel">Cancel</button><button class="admin-primary-button" type="submit"><i class="fa-solid fa-check" aria-hidden="true"></i><span data-incident-details-submit data-i18n="admin.incidents.create">New incident</span></button></div>
    </form>
  </dialog>
  <dialog class="admin-probe-dialog admin-workflow-dialog" data-incident-update-dialog aria-labelledby="admin-incident-update-title">
    <form class="admin-probe-form" data-incident-update-form><div class="admin-probe-dialog-heading"><span class="admin-probe-dialog-icon"><i class="fa-solid fa-message" aria-hidden="true"></i></span><div><p class="admin-eyebrow" data-incident-update-target></p><h2 id="admin-incident-update-title" data-incident-update-title data-i18n="admin.incidents.addUpdate">Publish update</h2></div><button class="admin-icon-button" type="button" data-incident-update-close aria-label="Close" title="Close" data-i18n-aria-label="common.close" data-i18n-title="common.close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div><label class="admin-field"><span data-i18n="admin.incidents.updateMessage">Message</span><textarea class="admin-textarea" name="message" rows="6" maxlength="20000" required></textarea></label><label class="admin-switch"><input type="checkbox" name="published" checked><span aria-hidden="true"></span><span><strong data-i18n="admin.incidents.publicUpdate">Public update</strong><small data-i18n="admin.incidents.publicUpdateHint">Subscribers will be notified when delivery is enabled.</small></span></label><div class="admin-probe-feedback" data-incident-update-feedback role="alert" hidden></div><div class="admin-probe-form-actions"><button class="admin-secondary-button" type="button" data-incident-update-close data-i18n="admin.probes.cancel">Cancel</button><button class="admin-primary-button" type="submit"><i class="fa-solid fa-paper-plane" aria-hidden="true"></i><span data-i18n="admin.incidents.publishUpdate">Publish</span></button></div></form>
  </dialog>
  <dialog class="admin-probe-dialog admin-workflow-dialog" data-incident-comment-dialog aria-labelledby="admin-incident-comment-title"><form class="admin-probe-form" data-incident-comment-form enctype="multipart/form-data"><div class="admin-probe-dialog-heading"><span class="admin-probe-dialog-icon"><i class="fa-regular fa-comment" aria-hidden="true"></i></span><div><p class="admin-eyebrow" data-incident-comment-target></p><h2 id="admin-incident-comment-title" data-i18n="admin.incidents.comment">Internal comment</h2></div><button class="admin-icon-button" type="button" data-incident-comment-close aria-label="Close" title="Close" data-i18n-aria-label="common.close" data-i18n-title="common.close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div><label class="admin-field"><span data-i18n="admin.incidents.commentBody">Comment</span><textarea class="admin-textarea" name="body" rows="6" maxlength="20000" required></textarea></label><label class="admin-field"><span data-i18n="admin.incidents.attachment">Attachment</span><span class="admin-input-wrap"><i class="fa-solid fa-paperclip" aria-hidden="true"></i><input type="file" name="attachment" accept="image/png,image/jpeg,image/webp,application/pdf,text/plain,application/json"></span><span class="admin-field-hint" data-i18n="admin.incidents.attachmentHint">PNG, JPEG, WebP, PDF, text, or JSON up to 5 MB.</span></label><div class="admin-probe-feedback" data-incident-comment-feedback role="alert" hidden></div><div class="admin-probe-form-actions"><button class="admin-secondary-button" type="button" data-incident-comment-close data-i18n="admin.probes.cancel">Cancel</button><button class="admin-primary-button" type="submit"><i class="fa-solid fa-check" aria-hidden="true"></i><span data-i18n="admin.incidents.saveComment">Save comment</span></button></div></form></dialog>
  <dialog class="admin-probe-dialog admin-workflow-dialog" data-runbook-dialog aria-labelledby="admin-runbook-title"><form class="admin-probe-form" data-runbook-form><div class="admin-probe-dialog-heading"><span class="admin-probe-dialog-icon"><i class="fa-solid fa-book" aria-hidden="true"></i></span><div><p class="admin-eyebrow" data-i18n="admin.incidents.runbooks">Runbooks</p><h2 id="admin-runbook-title" data-runbook-title data-i18n="admin.incidents.newRunbook">New runbook</h2></div><button class="admin-icon-button" type="button" data-runbook-close aria-label="Close" title="Close" data-i18n-aria-label="common.close" data-i18n-title="common.close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div><div class="admin-notification-primary-fields"><label class="admin-field"><span data-i18n="admin.incidents.runbookName">Name</span><span class="admin-input-wrap"><i class="fa-solid fa-heading" aria-hidden="true"></i><input type="text" name="name" maxlength="160" required></span></label><label class="admin-field"><span data-i18n="admin.incidents.runbookSlug">Slug</span><span class="admin-input-wrap"><i class="fa-solid fa-link" aria-hidden="true"></i><input type="text" name="slug" maxlength="120" pattern="[a-z0-9]+(?:-[a-z0-9]+)*"></span></label></div><label class="admin-field"><span data-i18n="admin.incidents.runbookContent">Procedure</span><textarea class="admin-textarea admin-code-textarea" name="content" rows="10" maxlength="100000"></textarea></label><label class="admin-switch"><input type="checkbox" name="enabled" checked><span aria-hidden="true"></span><span><strong data-i18n="common.enabled">Enabled</strong><small data-i18n="admin.incidents.runbookEnabledHint">Disabled runbooks remain attached to existing incidents.</small></span></label><div class="admin-probe-feedback" data-runbook-feedback role="alert" hidden></div><div class="admin-probe-form-actions"><button class="admin-secondary-button" type="button" data-runbook-close data-i18n="admin.probes.cancel">Cancel</button><button class="admin-primary-button" type="submit"><i class="fa-solid fa-check" aria-hidden="true"></i><span data-runbook-submit data-i18n="admin.incidents.saveRunbook">Save runbook</span></button></div></form></dialog>
  <dialog class="admin-probe-dialog admin-workflow-dialog" data-maintenance-dialog aria-labelledby="admin-maintenance-dialog-title">
    <form class="admin-probe-form" data-maintenance-form><div class="admin-probe-dialog-heading"><span class="admin-probe-dialog-icon"><i class="fa-solid fa-calendar-check" aria-hidden="true"></i></span><div><p class="admin-eyebrow" data-i18n="admin.maintenance.eyebrow">Schedule</p><h2 id="admin-maintenance-dialog-title" data-maintenance-dialog-title data-i18n="admin.maintenance.create">Plan maintenance</h2></div><button class="admin-icon-button" type="button" data-maintenance-close aria-label="Close" title="Close" data-i18n-aria-label="common.close" data-i18n-title="common.close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div><label class="admin-field"><span data-i18n="admin.maintenance.fieldTitle">Title</span><span class="admin-input-wrap"><i class="fa-solid fa-heading" aria-hidden="true"></i><input type="text" name="title" maxlength="160" required></span></label><label class="admin-field"><span data-i18n="admin.maintenance.descriptionLabel">Description</span><textarea class="admin-textarea" name="description" rows="4" maxlength="20000"></textarea></label><div class="admin-workflow-date-grid"><label class="admin-field"><span data-i18n="admin.maintenance.startsAt">Starts at</span><span class="admin-input-wrap"><i class="fa-regular fa-calendar" aria-hidden="true"></i><input type="datetime-local" name="starts_at" required></span></label><label class="admin-field"><span data-i18n="admin.maintenance.endsAt">Ends at</span><span class="admin-input-wrap"><i class="fa-regular fa-calendar-check" aria-hidden="true"></i><input type="datetime-local" name="ends_at" required></span></label></div><div class="admin-notification-primary-fields"><label class="admin-field"><span data-i18n="admin.common.timezone">Timezone</span><span class="admin-input-wrap"><i class="fa-solid fa-globe" aria-hidden="true"></i><input type="text" name="timezone" maxlength="64" value="<?= insight_admin_escape((string)($insightAdminConfig['timezone'] ?? 'Europe/Paris')) ?>" required></span></label><label class="admin-field"><span data-i18n="admin.maintenance.statusLabel">Status</span><span class="admin-select-wrap"><i class="fa-solid fa-list-check" aria-hidden="true"></i><select name="status"><option value="planned" data-i18n="admin.maintenance.status.planned">Planned</option><option value="cancelled" data-i18n="admin.maintenance.status.cancelled">Cancelled</option><option value="completed" data-i18n="admin.maintenance.status.completed">Completed</option></select></span></label></div><div class="admin-workflow-date-grid"><label class="admin-field"><span data-i18n="admin.maintenance.recurrence">Recurrence</span><span class="admin-select-wrap"><i class="fa-solid fa-repeat" aria-hidden="true"></i><select name="recurrence"><option value="none" data-i18n="admin.maintenance.recurrence.none">None</option><option value="daily" data-i18n="admin.maintenance.recurrence.daily">Daily</option><option value="weekly" data-i18n="admin.maintenance.recurrence.weekly">Weekly</option><option value="monthly" data-i18n="admin.maintenance.recurrence.monthly">Monthly</option></select></span></label><label class="admin-field"><span data-i18n="admin.maintenance.recurrenceInterval">Every</span><span class="admin-input-wrap"><i class="fa-solid fa-hashtag" aria-hidden="true"></i><input type="number" name="recurrence_interval" min="1" max="52" value="1"></span></label><label class="admin-field"><span data-i18n="admin.maintenance.recurrenceUntil">Repeat until</span><span class="admin-input-wrap"><i class="fa-regular fa-calendar-xmark" aria-hidden="true"></i><input type="datetime-local" name="recurrence_until"></span></label></div><fieldset class="admin-workflow-targets"><legend data-i18n="admin.common.affectedMonitors">Affected monitors</legend><p data-i18n="admin.maintenance.allWhenEmpty">Leave empty to target every monitor.</p><div class="admin-workflow-target-grid"><?php foreach (array_merge($monitors, $servers) as $target): ?><label><input type="checkbox" name="site_ids" value="<?= (int)$target['id'] ?>"><span><?= insight_admin_escape(insight_dashboard_host((string)$target['url'])) ?></span></label><?php endforeach; ?></div></fieldset><label class="admin-switch"><input type="checkbox" name="notify_public" checked><span aria-hidden="true"></span><span><strong data-i18n="admin.maintenance.notifyPublic">Publish this maintenance</strong><small data-i18n="admin.maintenance.notifyPublicHint">It appears on affected status pages.</small></span></label><div class="admin-probe-feedback" data-maintenance-feedback role="alert" hidden></div><div class="admin-probe-form-actions"><button class="admin-secondary-button" type="button" data-maintenance-close data-i18n="admin.probes.cancel">Cancel</button><button class="admin-primary-button" type="submit"><i class="fa-solid fa-check" aria-hidden="true"></i><span data-maintenance-submit data-i18n="admin.maintenance.create">Plan maintenance</span></button></div></form>
  </dialog>
  <dialog class="admin-probe-dialog admin-status-page-dialog" data-status-page-dialog aria-labelledby="admin-status-page-dialog-title">
    <form class="admin-probe-form" data-status-page-form><div class="admin-probe-dialog-heading"><span class="admin-probe-dialog-icon"><i class="fa-solid fa-window-maximize" aria-hidden="true"></i></span><div><p class="admin-eyebrow" data-i18n="admin.statusPages.eyebrow">Publishing</p><h2 id="admin-status-page-dialog-title" data-status-page-dialog-title data-i18n="admin.statusPages.create">New page</h2></div><button class="admin-icon-button" type="button" data-status-page-close aria-label="Close" title="Close" data-i18n-aria-label="common.close" data-i18n-title="common.close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div><div class="admin-notification-primary-fields"><label class="admin-field"><span data-i18n="admin.statusPages.name">Name</span><span class="admin-input-wrap"><i class="fa-solid fa-heading" aria-hidden="true"></i><input type="text" name="name" maxlength="160" required></span></label><label class="admin-field"><span data-i18n="admin.statusPages.slug">Slug</span><span class="admin-input-wrap"><i class="fa-solid fa-link" aria-hidden="true"></i><input type="text" name="slug" maxlength="120" pattern="[a-z0-9]+(?:-[a-z0-9]+)*" required></span></label></div><label class="admin-field"><span data-i18n="admin.statusPages.descriptionLabel">Description</span><textarea class="admin-textarea" name="description" rows="3" maxlength="20000"></textarea></label><div class="admin-notification-primary-fields"><label class="admin-field"><span data-i18n="admin.statusPages.domain">Custom domain</span><span class="admin-input-wrap"><i class="fa-solid fa-globe" aria-hidden="true"></i><input type="text" name="custom_domain" maxlength="255" placeholder="status.example.com"></span></label><label class="admin-field"><span data-i18n="admin.statusPages.visibility">Visibility</span><span class="admin-select-wrap"><i class="fa-solid fa-eye" aria-hidden="true"></i><select name="visibility"><option value="public" data-i18n="admin.statusPages.public">Public</option><option value="private" data-i18n="admin.statusPages.private">Private</option></select></span></label></div><label class="admin-field" data-status-page-password-field hidden><span data-i18n="admin.statusPages.password">Page password</span><span class="admin-input-wrap"><i class="fa-solid fa-key" aria-hidden="true"></i><input type="password" name="password" minlength="12" maxlength="1024" autocomplete="new-password"></span><span class="admin-field-hint" data-i18n="admin.statusPages.passwordHint">Leave empty while editing to keep the current password.</span></label><div class="admin-workflow-date-grid"><label class="admin-field"><span data-i18n="admin.statusPages.theme">Theme</span><span class="admin-select-wrap"><i class="fa-solid fa-palette" aria-hidden="true"></i><select name="theme"><option value="system" data-i18n="theme.system">System</option><option value="light" data-i18n="theme.light">Light</option><option value="dark" data-i18n="theme.dark">Dark</option></select></span></label><label class="admin-field"><span data-i18n="admin.statusPages.locale">Language</span><span class="admin-select-wrap"><i class="fa-solid fa-language" aria-hidden="true"></i><select name="locale"><option value="auto" data-i18n="common.auto">Auto</option><option value="en">English</option><option value="fr" data-i18n="language.french">French</option></select></span></label><label class="admin-field"><span data-i18n="admin.statusPages.accent">Accent</span><span class="admin-color-input"><input type="color" name="accent_color" value="#16a34a"><input type="text" name="accent_text" value="#16a34a" pattern="#[a-fA-F0-9]{6}" maxlength="7"></span></label></div><fieldset class="admin-workflow-targets"><legend data-i18n="admin.statusPages.monitors">Ungrouped monitors</legend><div class="admin-workflow-target-grid"><?php foreach (array_merge($monitors, $servers) as $target): ?><label><input type="checkbox" name="site_ids" value="<?= (int)$target['id'] ?>"><span><?= insight_admin_escape(insight_dashboard_host((string)$target['url'])) ?></span></label><?php endforeach; ?></div></fieldset><section class="admin-status-page-groups"><div class="admin-access-list-heading"><strong data-i18n="admin.statusPages.groups">Monitor groups</strong><button class="admin-secondary-button" type="button" data-status-page-add-group><i class="fa-solid fa-plus" aria-hidden="true"></i><span data-i18n="admin.statusPages.addGroup">Add group</span></button></div><div data-status-page-groups></div></section><label class="admin-switch"><input type="checkbox" name="enabled" checked><span aria-hidden="true"></span><span><strong data-i18n="admin.statusPages.enabled">Enabled</strong><small data-i18n="admin.statusPages.enabledHint">Disabled pages cannot be opened.</small></span></label><div class="admin-probe-feedback" data-status-page-feedback role="alert" hidden></div><div class="admin-probe-form-actions"><button class="admin-secondary-button" type="button" data-status-page-close data-i18n="admin.probes.cancel">Cancel</button><button class="admin-primary-button" type="submit"><i class="fa-solid fa-check" aria-hidden="true"></i><span data-status-page-submit data-i18n="admin.statusPages.create">New page</span></button></div></form>
  </dialog>
  <dialog class="admin-confirm-dialog" data-workflow-delete-dialog aria-labelledby="admin-workflow-delete-title"><div class="admin-confirm-content"><span class="admin-confirm-icon"><i class="fa-regular fa-trash-can" aria-hidden="true"></i></span><div><p class="admin-eyebrow" data-i18n="admin.probes.deleteEyebrow">Deletion</p><h2 id="admin-workflow-delete-title" data-i18n="admin.common.confirmDelete">Delete this item?</h2><p data-i18n="admin.common.deleteIrreversible">This action cannot be undone.</p><strong data-workflow-delete-target></strong></div><div class="admin-probe-feedback" data-workflow-delete-feedback role="alert" hidden></div><div class="admin-probe-form-actions"><button class="admin-secondary-button" type="button" data-workflow-delete-close data-i18n="admin.probes.cancel">Cancel</button><button class="admin-primary-button is-destructive" type="button" data-workflow-delete-confirm><i class="fa-regular fa-trash-can" aria-hidden="true"></i><span data-i18n="admin.notifications.confirmDelete">Delete</span></button></div></div></dialog>
  <dialog class="admin-probe-dialog admin-diagnostic-dialog" data-probe-diagnostic-dialog aria-labelledby="admin-probe-diagnostic-title">
    <div class="admin-probe-form"><div class="admin-probe-dialog-heading"><span class="admin-probe-dialog-icon"><i class="fa-solid fa-stethoscope" aria-hidden="true"></i></span><div><p class="admin-eyebrow" data-i18n="admin.probes.diagnostics">Private diagnostics</p><h2 id="admin-probe-diagnostic-title" data-i18n="admin.probes.diagnosticTitle">Latest failure diagnostic</h2><span class="admin-dialog-target" data-probe-diagnostic-target></span></div><button class="admin-icon-button" type="button" data-probe-diagnostic-close aria-label="Close" title="Close" data-i18n-aria-label="common.close" data-i18n-title="common.close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div><div class="admin-probe-feedback" data-probe-diagnostic-feedback role="alert" hidden></div><dl class="admin-diagnostic-summary" data-probe-diagnostic-summary></dl><pre class="admin-diagnostic-output" data-probe-diagnostic-output></pre><a class="admin-secondary-button" data-probe-diagnostic-artifact href="#" target="_blank" rel="noopener" hidden><i class="fa-regular fa-image" aria-hidden="true"></i><span data-i18n="admin.probes.openScreenshot">Open screenshot</span></a></div>
  </dialog>
  <dialog class="admin-probe-dialog admin-monitor-dialog" data-probe-dialog aria-labelledby="admin-probe-dialog-title">
    <form class="admin-probe-form" data-probe-form>
      <div class="admin-probe-dialog-heading">
        <span class="admin-probe-dialog-icon"><i class="fa-solid fa-heart-pulse" aria-hidden="true" data-probe-dialog-icon></i></span>
        <div><p class="admin-eyebrow" data-i18n="admin.probes.eyebrow">Monitor</p><h2 id="admin-probe-dialog-title" data-probe-dialog-title>Add a monitor</h2></div>
        <button class="admin-icon-button" type="button" data-probe-close aria-label="Close" title="Close" data-i18n-aria-label="common.close" data-i18n-title="common.close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
      </div>
      <div class="admin-probe-type-field" data-probe-type-field>
        <span data-i18n="admin.probes.type">Type</span>
        <span class="admin-select-wrap"><i class="fa-solid fa-globe" aria-hidden="true" data-probe-type-icon></i><select name="probe_type">
          <option value="http" data-i18n="admin.probes.typeHttp">Website (HTTP)</option>
          <option value="browser" data-i18n="admin.probes.typeBrowser">Browser journey</option>
          <option value="websocket" data-i18n="admin.probes.typeWebsocket">WebSocket</option>
          <option value="icmp" data-i18n="admin.probes.typeIcmp">Ping (ICMP)</option>
          <option value="tcp" data-i18n="admin.probes.typeTcp">Port (TCP)</option>
          <option value="dns" data-i18n="admin.probes.typeDns">Domain name (DNS)</option>
          <option value="heartbeat" data-i18n="admin.probes.typeHeartbeat">Scheduled job (Heartbeat)</option>
          <option value="mqtt" data-i18n="admin.probes.typeMqtt">MQTT</option>
          <option value="sql" data-i18n="admin.probes.typeSql">Database (SQL)</option>
          <option value="docker" data-i18n="admin.probes.typeDocker">Docker container</option>
          <option value="grpc" data-i18n="admin.probes.typeGrpc">gRPC service</option>
          <option value="redis" data-i18n="admin.probes.typeRedis">Redis</option>
          <option value="smtp" data-i18n="admin.probes.typeSmtp">Email server (SMTP)</option>
          <option value="rabbitmq" data-i18n="admin.probes.typeRabbitMq">RabbitMQ</option>
          <option value="snmp" data-i18n="admin.probes.typeSnmp">Network device (SNMP)</option>
          <option value="service" data-i18n="admin.probes.typeService">Local service (agent)</option>
        </select></span>
      </div>
      <label class="admin-field"><span data-i18n="admin.probes.name">Name</span><span class="admin-input-wrap"><i class="fa-solid fa-tag" aria-hidden="true"></i><input type="text" name="name" maxlength="160" autocomplete="off" placeholder="Checkout API" data-i18n-placeholder="admin.probes.namePlaceholder"></span></label>
      <label class="admin-field">
        <span data-probe-target-label data-i18n="admin.probes.url">Address</span>
        <span class="admin-input-wrap"><i class="fa-solid fa-link" aria-hidden="true" data-probe-target-icon></i><input type="text" name="target" required maxlength="255" autocomplete="off" spellcheck="false" data-probe-target></span>
        <span class="admin-field-hint" data-probe-target-hint></span>
      </label>
      <div class="admin-probe-primary-setting">
        <label class="admin-field"><span data-i18n="admin.probes.interval">Frequency</span><span class="admin-select-wrap"><i class="fa-regular fa-clock" aria-hidden="true"></i><select name="interval_sec"><option value="10" data-i18n="admin.probes.everyTenSeconds">Every 10 seconds</option><option value="20" data-i18n="admin.probes.everyTwentySeconds">Every 20 seconds</option><option value="30" data-i18n="admin.probes.everyThirtySeconds">Every 30 seconds</option><option value="60" selected data-i18n="admin.probes.everyMinute">Every minute</option><option value="120" data-i18n="admin.probes.everyTwoMinutes">Every 2 minutes</option><option value="180" data-i18n="admin.probes.everyThreeMinutes">Every 3 minutes</option><option value="300" data-i18n="admin.probes.everyFiveMinutes">Every 5 minutes</option><option value="600" data-i18n="admin.probes.everyTenMinutes">Every 10 minutes</option><option value="1800" data-i18n="admin.probes.everyThirtyMinutes">Every 30 minutes</option><option value="21600" data-i18n="admin.probes.everySixHours">Every 6 hours</option><option value="43200" data-i18n="admin.probes.everyTwelveHours">Every 12 hours</option><option value="86400" data-i18n="admin.probes.everyDay">Every day</option></select></span></label>
      </div>
      <details class="admin-probe-disclosure" data-probe-disclosure="reliability">
        <summary><span class="admin-probe-disclosure-icon"><i class="fa-solid fa-shield" aria-hidden="true"></i></span><span class="admin-probe-disclosure-copy"><strong data-i18n="admin.probes.reliability">Reliability</strong><small data-i18n="admin.probes.reliabilityHint">Timeouts and outage confirmation</small></span><i class="fa-solid fa-chevron-down admin-probe-disclosure-chevron" aria-hidden="true"></i></summary>
        <div class="admin-probe-disclosure-content"><div class="admin-notification-field-grid"><label class="admin-field"><span data-i18n="admin.probes.timeout">Timeout (seconds)</span><span class="admin-input-wrap"><i class="fa-solid fa-stopwatch" aria-hidden="true"></i><input type="number" name="timeout_sec" min="1" max="120" value="10" required></span></label><label class="admin-field"><span data-i18n="admin.probes.retries">Retries</span><span class="admin-input-wrap"><i class="fa-solid fa-rotate" aria-hidden="true"></i><input type="number" name="retry_count" min="0" max="10" value="2" required></span></label><label class="admin-field"><span data-i18n="admin.probes.failureThreshold">Failures required</span><span class="admin-input-wrap"><i class="fa-solid fa-arrow-trend-down" aria-hidden="true"></i><input type="number" name="failure_threshold" min="1" max="20" value="2" required></span></label><label class="admin-field"><span data-i18n="admin.probes.recoveryThreshold">Successes required</span><span class="admin-input-wrap"><i class="fa-solid fa-arrow-trend-up" aria-hidden="true"></i><input type="number" name="recovery_threshold" min="1" max="20" value="2" required></span></label></div></div>
      </details>
      <details class="admin-probe-disclosure" data-probe-disclosure="availability">
        <summary><span class="admin-probe-disclosure-icon"><i class="fa-solid fa-chart-line" aria-hidden="true"></i></span><span class="admin-probe-disclosure-copy"><strong data-i18n="admin.probes.availabilitySettings">Time when the site works</strong><small data-i18n="admin.probes.availabilitySettingsHint">How Insight counts replies</small></span><i class="fa-solid fa-chevron-down admin-probe-disclosure-chevron" aria-hidden="true"></i></summary>
        <div class="admin-probe-disclosure-content"><div class="admin-notification-field-grid"><label class="admin-field"><span data-i18n="admin.probes.sloTarget">Availability target (%)</span><span class="admin-input-wrap"><i class="fa-solid fa-bullseye" aria-hidden="true"></i><input type="number" name="slo_target_percent" min="0" max="100" step="0.001" value="99.9" required></span></label><div class="admin-calculation-default"><i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i><div><strong data-i18n="admin.probes.calculationDefaultTitle">The simple choice: leave Automatic on</strong><span data-i18n="admin.probes.calculationDefaultDescription">Think of Insight as a small robot. Every minute, it asks the site if it is okay. If there is no reply for two minutes, it says it does not know. Those minutes count as neither good nor bad.</span></div></div></div><input type="hidden" name="calc_method" value="inherit"><label class="admin-switch"><input type="checkbox" name="strict_availability" data-strict-availability><span aria-hidden="true"></span><span><strong data-i18n="admin.probes.strictAvailability">Count a missing reply as downtime</strong><small data-i18n="admin.probes.strictAvailabilityHint">Leave this off unless the monitoring itself must never miss a minute.</small></span></label></div>
      </details>
      <details class="admin-probe-disclosure admin-probe-dynamic-fields" data-probe-fields="http">
        <summary><span class="admin-probe-disclosure-icon"><i class="fa-solid fa-code" aria-hidden="true"></i></span><span class="admin-probe-disclosure-copy"><strong data-i18n="admin.probes.httpChecks">HTTP options</strong><small data-i18n="admin.probes.httpChecksHint">Request, content, sign-in, and certificate</small></span><i class="fa-solid fa-chevron-down admin-probe-disclosure-chevron" aria-hidden="true"></i></summary>
        <div class="admin-probe-disclosure-content">
        <div class="admin-notification-field-grid is-three"><label class="admin-field"><span data-i18n="admin.probes.httpMethod">Method</span><span class="admin-select-wrap"><i class="fa-solid fa-arrow-right" aria-hidden="true"></i><select name="http_method"><?php foreach (['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'] as $method): ?><option value="<?= $method ?>"><?= $method ?></option><?php endforeach; ?></select></span></label><label class="admin-field"><span data-i18n="admin.probes.redirects">Redirects</span><span class="admin-select-wrap"><i class="fa-solid fa-share" aria-hidden="true"></i><select name="http_redirect"><option value="follow" data-i18n="admin.probes.followRedirects">Follow</option><option value="no_follow" data-i18n="admin.probes.blockRedirects">Do not follow</option></select></span></label><label class="admin-field"><span data-i18n="admin.probes.acceptedStatusCodes">Accepted status codes</span><span class="admin-input-wrap"><i class="fa-solid fa-list-ol" aria-hidden="true"></i><input type="text" name="accepted_status_codes" value="200-399" maxlength="255" required></span></label></div>
        <div class="admin-notification-field-grid"><label class="admin-field"><span data-i18n="admin.probes.keywordMode">Body keyword</span><span class="admin-select-wrap"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i><select name="keyword_mode"><option value="none" data-i18n="admin.probes.assertionNone">No assertion</option><option value="contains" data-i18n="admin.probes.assertionContains">Must contain</option><option value="absent" data-i18n="admin.probes.assertionAbsent">Must not contain</option></select></span></label><label class="admin-field"><span data-i18n="admin.probes.keywordText">Keyword</span><span class="admin-input-wrap"><i class="fa-solid fa-font" aria-hidden="true"></i><input type="text" name="keyword_text" maxlength="20000"></span></label></div>
        <div class="admin-notification-field-grid"><label class="admin-field"><span data-i18n="admin.probes.jsonPath">JSON path</span><span class="admin-input-wrap"><i class="fa-solid fa-code-branch" aria-hidden="true"></i><input type="text" name="json_path" maxlength="500" placeholder="$.status"></span></label><label class="admin-field"><span data-i18n="admin.probes.jsonExpected">Expected value</span><span class="admin-input-wrap"><i class="fa-solid fa-equals" aria-hidden="true"></i><input type="text" name="json_expected_value" maxlength="20000" placeholder="ok"></span></label></div>
        <label class="admin-field"><span data-i18n="admin.probes.requestHeaders">Request headers (JSON)</span><textarea class="admin-textarea admin-code-textarea" name="request_headers_json" rows="3" maxlength="20000" placeholder='{"Authorization":"Bearer …"}'></textarea></label>
        <label class="admin-field"><span data-i18n="admin.probes.requestBody">Request body</span><textarea class="admin-textarea admin-code-textarea" name="request_body" rows="4" maxlength="65535"></textarea></label>
        <div class="admin-notification-field-grid"><label class="admin-field"><span data-i18n="admin.probes.basicAuthUsername">Username</span><span class="admin-input-wrap"><i class="fa-solid fa-user" aria-hidden="true"></i><input type="text" name="basic_auth_username" maxlength="255" autocomplete="off"></span></label><label class="admin-field"><span data-i18n="admin.probes.basicAuthPassword">Password</span><span class="admin-input-wrap"><i class="fa-solid fa-key" aria-hidden="true"></i><input type="password" name="basic_auth_password" maxlength="2000" autocomplete="new-password" data-probe-secret></span></label></div>
        <div class="admin-notification-field-grid"><label class="admin-switch"><input type="checkbox" name="tls_verify" checked><span aria-hidden="true"></span><span><strong data-i18n="admin.probes.verifyTls">Verify TLS certificate</strong><small data-i18n="admin.probes.verifyTlsHint">Invalid certificates make the check fail.</small></span></label><label class="admin-field"><span data-i18n="admin.probes.tlsExpiry">TLS expiry warning</span><span class="admin-input-wrap"><i class="fa-solid fa-certificate" aria-hidden="true"></i><input type="number" name="tls_expiry_threshold_days" min="1" max="365" value="14" required></span></label></div>
        </div>
      </details>
      <details class="admin-probe-disclosure admin-probe-dynamic-fields" data-probe-fields="browser" hidden><summary><span class="admin-probe-disclosure-icon"><i class="fa-solid fa-window-maximize" aria-hidden="true"></i></span><span class="admin-probe-disclosure-copy"><strong data-i18n="admin.probes.browserChecks">Browser steps</strong><small data-i18n="admin.probes.browserChecksHint">Actions, protected data, and screenshots</small></span><i class="fa-solid fa-chevron-down admin-probe-disclosure-chevron" aria-hidden="true"></i></summary><div class="admin-probe-disclosure-content"><label class="admin-field"><span data-i18n="admin.probes.browserScenario">Steps (JSON)</span><textarea class="admin-textarea admin-code-textarea" name="browser_script" rows="8" maxlength="65535" placeholder='[{"action":"goto"},{"action":"expect_text","selector":"body","value":"Ready"}]'></textarea></label><label class="admin-field"><span data-i18n="admin.probes.browserVariables">Protected data (JSON)</span><textarea class="admin-textarea admin-code-textarea" name="browser_variables_json" rows="3" maxlength="20000" placeholder='{"LOGIN_EMAIL":"user@example.com"}' data-probe-secret-area></textarea><span class="admin-field-hint" data-i18n="admin.probes.secretConfiguredHint">Leave empty while editing to keep saved secrets.</span></label><label class="admin-switch"><input type="checkbox" name="capture_success_screenshot"><span aria-hidden="true"></span><span><strong data-i18n="admin.probes.captureSuccess">Save successful screenshots</strong><small data-i18n="admin.probes.captureSuccessHint">Failure screenshots are always saved.</small></span></label></div></details>
      <details class="admin-probe-disclosure admin-probe-dynamic-fields" data-probe-fields="websocket" hidden><summary><span class="admin-probe-disclosure-icon"><i class="fa-solid fa-arrows-left-right" aria-hidden="true"></i></span><span class="admin-probe-disclosure-copy"><strong>WebSocket</strong><small data-i18n="admin.probes.websocketChecksHint">Headers and messages</small></span><i class="fa-solid fa-chevron-down admin-probe-disclosure-chevron" aria-hidden="true"></i></summary><div class="admin-probe-disclosure-content"><label class="admin-field"><span data-i18n="admin.probes.websocketHeaders">Connection headers (JSON)</span><textarea class="admin-textarea admin-code-textarea" name="websocket_headers_json" rows="3" maxlength="20000"></textarea><span class="admin-field-hint" data-i18n="admin.probes.secretConfiguredHint">Leave empty while editing to keep saved secrets.</span></label><div class="admin-notification-field-grid"><label class="admin-field"><span data-i18n="admin.probes.websocketSend">Message to send</span><textarea class="admin-textarea admin-code-textarea" name="websocket_send" rows="3" maxlength="20000"></textarea></label><label class="admin-field"><span data-i18n="admin.probes.expectedContent">Expected reply</span><textarea class="admin-textarea admin-code-textarea" name="websocket_expect" rows="3" maxlength="20000"></textarea></label></div></div></details>
      <details class="admin-probe-disclosure admin-probe-dynamic-fields" data-probe-fields="mqtt" hidden><summary><span class="admin-probe-disclosure-icon"><i class="fa-solid fa-tower-broadcast" aria-hidden="true"></i></span><span class="admin-probe-disclosure-copy"><strong>MQTT</strong><small data-i18n="admin.probes.mqttChecksHint">Sign-in and expected message</small></span><i class="fa-solid fa-chevron-down admin-probe-disclosure-chevron" aria-hidden="true"></i></summary><div class="admin-probe-disclosure-content"><div class="admin-notification-field-grid is-three"><label class="admin-field"><span data-i18n="admin.probes.username">Username</span><span class="admin-input-wrap"><i class="fa-solid fa-user" aria-hidden="true"></i><input type="text" name="mqtt_username" maxlength="255" autocomplete="off"></span></label><label class="admin-field"><span data-i18n="admin.probes.password">Password</span><span class="admin-input-wrap"><i class="fa-solid fa-key" aria-hidden="true"></i><input type="password" name="mqtt_password" maxlength="2000" autocomplete="new-password"></span></label><label class="admin-field"><span>QoS</span><span class="admin-select-wrap"><i class="fa-solid fa-signal" aria-hidden="true"></i><select name="mqtt_qos"><option value="0">0</option><option value="1">1</option><option value="2">2</option></select></span></label></div><label class="admin-field"><span data-i18n="admin.probes.expectedContent">Expected message</span><span class="admin-input-wrap"><i class="fa-solid fa-equals" aria-hidden="true"></i><input type="text" name="mqtt_expect" maxlength="20000"></span></label></div></details>
      <details class="admin-probe-disclosure admin-probe-dynamic-fields" data-probe-fields="sql" hidden><summary><span class="admin-probe-disclosure-icon"><i class="fa-solid fa-database" aria-hidden="true"></i></span><span class="admin-probe-disclosure-copy"><strong data-i18n="admin.probes.sqlChecks">SQL query</strong><small data-i18n="admin.probes.sqlChecksHint">Sign-in and read-only query</small></span><i class="fa-solid fa-chevron-down admin-probe-disclosure-chevron" aria-hidden="true"></i></summary><div class="admin-probe-disclosure-content"><div class="admin-notification-field-grid"><label class="admin-field"><span data-i18n="admin.probes.username">Username</span><span class="admin-input-wrap"><i class="fa-solid fa-user" aria-hidden="true"></i><input type="text" name="sql_username" maxlength="255" autocomplete="off"></span></label><label class="admin-field"><span data-i18n="admin.probes.password">Password</span><span class="admin-input-wrap"><i class="fa-solid fa-key" aria-hidden="true"></i><input type="password" name="sql_password" maxlength="2000" autocomplete="new-password"></span></label></div><label class="admin-field"><span data-i18n="admin.probes.sqlQuery">Read-only query</span><textarea class="admin-textarea admin-code-textarea" name="sql_query" rows="4" maxlength="20000">SELECT 1</textarea></label><label class="admin-field"><span data-i18n="admin.probes.expectedValue">Expected value</span><span class="admin-input-wrap"><i class="fa-solid fa-equals" aria-hidden="true"></i><input type="text" name="sql_expect" maxlength="20000"></span></label></div></details>
      <details class="admin-probe-disclosure admin-probe-dynamic-fields" data-probe-fields="grpc" hidden><summary><span class="admin-probe-disclosure-icon"><i class="fa-solid fa-tower-cell" aria-hidden="true"></i></span><span class="admin-probe-disclosure-copy"><strong data-i18n="admin.probes.grpcChecks">gRPC health</strong><small data-i18n="admin.probes.grpcChecksHint">Checks the standard health endpoint</small></span><i class="fa-solid fa-chevron-down admin-probe-disclosure-chevron" aria-hidden="true"></i></summary><div class="admin-probe-disclosure-content"><label class="admin-field"><span data-i18n="admin.probes.grpcService">Service name</span><span class="admin-input-wrap"><i class="fa-solid fa-puzzle-piece" aria-hidden="true"></i><input type="text" name="grpc_service" maxlength="255" autocomplete="off"></span><span class="admin-field-hint" data-i18n="admin.probes.grpcServiceHint">Leave empty for the server-wide health check.</span></label></div></details>
      <details class="admin-probe-disclosure admin-probe-dynamic-fields" data-probe-fields="redis" hidden><summary><span class="admin-probe-disclosure-icon"><i class="fa-solid fa-database" aria-hidden="true"></i></span><span class="admin-probe-disclosure-copy"><strong data-i18n="admin.probes.redisChecks">Redis connection</strong><small data-i18n="admin.probes.redisChecksHint">Authenticates, then sends PING</small></span><i class="fa-solid fa-chevron-down admin-probe-disclosure-chevron" aria-hidden="true"></i></summary><div class="admin-probe-disclosure-content"><div class="admin-notification-field-grid"><label class="admin-field"><span data-i18n="admin.probes.username">Username</span><span class="admin-input-wrap"><i class="fa-solid fa-user" aria-hidden="true"></i><input type="text" name="redis_username" maxlength="255" autocomplete="off"></span></label><label class="admin-field"><span data-i18n="admin.probes.password">Password</span><span class="admin-input-wrap"><i class="fa-solid fa-key" aria-hidden="true"></i><input type="password" name="redis_password" maxlength="2000" autocomplete="new-password"></span></label></div></div></details>
      <details class="admin-probe-disclosure admin-probe-dynamic-fields" data-probe-fields="smtp" hidden><summary><span class="admin-probe-disclosure-icon"><i class="fa-solid fa-envelope" aria-hidden="true"></i></span><span class="admin-probe-disclosure-copy"><strong data-i18n="admin.probes.smtpChecks">SMTP connection</strong><small data-i18n="admin.probes.smtpChecksHint">Connects, negotiates TLS, and verifies the server</small></span><i class="fa-solid fa-chevron-down admin-probe-disclosure-chevron" aria-hidden="true"></i></summary><div class="admin-probe-disclosure-content"><div class="admin-notification-field-grid is-three"><label class="admin-field"><span data-i18n="admin.probes.username">Username</span><span class="admin-input-wrap"><i class="fa-solid fa-user" aria-hidden="true"></i><input type="text" name="smtp_username" maxlength="255" autocomplete="off"></span></label><label class="admin-field"><span data-i18n="admin.probes.password">Password</span><span class="admin-input-wrap"><i class="fa-solid fa-key" aria-hidden="true"></i><input type="password" name="smtp_password" maxlength="2000" autocomplete="new-password"></span></label><label class="admin-field"><span data-i18n="admin.probes.smtpEncryption">Encryption</span><span class="admin-select-wrap"><i class="fa-solid fa-lock" aria-hidden="true"></i><select name="smtp_encryption"><option value="starttls">STARTTLS</option><option value="ssl">SSL/TLS</option><option value="none" data-i18n="admin.notifications.none">None</option></select></span></label></div></div></details>
      <details class="admin-probe-disclosure admin-probe-dynamic-fields" data-probe-fields="rabbitmq" hidden><summary><span class="admin-probe-disclosure-icon"><i class="fa-solid fa-arrow-right-arrow-left" aria-hidden="true"></i></span><span class="admin-probe-disclosure-copy"><strong data-i18n="admin.probes.rabbitMqChecks">RabbitMQ connection</strong><small data-i18n="admin.probes.rabbitMqChecksHint">Authenticates to the AMQP broker</small></span><i class="fa-solid fa-chevron-down admin-probe-disclosure-chevron" aria-hidden="true"></i></summary><div class="admin-probe-disclosure-content"><div class="admin-notification-field-grid"><label class="admin-field"><span data-i18n="admin.probes.username">Username</span><span class="admin-input-wrap"><i class="fa-solid fa-user" aria-hidden="true"></i><input type="text" name="rabbitmq_username" maxlength="255" autocomplete="off"></span></label><label class="admin-field"><span data-i18n="admin.probes.password">Password</span><span class="admin-input-wrap"><i class="fa-solid fa-key" aria-hidden="true"></i><input type="password" name="rabbitmq_password" maxlength="2000" autocomplete="new-password"></span></label></div></div></details>
      <details class="admin-probe-disclosure admin-probe-dynamic-fields" data-probe-fields="snmp" hidden><summary><span class="admin-probe-disclosure-icon"><i class="fa-solid fa-network-wired" aria-hidden="true"></i></span><span class="admin-probe-disclosure-copy"><strong data-i18n="admin.probes.snmpChecks">SNMP response</strong><small data-i18n="admin.probes.snmpChecksHint">Reads one SNMP v2c value without changing the device</small></span><i class="fa-solid fa-chevron-down admin-probe-disclosure-chevron" aria-hidden="true"></i></summary><div class="admin-probe-disclosure-content"><div class="admin-notification-field-grid"><label class="admin-field"><span data-i18n="admin.probes.snmpCommunity">Community</span><span class="admin-input-wrap"><i class="fa-solid fa-key" aria-hidden="true"></i><input type="password" name="snmp_community" maxlength="255" autocomplete="new-password"></span></label><label class="admin-field"><span data-i18n="admin.probes.snmpOid">OID</span><span class="admin-input-wrap"><i class="fa-solid fa-fingerprint" aria-hidden="true"></i><input type="text" name="snmp_oid" value="1.3.6.1.2.1.1.3.0" maxlength="255" required></span></label></div><label class="admin-field"><span data-i18n="admin.probes.expectedValue">Expected value</span><span class="admin-input-wrap"><i class="fa-solid fa-equals" aria-hidden="true"></i><input type="text" name="snmp_expect" maxlength="500"></span></label></div></details>
      <section class="admin-probe-dynamic-fields admin-probe-dynamic-notice" data-probe-fields="service" hidden><p class="admin-inline-warning"><i class="fa-solid fa-server" aria-hidden="true"></i><span data-i18n="admin.probes.serviceNotice">Runs only from the named agent. Use agent://agent-key/systemd/service.service or agent://agent-key/pm2/application.</span></p></section>
      <section class="admin-probe-dynamic-fields admin-probe-dynamic-notice" data-probe-fields="docker" hidden><p class="admin-inline-warning"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i><span data-i18n="admin.probes.dockerWarning">Local socket access must be enabled first.</span></p></section>
      <details class="admin-probe-disclosure admin-probe-dynamic-fields" data-probe-fields="dns" hidden><summary><span class="admin-probe-disclosure-icon"><i class="fa-solid fa-address-book" aria-hidden="true"></i></span><span class="admin-probe-disclosure-copy"><strong data-i18n="admin.probes.dnsChecks">DNS response</strong><small data-i18n="admin.probes.dnsChecksHint">Record type and expected answer</small></span><i class="fa-solid fa-chevron-down admin-probe-disclosure-chevron" aria-hidden="true"></i></summary><div class="admin-probe-disclosure-content"><div class="admin-notification-field-grid"><label class="admin-field"><span data-i18n="admin.probes.dnsRecordType">Record type</span><span class="admin-select-wrap"><i class="fa-solid fa-list" aria-hidden="true"></i><select name="dns_record_type"><?php foreach (['A', 'AAAA', 'CNAME', 'MX', 'NS', 'TXT'] as $record): ?><option value="<?= $record ?>"><?= $record ?></option><?php endforeach; ?></select></span></label><label class="admin-field"><span data-i18n="admin.probes.dnsExpected">Expected answer</span><span class="admin-input-wrap"><i class="fa-solid fa-equals" aria-hidden="true"></i><input type="text" name="dns_expected_value" maxlength="500"></span></label></div></div></details>
      <details class="admin-probe-disclosure admin-probe-dynamic-fields" data-probe-fields="heartbeat" hidden><summary><span class="admin-probe-disclosure-icon"><i class="fa-solid fa-heart-pulse" aria-hidden="true"></i></span><span class="admin-probe-disclosure-copy"><strong data-i18n="admin.probes.heartbeatChecks">Heartbeat delay</strong><small data-i18n="admin.probes.heartbeatChecksHint">Time before an alert</small></span><i class="fa-solid fa-chevron-down admin-probe-disclosure-chevron" aria-hidden="true"></i></summary><div class="admin-probe-disclosure-content"><label class="admin-field"><span data-i18n="admin.probes.heartbeatGrace">Delay (seconds)</span><span class="admin-input-wrap"><i class="fa-regular fa-clock" aria-hidden="true"></i><input type="number" name="heartbeat_grace_sec" min="10" max="2592000" value="300" required></span><span class="admin-field-hint" data-i18n="admin.probes.heartbeatSecretHint">The ping URL is shown once after creation.</span></label></div></details>
      <details class="admin-probe-disclosure" data-probe-disclosure="visibility">
        <summary><span class="admin-probe-disclosure-icon"><i class="fa-solid fa-eye" aria-hidden="true"></i></span><span class="admin-probe-disclosure-copy"><strong data-i18n="admin.probes.displaySettings">Display and diagnostics</strong><small data-i18n="admin.probes.displaySettingsHint">Visibility and saved error details</small></span><i class="fa-solid fa-chevron-down admin-probe-disclosure-chevron" aria-hidden="true"></i></summary>
        <div class="admin-probe-disclosure-content"><div class="admin-notification-primary-fields"><label class="admin-switch"><input type="checkbox" name="active" checked><span aria-hidden="true"></span><span><strong data-i18n="admin.probes.active">Monitoring on</strong><small data-i18n="admin.probes.activeHint">Turning it off keeps the history.</small></span></label><label class="admin-switch"><input type="checkbox" name="public_visible" checked><span aria-hidden="true"></span><span><strong data-i18n="admin.probes.publicVisible">Show publicly</strong><small data-i18n="admin.probes.publicVisibleHint">Status pages can override this.</small></span></label><label class="admin-switch"><input type="checkbox" name="diagnostics_enabled" checked><span aria-hidden="true"></span><span><strong data-i18n="admin.probes.diagnostics">Save error details</strong><small data-i18n="admin.probes.diagnosticsHint">Useful when a check fails.</small></span></label><label class="admin-switch" data-http-body-capture><input type="checkbox" name="diagnostic_capture_body"><span aria-hidden="true"></span><span><strong data-i18n="admin.probes.captureBody">Save a response sample</strong><small data-i18n="admin.probes.captureBodyHint">Sensitive values are hidden.</small></span></label></div></div>
      </details>
      <div class="admin-probe-feedback" data-probe-feedback role="alert" hidden></div>
      <div class="admin-probe-form-actions">
        <button class="admin-secondary-button" type="button" data-probe-close data-i18n="admin.probes.cancel">Cancel</button>
        <button class="admin-primary-button" type="submit" data-probe-submit><i class="fa-solid fa-plus" aria-hidden="true" data-probe-submit-icon></i><span data-i18n="admin.probes.submit" data-probe-submit-label>Add</span></button>
      </div>
    </form>
  </dialog>
  <dialog class="admin-access-dialog" data-heartbeat-secret-dialog aria-labelledby="admin-heartbeat-secret-title"><div class="admin-access-secret-content"><div class="admin-probe-dialog-heading"><span class="admin-probe-dialog-icon"><i class="fa-solid fa-heart-pulse" aria-hidden="true"></i></span><div><p class="admin-eyebrow" data-i18n="admin.probes.heartbeatEyebrow">Heartbeat created</p><h2 id="admin-heartbeat-secret-title" data-i18n="admin.probes.heartbeatTitle">Store the ping URL</h2></div><button class="admin-icon-button" type="button" data-heartbeat-secret-close aria-label="Close" title="Close" data-i18n-aria-label="common.close" data-i18n-title="common.close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div><p data-i18n="admin.probes.heartbeatOnce">Call this URL from your job. The secret will not be displayed again.</p><div class="admin-access-secret-value"><span data-i18n="admin.probes.heartbeatUrl">Ping URL</span><code data-heartbeat-secret-value></code><button class="admin-icon-button" type="button" data-heartbeat-secret-copy aria-label="Copy" title="Copy" data-i18n-aria-label="admin.access.copy" data-i18n-title="admin.access.copy"><i class="fa-regular fa-copy" aria-hidden="true"></i></button></div><div class="admin-probe-form-actions"><button class="admin-primary-button" type="button" data-heartbeat-secret-close><i class="fa-solid fa-check" aria-hidden="true"></i><span data-i18n="admin.access.done">Done</span></button></div></div></dialog>
  <dialog class="admin-confirm-dialog" data-probe-delete-dialog aria-labelledby="admin-probe-delete-title">
    <div class="admin-confirm-content">
      <span class="admin-confirm-icon"><i class="fa-regular fa-trash-can" aria-hidden="true"></i></span>
      <div><p class="admin-eyebrow" data-i18n="admin.probes.deleteEyebrow">Deletion</p><h2 id="admin-probe-delete-title" data-i18n="admin.probes.deleteTitle">Delete this monitor?</h2><p data-i18n="admin.probes.deleteDescription">Its history, incidents, and statistics will also be deleted.</p><strong data-probe-delete-target></strong></div>
      <div class="admin-probe-feedback" data-probe-delete-feedback role="alert" hidden></div>
      <div class="admin-probe-form-actions">
        <button class="admin-secondary-button" type="button" data-probe-delete-close data-i18n="admin.probes.cancel">Cancel</button>
        <button class="admin-primary-button is-destructive" type="button" data-probe-delete-confirm><i class="fa-regular fa-trash-can" aria-hidden="true"></i><span data-i18n="admin.probes.confirmDelete">Delete</span></button>
      </div>
    </div>
  </dialog>
  <dialog class="admin-probe-dialog admin-postmortem-dialog" data-incident-dialog aria-labelledby="admin-incident-dialog-title">
    <form class="admin-probe-form" data-incident-form>
      <div class="admin-probe-dialog-heading">
        <span class="admin-probe-dialog-icon"><i class="fa-solid fa-file-waveform" aria-hidden="true"></i></span>
        <div><p class="admin-eyebrow" data-i18n="admin.incidents.postmortemEyebrow">Incident report</p><h2 id="admin-incident-dialog-title" data-i18n="admin.incidents.editTitle">Edit postmortem</h2><span class="admin-dialog-target" data-incident-target></span></div>
        <button class="admin-icon-button" type="button" data-incident-dialog-close aria-label="Close" title="Close" data-i18n-aria-label="common.close" data-i18n-title="common.close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
      </div>
      <label class="admin-field"><span data-i18n="admin.incidents.postmortemLabel">Public report</span><textarea class="admin-textarea admin-postmortem-textarea" name="postmortem" rows="9" maxlength="20000" data-incident-postmortem-input></textarea><span class="admin-field-hint" data-i18n="admin.incidents.postmortemHint">Explain what happened, the impact, the cause, and the corrective actions. This text is public.</span></label>
      <div class="admin-probe-feedback" data-incident-feedback role="alert" hidden></div>
      <div class="admin-probe-form-actions admin-postmortem-actions"><button class="admin-secondary-button" type="button" data-incident-clear><i class="fa-solid fa-eraser" aria-hidden="true"></i><span data-i18n="admin.incidents.clearField">Clear field</span></button><button class="admin-secondary-button" type="button" data-incident-dialog-close data-i18n="admin.probes.cancel">Cancel</button><button class="admin-primary-button" type="submit" data-incident-submit><i class="fa-solid fa-check" aria-hidden="true"></i><span data-i18n="admin.incidents.savePostmortem">Save report</span></button></div>
    </form>
  </dialog>
  <dialog class="admin-notification-dialog" data-notification-dialog aria-labelledby="admin-notification-dialog-title">
    <form class="admin-notification-form" data-notification-form>
      <div class="admin-probe-dialog-heading">
        <span class="admin-probe-dialog-icon"><i class="fa-regular fa-bell" aria-hidden="true" data-notification-dialog-icon></i></span>
        <div><p class="admin-eyebrow" data-i18n="admin.notifications.channelEyebrow">Destination</p><h2 id="admin-notification-dialog-title" data-notification-dialog-title data-i18n="admin.notifications.createTitle">Create channel</h2></div>
        <button class="admin-icon-button" type="button" data-notification-close aria-label="Close" title="Close" data-i18n-aria-label="common.close" data-i18n-title="common.close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
      </div>
      <div class="admin-notification-primary-fields">
        <label class="admin-field"><span data-i18n="admin.notifications.name">Name</span><span class="admin-input-wrap"><i class="fa-solid fa-tag" aria-hidden="true"></i><input type="text" name="name" maxlength="120" required autocomplete="off" placeholder="Primary on-call" data-i18n-placeholder="admin.notifications.namePlaceholder"></span></label>
        <label class="admin-field"><span data-i18n="admin.notifications.provider">Service</span><span class="admin-select-wrap"><i class="fa-solid fa-plug" aria-hidden="true"></i><select name="provider" data-notification-provider><?php foreach ($notificationCatalog as $provider): ?><option value="<?= insight_admin_escape((string)$provider['id']) ?>" data-mode="<?= insight_admin_escape((string)$provider['mode']) ?>" data-icon="<?= insight_admin_escape((string)$provider['icon']) ?>"><?= insight_admin_escape((string)$provider['label']) ?></option><?php endforeach; ?></select></span></label>
      </div>
      <div class="admin-notification-config" data-notification-config="smtp">
        <div class="admin-notification-field-grid is-three">
          <label class="admin-field"><span data-i18n="admin.notifications.smtpHost">SMTP server</span><span class="admin-input-wrap"><i class="fa-solid fa-server" aria-hidden="true"></i><input type="text" name="smtp_host" maxlength="255" autocomplete="off" placeholder="smtp.example.com"></span></label>
          <label class="admin-field"><span data-i18n="admin.notifications.smtpPort">Port</span><span class="admin-input-wrap"><i class="fa-solid fa-hashtag" aria-hidden="true"></i><input type="number" name="smtp_port" min="1" max="65535" value="465"></span></label>
          <label class="admin-field"><span data-i18n="admin.notifications.smtpEncryption">Encryption</span><span class="admin-select-wrap"><i class="fa-solid fa-lock" aria-hidden="true"></i><select name="smtp_encryption"><option value="ssl">SSL</option><option value="tls">STARTTLS</option><option value="none" data-i18n="admin.notifications.none">None</option></select></span></label>
        </div>
        <div class="admin-notification-field-grid">
          <label class="admin-field"><span data-i18n="admin.notifications.username">Username</span><span class="admin-input-wrap"><i class="fa-regular fa-user" aria-hidden="true"></i><input type="text" name="smtp_username" maxlength="255" autocomplete="username"></span></label>
          <label class="admin-field"><span data-i18n="admin.notifications.password">Password</span><span class="admin-input-wrap"><i class="fa-solid fa-key" aria-hidden="true"></i><input type="password" name="smtp_password" maxlength="2000" autocomplete="new-password" data-secret-field></span></label>
        </div>
        <div class="admin-notification-field-grid">
          <label class="admin-field"><span data-i18n="admin.notifications.fromEmail">From address</span><span class="admin-input-wrap"><i class="fa-solid fa-at" aria-hidden="true"></i><input type="email" name="smtp_from_email" maxlength="320" autocomplete="email"></span></label>
          <label class="admin-field"><span data-i18n="admin.notifications.fromName">From name</span><span class="admin-input-wrap"><i class="fa-solid fa-signature" aria-hidden="true"></i><input type="text" name="smtp_from_name" maxlength="120" value="<?= insight_admin_escape($appName) ?>"></span></label>
        </div>
        <label class="admin-field"><span data-i18n="admin.notifications.recipients">Recipients</span><textarea class="admin-textarea" name="smtp_to" rows="2" maxlength="2000" placeholder="ops@example.com, admin@example.com"></textarea></label>
      </div>
      <div class="admin-notification-config" data-notification-config="webhook" hidden>
        <div class="admin-notification-field-grid is-endpoint">
          <label class="admin-field"><span data-i18n="admin.notifications.webhookUrl">Webhook URL</span><span class="admin-input-wrap"><i class="fa-solid fa-link" aria-hidden="true"></i><input type="password" name="webhook_url" maxlength="4000" autocomplete="new-password" data-secret-field placeholder="https://hooks.example.com/…"></span></label>
          <label class="admin-field"><span data-i18n="admin.notifications.method">Method</span><span class="admin-select-wrap"><i class="fa-solid fa-arrow-right" aria-hidden="true"></i><select name="webhook_method"><option>POST</option><option>PUT</option><option>PATCH</option></select></span></label>
        </div>
        <label class="admin-field"><span data-i18n="admin.notifications.headers">JSON headers</span><textarea class="admin-textarea" name="webhook_headers" rows="3" maxlength="10000" data-secret-field placeholder='{"Authorization":"Bearer …"}'></textarea></label>
        <label class="admin-field"><span data-i18n="admin.notifications.payload">Custom JSON body</span><textarea class="admin-textarea admin-code-textarea" name="webhook_payload" rows="5" maxlength="20000" placeholder='{"title":"{{ title }}","message":"{{ body }}"}'></textarea></label>
      </div>
      <div class="admin-notification-config" data-notification-config="free_mobile" hidden>
        <div class="admin-notification-field-grid">
          <label class="admin-field"><span data-i18n="admin.notifications.freeUser">Free Mobile username</span><span class="admin-input-wrap"><i class="fa-regular fa-user" aria-hidden="true"></i><input type="text" name="free_user" maxlength="120" autocomplete="off"></span></label>
          <label class="admin-field"><span data-i18n="admin.notifications.freePassword">Identification key</span><span class="admin-input-wrap"><i class="fa-solid fa-key" aria-hidden="true"></i><input type="password" name="free_password" maxlength="2000" autocomplete="new-password" data-secret-field></span></label>
        </div>
      </div>
      <div class="admin-notification-config" data-notification-config="apprise" hidden>
        <label class="admin-field"><span data-i18n="admin.notifications.appriseUrls">Apprise notification URL</span><textarea class="admin-textarea admin-code-textarea" name="apprise_urls" rows="4" maxlength="30000" data-secret-field placeholder="discord://…&#10;ntfys://…"></textarea><span class="admin-field-hint" data-i18n="admin.notifications.appriseHint">One URL per line. All 138+ Apprise services are accepted.</span></label>
      </div>
      <fieldset class="admin-notification-events">
        <legend data-i18n="admin.notifications.triggerOn">Trigger for</legend>
        <?php foreach (insight_notifications_events() as $event): ?><?php $eventIcon = match ($event) { 'monitor_down' => 'fa-arrow-trend-down', 'monitor_up' => 'fa-arrow-trend-up', 'incident_open' => 'fa-triangle-exclamation', 'incident_update' => 'fa-message', 'incident_acknowledged' => 'fa-user-check', 'incident_resolved' => 'fa-circle-check', 'tls_expiring' => 'fa-hourglass-half', 'tls_invalid' => 'fa-certificate', 'maintenance_started' => 'fa-calendar-day', 'maintenance_ended' => 'fa-calendar-check', default => 'fa-bell' }; ?><label><input type="checkbox" name="events" value="<?= insight_admin_escape($event) ?>" checked><span><i class="fa-solid <?= insight_admin_escape($eventIcon) ?>" aria-hidden="true"></i><span data-i18n="admin.notifications.event.<?= insight_admin_escape($event) ?>"><?= insight_admin_escape(str_replace('_', ' ', ucfirst($event))) ?></span></span></label><?php endforeach; ?>
      </fieldset>
      <div class="admin-notification-primary-fields"><label class="admin-field"><span data-i18n="admin.notifications.minimumSeverity">Minimum severity</span><span class="admin-select-wrap"><i class="fa-solid fa-gauge-high" aria-hidden="true"></i><select name="minimum_severity"><option value="info" data-i18n="admin.incidents.severity.info">Information</option><option value="minor" data-i18n="admin.incidents.severity.minor">Minor</option><option value="major" data-i18n="admin.incidents.severity.major">Major</option><option value="critical" data-i18n="admin.incidents.severity.critical">Critical</option></select></span><span class="admin-field-hint" data-i18n="admin.notifications.minimumSeverityHint">Events below this severity are ignored.</span></label><div class="admin-notification-routing-copy"><i class="fa-solid fa-route" aria-hidden="true"></i><div><strong data-i18n="admin.notifications.routingTitle">Monitor routing</strong><span data-i18n="admin.notifications.routingHint">Leave every monitor unchecked to receive events from all monitors.</span></div></div></div>
      <fieldset class="admin-workflow-targets admin-notification-targets"><legend data-i18n="admin.notifications.routedMonitors">Monitors routed to this channel</legend><div class="admin-workflow-target-grid"><?php foreach (array_merge($monitors, $servers) as $target): ?><label><input type="checkbox" name="site_ids" value="<?= (int)$target['id'] ?>"><span><i class="fa-solid <?= in_array(strtolower((string)($target['probe_type'] ?? 'http')), ['icmp', 'tcp', 'snmp', 'service'], true) ? 'fa-server' : 'fa-heart-pulse' ?>" aria-hidden="true"></i><?= insight_admin_escape(insight_dashboard_host((string)$target['url'])) ?></span></label><?php endforeach; ?></div></fieldset>
      <label class="admin-switch"><input type="checkbox" name="enabled" checked><span aria-hidden="true"></span><span><strong data-i18n="admin.notifications.enabled">Active channel</strong><small data-i18n="admin.notifications.enabledHint">Receives the selected alerts.</small></span></label>
      <div class="admin-probe-feedback" data-notification-feedback role="alert" hidden></div>
      <div class="admin-probe-form-actions">
        <button class="admin-secondary-button" type="button" data-notification-close data-i18n="admin.probes.cancel">Cancel</button>
        <button class="admin-primary-button" type="submit" data-notification-submit><i class="fa-solid fa-check" aria-hidden="true"></i><span data-notification-submit-label data-i18n="admin.notifications.create">Create channel</span></button>
      </div>
    </form>
  </dialog>
  <dialog class="admin-confirm-dialog" data-notification-delete-dialog aria-labelledby="admin-notification-delete-title">
    <div class="admin-confirm-content">
      <span class="admin-confirm-icon"><i class="fa-regular fa-trash-can" aria-hidden="true"></i></span>
      <div><p class="admin-eyebrow" data-i18n="admin.notifications.deleteEyebrow">Deletion</p><h2 id="admin-notification-delete-title" data-i18n="admin.notifications.deleteTitle">Delete this channel?</h2><p data-i18n="admin.notifications.deleteDescription">Future events will no longer be sent to this destination.</p><strong data-notification-delete-target></strong></div>
      <div class="admin-probe-feedback" data-notification-delete-feedback role="alert" hidden></div>
      <div class="admin-probe-form-actions"><button class="admin-secondary-button" type="button" data-notification-delete-close data-i18n="admin.probes.cancel">Cancel</button><button class="admin-primary-button is-destructive" type="button" data-notification-delete-confirm><i class="fa-regular fa-trash-can" aria-hidden="true"></i><span data-i18n="admin.notifications.confirmDelete">Delete</span></button></div>
    </div>
  </dialog>
  <dialog class="admin-notification-dialog admin-oncall-dialog" data-oncall-dialog aria-labelledby="admin-oncall-dialog-title">
    <form class="admin-notification-form" data-oncall-form>
      <div class="admin-probe-dialog-heading">
        <span class="admin-probe-dialog-icon"><i class="fa-solid fa-user-clock" aria-hidden="true"></i></span>
        <div><p class="admin-eyebrow" data-i18n="admin.oncall.eyebrow">Escalation</p><h2 id="admin-oncall-dialog-title" data-oncall-dialog-title data-i18n="admin.oncall.createTitle">Create an on-call rotation</h2></div>
        <button class="admin-icon-button" type="button" data-oncall-close aria-label="Close" title="Close" data-i18n-aria-label="common.close" data-i18n-title="common.close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
      </div>
      <div class="admin-notification-primary-fields">
        <label class="admin-field"><span data-i18n="admin.oncall.name">Name</span><span class="admin-input-wrap"><i class="fa-solid fa-tag" aria-hidden="true"></i><input type="text" name="name" maxlength="160" required placeholder="Primary on-call" data-i18n-placeholder="admin.oncall.namePlaceholder"></span></label>
        <label class="admin-field"><span data-i18n="admin.common.timezone">Timezone</span><span class="admin-input-wrap"><i class="fa-solid fa-globe" aria-hidden="true"></i><input type="text" name="timezone" maxlength="64" required value="<?= insight_admin_escape((string)($insightAdminConfig['timezone'] ?? 'Europe/Paris')) ?>"></span></label>
      </div>
      <div class="admin-notification-field-grid is-three">
        <label class="admin-field"><span data-i18n="admin.oncall.delay">First escalation</span><span class="admin-input-wrap"><i class="fa-regular fa-clock" aria-hidden="true"></i><input type="number" name="escalation_delay_minutes" min="0" max="10080" value="5" required></span><span class="admin-field-hint" data-i18n="admin.oncall.minutesHint">Minutes after detection.</span></label>
        <label class="admin-field"><span data-i18n="admin.oncall.repeat">Repeat every</span><span class="admin-input-wrap"><i class="fa-solid fa-repeat" aria-hidden="true"></i><input type="number" name="repeat_interval_minutes" min="1" max="10080" value="15" required></span><span class="admin-field-hint" data-i18n="admin.oncall.minutes">Minutes</span></label>
        <label class="admin-field"><span data-i18n="admin.oncall.maximum">Maximum alerts</span><span class="admin-input-wrap"><i class="fa-solid fa-list-ol" aria-hidden="true"></i><input type="number" name="maximum_repeats" min="1" max="100" value="3" required></span></label>
      </div>
      <label class="admin-field"><span data-i18n="admin.oncall.minimumSeverity">Minimum incident severity</span><span class="admin-select-wrap"><i class="fa-solid fa-gauge-high" aria-hidden="true"></i><select name="minimum_severity"><option value="info" data-i18n="admin.incidents.severity.info">Information</option><option value="minor" data-i18n="admin.incidents.severity.minor">Minor</option><option value="major" selected data-i18n="admin.incidents.severity.major">Major</option><option value="critical" data-i18n="admin.incidents.severity.critical">Critical</option></select></span></label>
      <section class="admin-oncall-members">
        <div class="admin-access-list-heading"><div><strong data-i18n="admin.oncall.shifts">Shifts</strong><span data-i18n="admin.oncall.shiftsHint">The active shift receives each escalation.</span></div><button class="admin-secondary-button" type="button" data-oncall-add-member><i class="fa-solid fa-plus" aria-hidden="true"></i><span data-i18n="admin.oncall.addShift">Add shift</span></button></div>
        <div data-oncall-members></div>
      </section>
      <fieldset class="admin-workflow-targets"><legend data-i18n="admin.oncall.routedMonitors">Routed monitors</legend><p data-i18n="admin.oncall.allWhenEmpty">Leave empty to cover every monitor.</p><div class="admin-workflow-target-grid"><?php foreach (array_merge($monitors, $servers) as $target): ?><label><input type="checkbox" name="site_ids" value="<?= (int)$target['id'] ?>"><span><?= insight_admin_escape(insight_dashboard_host((string)$target['url'])) ?></span></label><?php endforeach; ?></div></fieldset>
      <label class="admin-switch"><input type="checkbox" name="enabled" checked><span aria-hidden="true"></span><span><strong data-i18n="admin.oncall.enabled">Active rotation</strong><small data-i18n="admin.oncall.enabledHint">Only active rotations escalate incidents.</small></span></label>
      <div class="admin-probe-feedback" data-oncall-feedback role="alert" hidden></div>
      <div class="admin-probe-form-actions"><button class="admin-secondary-button" type="button" data-oncall-close data-i18n="admin.probes.cancel">Cancel</button><button class="admin-primary-button" type="submit" data-oncall-submit><i class="fa-solid fa-check" aria-hidden="true"></i><span data-oncall-submit-label data-i18n="admin.oncall.create">New rotation</span></button></div>
    </form>
  </dialog>
  <template data-oncall-member-template>
    <article class="admin-oncall-member" data-oncall-member>
      <div class="admin-oncall-member-heading"><strong data-i18n="admin.oncall.shift">Shift</strong><button class="admin-icon-button is-destructive" type="button" data-oncall-remove-member aria-label="Remove shift" title="Remove shift" data-i18n-aria-label="admin.oncall.removeShift" data-i18n-title="admin.oncall.removeShift"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div>
      <div class="admin-notification-primary-fields"><label class="admin-field"><span data-i18n="admin.oncall.memberName">Person or team</span><span class="admin-input-wrap"><i class="fa-regular fa-user" aria-hidden="true"></i><input type="text" data-oncall-member-name maxlength="140" required></span></label><label class="admin-field"><span data-i18n="admin.oncall.channel">Alert destination</span><span class="admin-select-wrap"><i class="fa-solid fa-bell" aria-hidden="true"></i><select data-oncall-member-channel required><?php foreach ($notificationChannels as $channel): ?><option value="<?= (int)$channel['id'] ?>"><?= insight_admin_escape((string)$channel['name']) ?></option><?php endforeach; ?></select></span></label></div>
      <div class="admin-workflow-date-grid"><label class="admin-field"><span data-i18n="admin.oncall.startsAt">Starts at</span><span class="admin-input-wrap"><i class="fa-regular fa-calendar" aria-hidden="true"></i><input type="datetime-local" data-oncall-member-start required></span></label><label class="admin-field"><span data-i18n="admin.oncall.endsAt">Ends at</span><span class="admin-input-wrap"><i class="fa-regular fa-calendar-check" aria-hidden="true"></i><input type="datetime-local" data-oncall-member-end required></span></label><label class="admin-field"><span data-i18n="admin.oncall.recurrence">Recurrence</span><span class="admin-select-wrap"><i class="fa-solid fa-repeat" aria-hidden="true"></i><select data-oncall-member-recurrence><option value="none" data-i18n="admin.maintenance.recurrence.none">None</option><option value="daily" data-i18n="admin.maintenance.recurrence.daily">Daily</option><option value="weekly" selected data-i18n="admin.maintenance.recurrence.weekly">Weekly</option></select></span></label></div>
    </article>
  </template>
  <dialog class="admin-confirm-dialog" data-oncall-delete-dialog aria-labelledby="admin-oncall-delete-title"><div class="admin-confirm-content"><span class="admin-confirm-icon"><i class="fa-regular fa-trash-can" aria-hidden="true"></i></span><div><p class="admin-eyebrow" data-i18n="admin.oncall.deleteEyebrow">Deletion</p><h2 id="admin-oncall-delete-title" data-i18n="admin.oncall.deleteTitle">Delete this rotation?</h2><p data-i18n="admin.oncall.deleteDescription">Future incidents will no longer use this escalation path.</p><strong data-oncall-delete-target></strong></div><div class="admin-probe-feedback" data-oncall-delete-feedback role="alert" hidden></div><div class="admin-probe-form-actions"><button class="admin-secondary-button" type="button" data-oncall-delete-close data-i18n="admin.probes.cancel">Cancel</button><button class="admin-primary-button is-destructive" type="button" data-oncall-delete-confirm><i class="fa-regular fa-trash-can" aria-hidden="true"></i><span data-i18n="admin.oncall.confirmDelete">Delete</span></button></div></div></dialog>
<?php insight_admin_page_end(); ?>
