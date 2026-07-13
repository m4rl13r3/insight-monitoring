<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/monitoring/distributed.php';

function insight_node_admin_usage(): never
{
    fwrite(STDERR, "Usage: php scripts/node-admin.php list|activate|pause|revoke [node-key]\n");
    exit(2);
}

function insight_node_admin_connect(): mysqli
{
    $config = require dirname(__DIR__) . '/public/config/config.php';
    mysqli_report(MYSQLI_REPORT_OFF);
    $connection = mysqli_init();
    if (!$connection instanceof mysqli) {
        throw new RuntimeException('Unable to initialize MariaDB.');
    }
    $connection->options(MYSQLI_OPT_CONNECT_TIMEOUT, 5);
    $connected = @$connection->real_connect(
        (string)($config['servername'] ?? 'db'),
        (string)($config['username'] ?? ''),
        (string)($config['password'] ?? ''),
        (string)($config['dbname'] ?? ''),
        (int)($config['port'] ?? 3306)
    );
    if (!$connected) {
        throw new RuntimeException('Unable to connect to MariaDB.');
    }
    $connection->set_charset('utf8mb4');
    return $connection;
}

$action = strtolower(trim((string)($argv[1] ?? '')));
if (!in_array($action, ['list', 'activate', 'pause', 'revoke'], true)) {
    insight_node_admin_usage();
}

$connection = null;
try {
    $connection = insight_node_admin_connect();
    insight_dist_ensure_schema($connection);
    if ($action === 'list') {
        $rows = insight_dist_query_all($connection, "
            SELECT
                n.node_key,
                n.display_name,
                COALESCE(n.region, '-') AS region,
                COALESCE(n.zone, '-') AS zone,
                n.status,
                n.connectivity_status,
                n.last_seen_at,
                (SELECT COUNT(*) FROM monitoring_assignments a WHERE a.node_id = n.id AND a.active = 1) AS assignments
            FROM monitoring_nodes n
            ORDER BY n.node_key
        ");
        if ($rows === []) {
            echo "No registered nodes.\n";
            exit(0);
        }
        printf("%-24s %-20s %-12s %-12s %-9s %-10s %-6s %s\n", 'KEY', 'NAME', 'REGION', 'ZONE', 'STATUS', 'NETWORK', 'TARGETS', 'LAST SEEN');
        foreach ($rows as $row) {
            printf(
                "%-24s %-20s %-12s %-12s %-9s %-10s %-6d %s\n",
                mb_strimwidth((string)$row['node_key'], 0, 24),
                mb_strimwidth((string)$row['display_name'], 0, 20),
                mb_strimwidth((string)$row['region'], 0, 12),
                mb_strimwidth((string)$row['zone'], 0, 12),
                (string)$row['status'],
                (string)$row['connectivity_status'],
                (int)$row['assignments'],
                (string)$row['last_seen_at']
            );
        }
        exit(0);
    }
    $nodeKey = insight_dist_validate_node_key((string)($argv[2] ?? ''));
    $status = match ($action) {
        'activate' => 'active',
        'pause' => 'paused',
        'revoke' => 'revoked',
    };
    $statement = insight_dist_execute(
        $connection,
        'UPDATE monitoring_nodes SET status = ?, updated_at = CURRENT_TIMESTAMP(3) WHERE node_key = ?',
        [$status, $nodeKey]
    );
    $changed = $statement->affected_rows;
    $statement->close();
    if ($changed < 1) {
        throw new RuntimeException('Node not found or already in this state.');
    }
    insight_dist_refresh_assignments($connection);
    echo "Node {$nodeKey}: status {$status}.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
} finally {
    if ($connection instanceof mysqli) {
        $connection->close();
    }
}
