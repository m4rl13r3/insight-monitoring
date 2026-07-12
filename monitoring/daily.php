<?php

declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

require __DIR__ . '/python_bridge.php';
require __DIR__ . '/public_runtime_state.php';

$result = run_monitoring_python(['daily']);
if (empty($result['ok'])) {
    if (PHP_SAPI !== 'cli') {
        http_response_code((int)($result['status_code'] ?? 500));
    }
    public_state_write_daily([
        'service_name' => 'insight',
        'daily_last_ok' => 0,
        'daily_engine' => 'python',
    ]);
    $message = (string)($result['message'] ?? 'Le calcul journalier Python a échoué.');
    if (!empty($result['raw'])) {
        $message .= PHP_EOL . $result['raw'];
    }
    echo $message . PHP_EOL;
    exit(1);
}

public_state_write_daily([
    'service_name' => 'insight',
    'daily_last_ok' => 1,
    'daily_processed' => (int)($result['processed'] ?? 0),
    'daily_bad_data' => (int)($result['bad_data'] ?? 0),
    'daily_engine' => 'python',
]);

echo 'Total traité : ' . (int)($result['processed'] ?? 0) . PHP_EOL;
echo 'Données invalides : ' . (int)($result['bad_data'] ?? 0) . PHP_EOL;
echo 'Moteur : python' . PHP_EOL;

exit(0);
