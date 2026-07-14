<?php

declare(strict_types=1);

putenv('INSIGHT_APP_ENV=development');
putenv('INSIGHT_DEV_AUTH_BYPASS=1');
putenv('INSIGHT_NOTIFICATION_ENCRYPTION_KEY=' . str_repeat('b', 64));
$testDirectory = sys_get_temp_dir() . '/insight-probes-' . bin2hex(random_bytes(6));
putenv('INSIGHT_AUTH_DB_PATH=' . $testDirectory . '/auth.sqlite');

require dirname(__DIR__) . '/public/admin/_probes.php';

if (insight_probes_bool(false, true) !== false || insight_probes_bool(true, false) !== true) {
    fwrite(STDERR, "Boolean inputs were not preserved.\n");
    exit(1);
}

$cases = [
    [['probe_type' => 'http', 'target' => 'example.net', 'interval_sec' => 60], true, 'https://example.net'],
    [['probe_type' => 'icmp', 'target' => '192.0.2.1', 'interval_sec' => 120], true, '192.0.2.1'],
    [['probe_type' => 'tcp', 'target' => 'server.example.net:443', 'interval_sec' => 300], true, 'server.example.net:443'],
    [['probe_type' => 'tcp', 'target' => '[::1]:443', 'interval_sec' => 60], true, '[::1]:443'],
    [['probe_type' => 'browser', 'target' => 'browser.example.net', 'interval_sec' => 30, 'browser_script' => '[{"action":"goto"}]', 'browser_variables_json' => '{}', 'calc_method' => 'interval_capped'], true, 'https://browser.example.net'],
    [['probe_type' => 'websocket', 'target' => 'stream.example.net/socket', 'interval_sec' => 60, 'websocket_headers_json' => '{}'], true, 'wss://stream.example.net/socket'],
    [['probe_type' => 'mqtt', 'target' => 'broker.example.net:1883/insight', 'interval_sec' => 60], true, 'mqtt://broker.example.net:1883/insight'],
    [['probe_type' => 'sql', 'target' => 'mysql://db.example.net/insight', 'interval_sec' => 60, 'sql_query' => 'SELECT 1'], true, 'mysql://db.example.net/insight'],
    [['probe_type' => 'docker', 'target' => 'local/insight-web-1', 'interval_sec' => 60], true, 'docker://local/insight-web-1'],
    [['probe_type' => 'grpc', 'target' => 'api.example.net:50051', 'interval_sec' => 60], true, 'grpc://api.example.net:50051'],
    [['probe_type' => 'redis', 'target' => 'cache.example.net:6379/0', 'interval_sec' => 60], true, 'redis://cache.example.net:6379/0'],
    [['probe_type' => 'smtp', 'target' => 'mail.example.net:587', 'interval_sec' => 60], true, 'smtp://mail.example.net:587'],
    [['probe_type' => 'rabbitmq', 'target' => 'mq.example.net:5672/production', 'interval_sec' => 60], true, 'amqp://mq.example.net:5672/production'],
    [['probe_type' => 'snmp', 'target' => 'switch.example.net:161', 'interval_sec' => 60], true, 'snmp://switch.example.net:161'],
    [['probe_type' => 'service', 'target' => 'paris-1/systemd/nginx.service', 'interval_sec' => 60], true, 'agent://paris-1/systemd/nginx.service'],
    [['probe_type' => 'heartbeat', 'target' => 'Nightly Backup', 'interval_sec' => 60], true, 'nightly-backup'],
    [['probe_type' => 'http', 'target' => 'headers.example.net', 'interval_sec' => 60, 'request_headers_json' => '{}'], true, 'https://headers.example.net'],
    [['probe_type' => 'sql', 'target' => 'mysql://db.example.net/insight', 'interval_sec' => 60, 'sql_query' => 'DELETE FROM incidents'], false, null],
    [['probe_type' => 'http', 'target' => 'example.net', 'interval_sec' => 60, 'calc_method' => 'invented'], false, null],
    [['probe_type' => 'tcp', 'target' => 'server.example.net', 'interval_sec' => 60], false, null],
    [['probe_type' => 'icmp', 'target' => 'https://example.net', 'interval_sec' => 60], false, null],
    [['probe_type' => 'service', 'target' => 'paris-1/systemd/../../bin/sh', 'interval_sec' => 60], false, null],
];

foreach ($cases as [$input, $expectedOk, $expectedTarget]) {
    $result = insight_probes_validate($input);
    if (($result['ok'] ?? false) !== $expectedOk) {
        fwrite(STDERR, 'Unexpected validation for ' . json_encode($input) . PHP_EOL);
        exit(1);
    }
    if ($expectedOk && ($result['target'] ?? null) !== $expectedTarget) {
        fwrite(STDERR, 'Unexpected normalization for ' . json_encode($input) . PHP_EOL);
        exit(1);
    }
}

$created = insight_probes_create_preview([
    'probe_type' => 'http',
    'target' => 'https://create.example.net',
    'interval_sec' => 60,
]);
if (!($created['ok'] ?? false)) {
    fwrite(STDERR, "Local creation failed.\n");
    exit(1);
}
$probeId = (int)($created['probe']['id'] ?? 0);
$updated = insight_probes_update_preview($probeId, [
    'probe_type' => 'tcp',
    'target' => 'server.example.net:443',
    'interval_sec' => 120,
]);
if (!($updated['ok'] ?? false) || ($updated['probe']['probe_type'] ?? '') !== 'tcp') {
    fwrite(STDERR, "Local update failed.\n");
    exit(1);
}
$deleted = insight_probes_delete_preview($probeId);
if (!($deleted['ok'] ?? false) || insight_probes_preview_rows() !== []) {
    fwrite(STDERR, "Local deletion failed.\n");
    exit(1);
}
@unlink(insight_probes_preview_path());
@rmdir($testDirectory . '/sessions');
@rmdir($testDirectory);

echo "Monitor validation passed.\n";
