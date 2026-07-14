<?php

declare(strict_types=1);

require dirname(__DIR__) . '/public/admin/_probes.php';

$config = require dirname(__DIR__) . '/public/config/config.php';
$database = insight_probes_database($config);
$requiredTables = [
    'sites',
    'probes',
    'hourly_stats',
    'daily_stats',
    'incidents',
    'scheduled_maintenances',
    'ssl_checks',
    'monitoring_public_runtime_state',
    'monitoring_calc_settings',
    'monitoring_aggregation_state',
    'insight_schema_migrations',
    'alert',
    'notification_channels',
    'notification_templates',
    'notification_deliveries',
    'status_pages',
    'status_page_groups',
    'status_page_monitors',
    'status_page_auth_attempts',
    'status_page_subscribers',
    'status_page_subscriber_deliveries',
    'status_page_subscription_attempts',
    'incident_sites',
    'incident_updates',
    'monitoring_nodes',
    'monitoring_assignments',
    'monitoring_reinforced_watch',
    'oncall_schedules',
    'oncall_members',
    'oncall_shifts',
    'oncall_schedule_sites',
    'oncall_escalation_events',
];
$result = $database->query('SHOW TABLES');
$tables = [];
if ($result instanceof mysqli_result) {
    while ($row = $result->fetch_row()) {
        $tables[] = (string)($row[0] ?? '');
    }
    $result->free();
}
foreach ($requiredTables as $table) {
    if (!in_array($table, $tables, true)) {
        fwrite(STDERR, "Missing MariaDB table: {$table}.\n");
        exit(1);
    }
}

$foreignKeys = $database->query(
    "SELECT TABLE_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME
     FROM information_schema.KEY_COLUMN_USAGE
     WHERE TABLE_SCHEMA=DATABASE()
       AND REFERENCED_TABLE_NAME IS NOT NULL
       AND TABLE_NAME IN ('monitoring_assignments','monitoring_agent_requests','monitoring_agent_batches','monitoring_observations','monitoring_consensus_current','monitoring_consensus_snapshots')"
);
$distributedRelations = [];
if ($foreignKeys instanceof mysqli_result) {
    foreach ($foreignKeys->fetch_all(MYSQLI_ASSOC) as $foreignKey) {
        $distributedRelations[(string)$foreignKey['TABLE_NAME'] . '.' . (string)$foreignKey['COLUMN_NAME']] = (string)$foreignKey['REFERENCED_TABLE_NAME'];
    }
    $foreignKeys->free();
}
$expectedRelations = [
    'monitoring_assignments.site_id' => 'sites',
    'monitoring_assignments.node_id' => 'monitoring_nodes',
    'monitoring_agent_requests.node_id' => 'monitoring_nodes',
    'monitoring_agent_batches.node_id' => 'monitoring_nodes',
    'monitoring_observations.site_id' => 'sites',
    'monitoring_observations.node_id' => 'monitoring_nodes',
    'monitoring_consensus_current.site_id' => 'sites',
    'monitoring_consensus_snapshots.site_id' => 'sites',
];
foreach ($expectedRelations as $relation => $target) {
    if (($distributedRelations[$relation] ?? '') !== $target) {
        fwrite(STDERR, "Missing distributed relation: {$relation}.\n");
        exit(1);
    }
}
$database->close();

$createdIds = [];
try {
    foreach ([
        ['probe_type' => 'http', 'target' => 'https://integration-http.example.test', 'interval_sec' => 60],
        ['probe_type' => 'icmp', 'target' => 'integration-icmp.example.test', 'interval_sec' => 120],
        ['probe_type' => 'tcp', 'target' => 'integration-tcp.example.test:443', 'interval_sec' => 300],
    ] as $probe) {
        $created = insight_probes_create_database($config, $probe);
        if (!($created['ok'] ?? false)) {
            throw new RuntimeException('Unable to create MariaDB data.');
        }
        $createdIds[] = (int)$created['probe']['id'];
    }
    $updated = insight_probes_update_database($config, $createdIds[2], [
        'probe_type' => 'tcp',
        'target' => 'integration-tcp.example.test:8443',
        'interval_sec' => 600,
    ]);
    if (!($updated['ok'] ?? false) || ($updated['probe']['url'] ?? '') !== 'integration-tcp.example.test:8443') {
        throw new RuntimeException('Unable to update MariaDB data.');
    }
} finally {
    foreach ($createdIds as $probeId) {
        $deleted = insight_probes_delete_database($config, $probeId);
        if (!($deleted['ok'] ?? false)) {
            fwrite(STDERR, "Unable to delete MariaDB probe {$probeId}.\n");
            exit(1);
        }
    }
}

echo "MariaDB integration passed.\n";
