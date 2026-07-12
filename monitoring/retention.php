<?php

declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

require __DIR__ . '/python_bridge.php';

if (getenv('MONITORING_PYTHON_TIMEOUT_SEC') === false) {
    putenv('MONITORING_PYTHON_TIMEOUT_SEC=300');
}

$result = run_monitoring_python(['retention']);
if (empty($result['ok'])) {
    if (PHP_SAPI !== 'cli') {
        http_response_code((int)($result['status_code'] ?? 500));
    }
    echo (string)($result['message'] ?? 'La purge des données de monitoring a échoué.') . PHP_EOL;
    exit(1);
}

echo json_encode([
    'ok' => true,
    'deleted' => $result['deleted'] ?? [],
    'skipped' => $result['skipped'] ?? [],
    'settings' => $result['settings'] ?? [],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit(0);
