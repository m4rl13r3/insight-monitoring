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
    'alert',
    'notification_channels',
    'notification_templates',
    'notification_deliveries',
];
$result = $database->query('SHOW TABLES');
$tables = [];
if ($result instanceof mysqli_result) {
    while ($row = $result->fetch_row()) {
        $tables[] = (string)($row[0] ?? '');
    }
    $result->free();
}
$database->close();
foreach ($requiredTables as $table) {
    if (!in_array($table, $tables, true)) {
        fwrite(STDERR, "Table MariaDB absente : {$table}.\n");
        exit(1);
    }
}

$createdIds = [];
try {
    foreach ([
        ['probe_type' => 'http', 'target' => 'https://integration-http.example.test', 'interval_sec' => 60],
        ['probe_type' => 'icmp', 'target' => 'integration-icmp.example.test', 'interval_sec' => 120],
        ['probe_type' => 'tcp', 'target' => 'integration-tcp.example.test:443', 'interval_sec' => 300],
    ] as $probe) {
        $created = insight_probes_create_database($config, $probe);
        if (!($created['ok'] ?? false)) {
            throw new RuntimeException('Création MariaDB impossible.');
        }
        $createdIds[] = (int)$created['probe']['id'];
    }
    $updated = insight_probes_update_database($config, $createdIds[2], [
        'probe_type' => 'tcp',
        'target' => 'integration-tcp.example.test:8443',
        'interval_sec' => 600,
    ]);
    if (!($updated['ok'] ?? false) || ($updated['probe']['url'] ?? '') !== 'integration-tcp.example.test:8443') {
        throw new RuntimeException('Modification MariaDB impossible.');
    }
} finally {
    foreach ($createdIds as $probeId) {
        $deleted = insight_probes_delete_database($config, $probeId);
        if (!($deleted['ok'] ?? false)) {
            fwrite(STDERR, "Suppression MariaDB impossible pour {$probeId}.\n");
            exit(1);
        }
    }
}

echo "Intégration MariaDB réussie.\n";
