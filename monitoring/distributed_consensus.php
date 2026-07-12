<?php

declare(strict_types=1);

$config = require dirname(__DIR__) . '/public/config/config.php';
require_once __DIR__ . '/distributed.php';
require_once __DIR__ . '/public_runtime_state.php';

mysqli_report(MYSQLI_REPORT_OFF);
$connection = @new mysqli(
    (string)($config['servername'] ?? 'db'),
    (string)($config['username'] ?? ''),
    (string)($config['password'] ?? ''),
    (string)($config['dbname'] ?? ''),
    (int)($config['port'] ?? 3306)
);
if ($connection->connect_error) {
    fwrite(STDERR, "Connexion MariaDB impossible.\n");
    exit(1);
}
$connection->set_charset('utf8mb4');

try {
    insight_dist_ensure_schema($connection);
    $results = insight_dist_evaluate_all($connection);
    $cleanup = insight_dist_cleanup($connection);
    $errors = count(array_filter(
        $results,
        static fn(array $result): bool => strtolower((string)($result['status'] ?? 'unknown')) !== 'online'
    ));
    public_state_write_monitor([
        'service_name' => 'insight',
        'active_engine' => 'consensus',
        'monitor_last_ok' => 1,
        'monitor_last_message' => 'Consensus distribué opérationnel.',
        'monitor_python_error' => null,
        'monitor_checked_by' => 'agents',
        'sites_checked' => count($results),
        'errors_count' => $errors,
    ]);
    echo json_encode([
        'status' => 'success',
        'evaluated' => count($results),
        'results' => $results,
        'cleanup' => $cleanup,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (Throwable $exception) {
    public_state_write_monitor([
        'service_name' => 'insight',
        'active_engine' => 'consensus',
        'monitor_last_ok' => 0,
        'monitor_last_message' => 'Échec du consensus distribué.',
        'monitor_python_error' => $exception->getMessage(),
        'monitor_checked_by' => 'agents',
    ]);
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
} finally {
    $connection->close();
}
