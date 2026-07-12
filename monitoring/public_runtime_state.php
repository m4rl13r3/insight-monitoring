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
            public_state_log('Configuration absente : config/config.php');
            return null;
        }

        $cfg = require $cfgFile;
        if (!is_array($cfg)) {
            public_state_log('Configuration invalide : tableau attendu');
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
        } catch (Throwable $exception) {
            public_state_log('Connexion à la base échouée : ' . $exception->getMessage());
            return null;
        }

        if ($conn->connect_errno) {
            public_state_log('Connexion à la base échouée : ' . $conn->connect_error);
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
    active_engine VARCHAR(16) NOT NULL DEFAULT 'unknown',
    monitor_last_ok TINYINT(1) NOT NULL DEFAULT 0,
    monitor_last_message VARCHAR(255) NULL,
    monitor_python_error TEXT NULL,
    monitor_checked_by VARCHAR(8) NOT NULL DEFAULT 'unknown',
    sites_checked INT NOT NULL DEFAULT 0,
    errors_count INT NOT NULL DEFAULT 0,
    incidents_opened INT NOT NULL DEFAULT 0,
    incidents_closed INT NOT NULL DEFAULT 0,
    hourly_last_ok TINYINT(1) NOT NULL DEFAULT 0,
    hourly_processed INT NOT NULL DEFAULT 0,
    hourly_bad_data INT NOT NULL DEFAULT 0,
    hourly_engine VARCHAR(16) NOT NULL DEFAULT 'unknown',
    daily_last_ok TINYINT(1) NOT NULL DEFAULT 0,
    daily_processed INT NOT NULL DEFAULT 0,
    daily_bad_data INT NOT NULL DEFAULT 0,
    daily_engine VARCHAR(16) NOT NULL DEFAULT 'unknown',
    last_monitor_at DATETIME NULL,
    last_hourly_at DATETIME NULL,
    last_daily_at DATETIME NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL;
        if (!$conn->query($sql)) {
            public_state_log('Création de la table échouée : ' . $conn->error);
        }
    }
}

if (!function_exists('public_state_bind')) {
    function public_state_bind(mysqli_stmt $stmt, string $types, array &$values): bool
    {
        $arguments = [$types];
        foreach ($values as $index => &$value) {
            $arguments[] = &$values[$index];
        }
        unset($value);
        return (bool)call_user_func_array([$stmt, 'bind_param'], $arguments);
    }
}

if (!function_exists('public_state_upsert')) {
    function public_state_upsert(array $payload): bool
    {
        $conn = public_state_db_connect();
        if (!$conn) {
            public_state_log('Mise à jour ignorée : base indisponible.');
            return false;
        }

        public_state_ensure_table($conn);
        $conn->query("INSERT IGNORE INTO monitoring_public_runtime_state (singleton_id) VALUES (1)");

        $allowed = [
            'service_name' => 's',
            'service_timezone' => 's',
            'app_env' => 's',
            'active_engine' => 's',
            'monitor_last_ok' => 'i',
            'monitor_last_message' => 's',
            'monitor_python_error' => 's',
            'monitor_checked_by' => 's',
            'sites_checked' => 'i',
            'errors_count' => 'i',
            'incidents_opened' => 'i',
            'incidents_closed' => 'i',
            'hourly_last_ok' => 'i',
            'hourly_processed' => 'i',
            'hourly_bad_data' => 'i',
            'hourly_engine' => 's',
            'daily_last_ok' => 'i',
            'daily_processed' => 'i',
            'daily_bad_data' => 'i',
            'daily_engine' => 's',
            'last_monitor_at' => 's',
            'last_hourly_at' => 's',
            'last_daily_at' => 's',
        ];

        $assignments = [];
        $types = '';
        $values = [];
        foreach ($payload as $field => $value) {
            if (!isset($allowed[$field])) {
                continue;
            }
            $assignments[] = $field . ' = ?';
            $types .= $allowed[$field];
            $values[] = $allowed[$field] === 'i' ? (int)$value : $value;
        }

        $ok = true;
        if ($assignments !== []) {
            $sql = 'UPDATE monitoring_public_runtime_state SET ' . implode(', ', $assignments) . ', updated_at = CURRENT_TIMESTAMP WHERE singleton_id = 1';
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                public_state_log('Préparation de la mise à jour échouée : ' . $conn->error);
                $conn->close();
                return false;
            }
            public_state_bind($stmt, $types, $values);
            $ok = $stmt->execute();
            if (!$ok) {
                public_state_log('Mise à jour de l’état échouée : ' . $stmt->error);
            }
            $stmt->close();
        }

        $degradedSql = <<<SQL
UPDATE monitoring_public_runtime_state
SET is_degraded = CASE
    WHEN last_monitor_at IS NULL OR monitor_last_ok = 0 THEN 1
    WHEN last_hourly_at IS NOT NULL AND hourly_last_ok = 0 THEN 1
    WHEN last_daily_at IS NOT NULL AND daily_last_ok = 0 THEN 1
    ELSE 0
END
WHERE singleton_id = 1
SQL;
        if (!$conn->query($degradedSql)) {
            public_state_log('Calcul de l’état dégradé échoué : ' . $conn->error);
            $ok = false;
        }

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
