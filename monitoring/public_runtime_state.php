<?php

declare(strict_types=1);

if (!function_exists('public_state_log')) {
    function public_state_log(string $message): void
    {
        $logDir = __DIR__ . '/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }
        $line = '[' . date('Y-m-d H:i:s') . '] ' . trim($message);
        @file_put_contents($logDir . '/public_runtime_state.log', $line . PHP_EOL, FILE_APPEND);
    }
}

if (!function_exists('public_state_db_connect')) {
    function public_state_db_connect(): ?mysqli
    {
        if (defined('MYSQLI_REPORT_OFF')) {
            @mysqli_report(MYSQLI_REPORT_OFF);
        }

        $cfgFile = __DIR__ . '/config/config.php';
        if (!is_file($cfgFile)) {
            public_state_log('Config absente: config/config.php');
            return null;
        }

        $cfg = require $cfgFile;
        if (!is_array($cfg)) {
            public_state_log('Config invalide: format non tableau');
            return null;
        }

        try {
            $conn = @new mysqli(
                (string)($cfg['servername'] ?? 'localhost'),
                (string)($cfg['username'] ?? ''),
                (string)($cfg['password'] ?? ''),
                (string)($cfg['dbname'] ?? ''),
                isset($cfg['port']) && is_numeric((string)$cfg['port']) ? (int)$cfg['port'] : 3306
            );
        } catch (Throwable $e) {
            public_state_log('Connexion DB échouée: ' . $e->getMessage());
            return null;
        }

        if ($conn->connect_errno) {
            public_state_log('Connexion DB échouée: ' . $conn->connect_error);
            return null;
        }

        @$conn->set_charset('utf8mb4');
        return $conn;
    }
}

if (!function_exists('public_state_ensure_table')) {
    function public_state_ensure_table(mysqli $conn): void
    {
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS monitoring_public_runtime_state (
    singleton_id TINYINT UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1,
    service_name VARCHAR(64) NOT NULL DEFAULT 'insight',
    service_timezone VARCHAR(64) NOT NULL DEFAULT 'Europe/Paris',
    app_env VARCHAR(32) NOT NULL DEFAULT 'production',
    is_degraded TINYINT(1) NOT NULL DEFAULT 0,
    active_engine VARCHAR(16) NOT NULL DEFAULT 'php',
    monitor_last_ok TINYINT(1) NOT NULL DEFAULT 0,
    monitor_last_message VARCHAR(255) NULL,
    monitor_python_error TEXT NULL,
    monitor_fallback_message TEXT NULL,
    monitor_checked_by VARCHAR(8) NOT NULL DEFAULT 'php',
    sites_checked INT NOT NULL DEFAULT 0,
    errors_count INT NOT NULL DEFAULT 0,
    incidents_opened INT NOT NULL DEFAULT 0,
    incidents_closed INT NOT NULL DEFAULT 0,
    hourly_last_ok TINYINT(1) NOT NULL DEFAULT 0,
    hourly_processed INT NOT NULL DEFAULT 0,
    hourly_bad_data INT NOT NULL DEFAULT 0,
    hourly_engine VARCHAR(16) NOT NULL DEFAULT 'php',
    daily_last_ok TINYINT(1) NOT NULL DEFAULT 0,
    daily_processed INT NOT NULL DEFAULT 0,
    daily_bad_data INT NOT NULL DEFAULT 0,
    daily_engine VARCHAR(16) NOT NULL DEFAULT 'php',
    last_monitor_at DATETIME NULL,
    last_hourly_at DATETIME NULL,
    last_daily_at DATETIME NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL;
        if (!$conn->query($sql)) {
            public_state_log('CREATE TABLE échoué: ' . $conn->error);
        }
    }
}

if (!function_exists('public_state_upsert')) {
    function public_state_upsert(array $payload): bool
    {
        $conn = public_state_db_connect();
        if (!$conn) {
            public_state_log('Upsert ignoré: DB indisponible.');
            return false;
        }

        public_state_ensure_table($conn);

        $defaults = [
            'service_name' => 'insight',
            'service_timezone' => 'Europe/Paris',
            'app_env' => 'production',
            'is_degraded' => 0,
            'active_engine' => 'php',
            'monitor_last_ok' => 0,
            'monitor_last_message' => null,
            'monitor_python_error' => null,
            'monitor_fallback_message' => null,
            'monitor_checked_by' => 'php',
            'sites_checked' => 0,
            'errors_count' => 0,
            'incidents_opened' => 0,
            'incidents_closed' => 0,
            'hourly_last_ok' => 0,
            'hourly_processed' => 0,
            'hourly_bad_data' => 0,
            'hourly_engine' => 'php',
            'daily_last_ok' => 0,
            'daily_processed' => 0,
            'daily_bad_data' => 0,
            'daily_engine' => 'php',
            'last_monitor_at' => null,
            'last_hourly_at' => null,
            'last_daily_at' => null,
        ];

        $state = array_merge($defaults, $payload);

        $sql = <<<SQL
INSERT INTO monitoring_public_runtime_state (
    singleton_id,
    service_name,
    service_timezone,
    app_env,
    is_degraded,
    active_engine,
    monitor_last_ok,
    monitor_last_message,
    monitor_python_error,
    monitor_fallback_message,
    monitor_checked_by,
    sites_checked,
    errors_count,
    incidents_opened,
    incidents_closed,
    hourly_last_ok,
    hourly_processed,
    hourly_bad_data,
    hourly_engine,
    daily_last_ok,
    daily_processed,
    daily_bad_data,
    daily_engine,
    last_monitor_at,
    last_hourly_at,
    last_daily_at
) VALUES (
    1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
)
ON DUPLICATE KEY UPDATE
    service_name = VALUES(service_name),
    service_timezone = VALUES(service_timezone),
    app_env = VALUES(app_env),
    is_degraded = VALUES(is_degraded),
    active_engine = VALUES(active_engine),
    monitor_last_ok = VALUES(monitor_last_ok),
    monitor_last_message = VALUES(monitor_last_message),
    monitor_python_error = VALUES(monitor_python_error),
    monitor_fallback_message = VALUES(monitor_fallback_message),
    monitor_checked_by = VALUES(monitor_checked_by),
    sites_checked = VALUES(sites_checked),
    errors_count = VALUES(errors_count),
    incidents_opened = VALUES(incidents_opened),
    incidents_closed = VALUES(incidents_closed),
    hourly_last_ok = VALUES(hourly_last_ok),
    hourly_processed = VALUES(hourly_processed),
    hourly_bad_data = VALUES(hourly_bad_data),
    hourly_engine = VALUES(hourly_engine),
    daily_last_ok = VALUES(daily_last_ok),
    daily_processed = VALUES(daily_processed),
    daily_bad_data = VALUES(daily_bad_data),
    daily_engine = VALUES(daily_engine),
    last_monitor_at = VALUES(last_monitor_at),
    last_hourly_at = VALUES(last_hourly_at),
    last_daily_at = VALUES(last_daily_at),
    updated_at = CURRENT_TIMESTAMP
SQL;

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            public_state_log('Préparation upsert échouée: ' . $conn->error);
            $conn->close();
            return false;
        }

        $isDegraded = (int)$state['is_degraded'];
        $monitorLastOk = (int)$state['monitor_last_ok'];
        $sitesChecked = (int)$state['sites_checked'];
        $errorsCount = (int)$state['errors_count'];
        $incidentsOpened = (int)$state['incidents_opened'];
        $incidentsClosed = (int)$state['incidents_closed'];
        $hourlyLastOk = (int)$state['hourly_last_ok'];
        $hourlyProcessed = (int)$state['hourly_processed'];
        $hourlyBadData = (int)$state['hourly_bad_data'];
        $dailyLastOk = (int)$state['daily_last_ok'];
        $dailyProcessed = (int)$state['daily_processed'];
        $dailyBadData = (int)$state['daily_bad_data'];

        $stmt->bind_param(
            'sssississsiiiiiisiiisisss',
            $state['service_name'],
            $state['service_timezone'],
            $state['app_env'],
            $isDegraded,
            $state['active_engine'],
            $monitorLastOk,
            $state['monitor_last_message'],
            $state['monitor_python_error'],
            $state['monitor_fallback_message'],
            $state['monitor_checked_by'],
            $sitesChecked,
            $errorsCount,
            $incidentsOpened,
            $incidentsClosed,
            $hourlyLastOk,
            $hourlyProcessed,
            $hourlyBadData,
            $state['hourly_engine'],
            $dailyLastOk,
            $dailyProcessed,
            $dailyBadData,
            $state['daily_engine'],
            $state['last_monitor_at'],
            $state['last_hourly_at'],
            $state['last_daily_at']
        );

        $ok = $stmt->execute();
        if (!$ok) {
            public_state_log('Exécution upsert échouée: ' . $stmt->error);
        }

        $stmt->close();
        $conn->close();
        return $ok;
    }
}

if (!function_exists('public_state_write_monitor')) {
    function public_state_write_monitor(array $payload): bool
    {
        $payload['last_monitor_at'] = date('Y-m-d H:i:s');
        return public_state_upsert($payload);
    }
}

if (!function_exists('public_state_write_hourly')) {
    function public_state_write_hourly(array $payload): bool
    {
        $payload['last_hourly_at'] = date('Y-m-d H:i:s');
        return public_state_upsert($payload);
    }
}

if (!function_exists('public_state_write_daily')) {
    function public_state_write_daily(array $payload): bool
    {
        $payload['last_daily_at'] = date('Y-m-d H:i:s');
        return public_state_upsert($payload);
    }
}
