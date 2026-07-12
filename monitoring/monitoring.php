<?php

declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

require __DIR__ . '/python_bridge.php';
require __DIR__ . '/public_runtime_state.php';

if (PHP_SAPI !== 'cli') {
    header('Content-Type: application/json; charset=utf-8');
}

$result = run_monitoring_python(['monitor']);
$ok = !empty($result['ok']);

if (!$ok) {
    if (PHP_SAPI !== 'cli') {
        http_response_code((int)($result['status_code'] ?? 500));
    }
    $message = (string)($result['message'] ?? 'Le worker Python a échoué.');
    public_state_write_monitor([
        'service_name' => 'insight',
        'active_engine' => 'python',
        'monitor_last_ok' => 0,
        'monitor_last_message' => 'Échec du monitor Python.',
        'monitor_python_error' => $message,
        'monitor_checked_by' => 'python',
    ]);
    echo json_encode([
        'ok' => false,
        'engine' => 'python',
        'message' => $message,
        'raw' => $result['raw'] ?? null,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(1);
}

public_state_write_monitor([
    'service_name' => 'insight',
    'active_engine' => 'python',
    'monitor_last_ok' => 1,
    'monitor_last_message' => 'Monitor Python opérationnel.',
    'monitor_python_error' => null,
    'monitor_checked_by' => 'python',
    'sites_checked' => (int)($result['sites_checked'] ?? 0),
    'errors_count' => (int)($result['errors'] ?? 0),
    'incidents_opened' => (int)($result['incidents_opened'] ?? 0),
    'incidents_closed' => (int)($result['incidents_closed'] ?? 0),
]);

echo json_encode([
    'ok' => true,
    'engine' => 'python',
    'sites_checked' => (int)($result['sites_checked'] ?? 0),
    'errors' => (int)($result['errors'] ?? 0),
    'incidents_opened' => (int)($result['incidents_opened'] ?? 0),
    'incidents_closed' => (int)($result['incidents_closed'] ?? 0),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit(0);
