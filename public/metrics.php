<?php

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_OFF);

$config = require __DIR__ . '/config/config.php';

function insight_metrics_env_bool(string $name, bool $default = false): bool
{
    $value = getenv($name);
    if ($value === false || trim((string)$value) === '') {
        return $default;
    }
    return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
}

function insight_metrics_label(mixed $value): string
{
    return str_replace(["\\", "\n", '"'], ["\\\\", '\\n', '\\"'], (string)$value);
}

function insight_metrics_number(mixed $value): string
{
    if (!is_numeric($value)) {
        return '0';
    }
    $number = (float)$value;
    if (!is_finite($number)) {
        return '0';
    }
    return rtrim(rtrim(number_format($number, 6, '.', ''), '0'), '.') ?: '0';
}

function insight_metrics_rows(mysqli $connection, string $query): array
{
    $result = $connection->query($query);
    if (!$result instanceof mysqli_result) {
        throw new RuntimeException('Lecture des métriques impossible.');
    }
    $rows = $result->fetch_all(MYSQLI_ASSOC);
    $result->free();
    return $rows;
}

if (!insight_metrics_env_bool('INSIGHT_METRICS_ENABLED')) {
    http_response_code(404);
    exit;
}

$requiredToken = trim((string)(getenv('INSIGHT_METRICS_TOKEN') ?: ''));
if ($requiredToken !== '') {
    $authorization = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
    $providedToken = preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches) === 1
        ? trim((string)$matches[1])
        : '';
    if ($providedToken === '' || !hash_equals($requiredToken, $providedToken)) {
        header('WWW-Authenticate: Bearer realm="Insight metrics"');
        http_response_code(401);
        exit;
    }
}

header('Content-Type: text/plain; version=0.0.4; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

$connection = mysqli_init();
if (!$connection instanceof mysqli) {
    http_response_code(503);
    echo "insight_metrics_available 0\n";
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
    echo "insight_metrics_available 0\n";
    exit;
}

try {
    $connection->set_charset('utf8mb4');
    $nodeTtl = max(30, min(86400, (int)(getenv('INSIGHT_AGENT_NODE_TTL_SEC') ?: 180)));
    $nodes = insight_metrics_rows($connection, "
        SELECT
            node_key,
            display_name,
            COALESCE(region, '') AS region,
            COALESCE(zone, '') AS zone,
            status,
            connectivity_status,
            clock_skew_ms,
            TIMESTAMPDIFF(SECOND, last_seen_at, CURRENT_TIMESTAMP(3)) AS last_seen_seconds
        FROM monitoring_nodes
        ORDER BY node_key
    ");
    $observations = insight_metrics_rows($connection, "
        SELECT
            s.id AS site_id,
            s.url,
            n.node_key,
            COALESCE(n.region, '') AS region,
            COALESCE(n.zone, '') AS zone,
            o.status,
            o.response_time_ms,
            o.http_code
        FROM monitoring_observations o
        INNER JOIN sites s ON s.id = o.site_id
        INNER JOIN monitoring_nodes n ON n.id = o.node_id
        WHERE o.id = (
            SELECT latest.id
            FROM monitoring_observations latest
            WHERE latest.site_id = o.site_id AND latest.node_id = o.node_id
            ORDER BY latest.observed_at DESC, latest.id DESC
            LIMIT 1
        )
        ORDER BY s.id, n.node_key
    ");
    $consensus = insight_metrics_rows($connection, "
        SELECT s.id AS site_id, s.url, c.*
        FROM monitoring_consensus_current c
        INNER JOIN sites s ON s.id = c.site_id
        ORDER BY s.id
    ");

    echo "# HELP insight_metrics_available Disponibilité de l’exporteur Insight.\n";
    echo "# TYPE insight_metrics_available gauge\n";
    echo "insight_metrics_available 1\n";
    echo "# HELP insight_agent_up Présence récente d’un agent actif.\n";
    echo "# TYPE insight_agent_up gauge\n";
    echo "# HELP insight_agent_connectivity État de la connectivité locale déclarée par l’agent.\n";
    echo "# TYPE insight_agent_connectivity gauge\n";
    echo "# HELP insight_agent_clock_skew_seconds Décalage d’horloge observé entre l’agent et le hub.\n";
    echo "# TYPE insight_agent_clock_skew_seconds gauge\n";
    foreach ($nodes as $node) {
        $labels = 'node="' . insight_metrics_label($node['node_key'])
            . '",name="' . insight_metrics_label($node['display_name'])
            . '",region="' . insight_metrics_label($node['region'])
            . '",zone="' . insight_metrics_label($node['zone']) . '"';
        $fresh = (int)($node['last_seen_seconds'] ?? PHP_INT_MAX) <= $nodeTtl;
        $up = $node['status'] === 'active' && $fresh ? 1 : 0;
        $connectivity = $node['connectivity_status'] === 'online' ? 1 : 0;
        echo "insight_agent_up{{$labels}} {$up}\n";
        echo "insight_agent_connectivity{{$labels}} {$connectivity}\n";
        echo 'insight_agent_clock_skew_seconds{' . $labels . '} ' . insight_metrics_number(((float)$node['clock_skew_ms']) / 1000) . "\n";
    }

    echo "# HELP insight_probe_success Résultat de la dernière sonde brute par agent.\n";
    echo "# TYPE insight_probe_success gauge\n";
    echo "# HELP insight_probe_duration_seconds Durée de la dernière sonde brute.\n";
    echo "# TYPE insight_probe_duration_seconds gauge\n";
    echo "# HELP insight_probe_http_status_code Code HTTP observé par la dernière sonde brute.\n";
    echo "# TYPE insight_probe_http_status_code gauge\n";
    foreach ($observations as $observation) {
        $labels = 'site_id="' . (int)$observation['site_id']
            . '",site="' . insight_metrics_label($observation['url'])
            . '",node="' . insight_metrics_label($observation['node_key'])
            . '",region="' . insight_metrics_label($observation['region'])
            . '",zone="' . insight_metrics_label($observation['zone']) . '"';
        $success = in_array($observation['status'], ['online', 'degraded'], true) ? 1 : 0;
        echo "insight_probe_success{{$labels}} {$success}\n";
        if (is_numeric($observation['response_time_ms'])) {
            echo 'insight_probe_duration_seconds{' . $labels . '} ' . insight_metrics_number(((float)$observation['response_time_ms']) / 1000) . "\n";
        }
        if (is_numeric($observation['http_code'])) {
            echo 'insight_probe_http_status_code{' . $labels . '} ' . (int)$observation['http_code'] . "\n";
        }
    }

    echo "# HELP insight_consensus_status État agrégé courant d’une cible.\n";
    echo "# TYPE insight_consensus_status gauge\n";
    echo "# HELP insight_consensus_confidence Ratio de nœuds soutenant le consensus.\n";
    echo "# TYPE insight_consensus_confidence gauge\n";
    echo "# HELP insight_consensus_nodes Nombre de nœuds du consensus par catégorie.\n";
    echo "# TYPE insight_consensus_nodes gauge\n";
    foreach ($consensus as $current) {
        $labels = 'site_id="' . (int)$current['site_id'] . '",site="' . insight_metrics_label($current['url']) . '"';
        $statusLabels = $labels . ',status="' . insight_metrics_label($current['status']) . '"';
        echo "insight_consensus_status{{$statusLabels}} 1\n";
        echo 'insight_consensus_confidence{' . $labels . '} ' . insight_metrics_number($current['confidence']) . "\n";
        foreach (['expected', 'fresh', 'online', 'offline', 'degraded', 'missing'] as $kind) {
            $column = 'nodes_' . $kind;
            echo 'insight_consensus_nodes{' . $labels . ',kind="' . $kind . '"} ' . (int)($current[$column] ?? 0) . "\n";
        }
    }
} catch (Throwable) {
    http_response_code(503);
    echo "insight_metrics_available 0\n";
} finally {
    $connection->close();
}
