<?php

declare(strict_types=1);

if (!function_exists('monitoring_fallback_config')) {
    function monitoring_fallback_config(): array
    {
        $cfg = require __DIR__ . '/config/config.php';
        if (!is_array($cfg)) {
            throw new RuntimeException('Invalid monitoring config.');
        }
        return $cfg;
    }
}

if (!function_exists('monitoring_fallback_db')) {
    function monitoring_fallback_db(array $cfg): mysqli
    {
        if (function_exists('mysqli_report')) {
            mysqli_report(MYSQLI_REPORT_OFF);
        }

        $conn = @new mysqli(
            (string)($cfg['servername'] ?? 'localhost'),
            (string)($cfg['username'] ?? ''),
            (string)($cfg['password'] ?? ''),
            (string)($cfg['dbname'] ?? ''),
            isset($cfg['port']) && is_numeric((string)$cfg['port']) ? (int)$cfg['port'] : 3306
        );

        if ($conn->connect_errno) {
            throw new RuntimeException('MySQL connection failed: ' . $conn->connect_error);
        }

        $conn->set_charset('utf8mb4');
        return $conn;
    }
}

if (!function_exists('monitoring_fallback_ensure_ssl_table')) {
    function monitoring_fallback_ensure_ssl_table(mysqli $conn): void
    {
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS ssl_checks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    site_id INT NOT NULL,
    host VARCHAR(255) NOT NULL,
    port INT NOT NULL DEFAULT 443,
    checked_by VARCHAR(3) NOT NULL DEFAULT 'php',
    is_valid TINYINT(1) NULL,
    valid_from DATETIME NULL,
    valid_to DATETIME NULL,
    days_remaining INT NULL,
    issuer_name VARCHAR(255) NULL,
    issuer_cn VARCHAR(255) NULL,
    subject_cn VARCHAR(255) NULL,
    san TEXT NULL,
    tls_version VARCHAR(32) NULL,
    cipher_name VARCHAR(64) NULL,
    error_message VARCHAR(255) NULL,
    checked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_ssl_site_checked (site_id, checked_at),
    INDEX idx_ssl_valid_to (valid_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL;
        $conn->query($sql);
    }
}

if (!function_exists('monitoring_fallback_ensure_checked_by_column')) {
    function monitoring_fallback_ensure_checked_by_column(mysqli $conn, string $table, string $defaultValue): void
    {
        $tableSafe = $conn->real_escape_string($table);
        $check = $conn->query(
            "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$tableSafe}' AND COLUMN_NAME = 'checked_by' LIMIT 1"
        );
        if ($check && $check->num_rows > 0) {
            $check->free();
            return;
        }
        if ($check) {
            $check->free();
        }
        $defaultSafe = $conn->real_escape_string($defaultValue);
        $conn->query("ALTER TABLE `{$tableSafe}` ADD COLUMN checked_by VARCHAR(3) NOT NULL DEFAULT '{$defaultSafe}'");
    }
}

if (!function_exists('monitoring_fallback_ensure_maintenance_table')) {
    function monitoring_fallback_ensure_maintenance_table(mysqli $conn): void
    {
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS scheduled_maintenances (
    id INT NOT NULL AUTO_INCREMENT,
    site_id INT NULL,
    title VARCHAR(160) NOT NULL,
    description TEXT NULL,
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NOT NULL,
    status ENUM('planned','cancelled','completed') NOT NULL DEFAULT 'planned',
    notify_public TINYINT(1) NOT NULL DEFAULT 1,
    created_by_user_id INT NULL,
    created_by_name VARCHAR(140) NULL,
    cancelled_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_maintenance_site (site_id),
    KEY idx_maintenance_status (status),
    KEY idx_maintenance_dates (starts_at, ends_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL;
        $conn->query($sql);
    }
}

if (!function_exists('monitoring_fallback_active_maintenance_scope')) {
    function monitoring_fallback_active_maintenance_scope(mysqli $conn, array $siteIds): array
    {
        $siteIds = array_values(array_unique(array_map('intval', $siteIds)));
        if (count($siteIds) === 0) {
            return ['global' => false, 'sites' => []];
        }

        $placeholders = implode(',', array_fill(0, count($siteIds), '?'));
        $sql = "
            SELECT site_id
            FROM scheduled_maintenances
            WHERE status <> 'cancelled'
              AND starts_at <= NOW()
              AND ends_at >= NOW()
              AND (site_id IS NULL OR site_id IN ($placeholders))
        ";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return ['global' => false, 'sites' => []];
        }

        $types = str_repeat('i', count($siteIds));
        $stmt->bind_param($types, ...$siteIds);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $global = false;
        $specific = [];
        foreach ($rows as $row) {
            if (!array_key_exists('site_id', $row) || $row['site_id'] === null) {
                $global = true;
                continue;
            }
            $sid = (int)$row['site_id'];
            if ($sid > 0) {
                $specific[$sid] = true;
            }
        }

        return ['global' => $global, 'sites' => array_keys($specific)];
    }
}

if (!function_exists('monitoring_fallback_extract_host')) {
    function monitoring_fallback_extract_host(string $url): string
    {
        $raw = trim($url);
        if ($raw === '') {
            return '';
        }

        $parts = parse_url($raw);
        if ($parts !== false && !empty($parts['host'])) {
            return (string)$parts['host'];
        }

        $parts = parse_url('https://' . ltrim($raw, '/'));
        if ($parts !== false && !empty($parts['host'])) {
            return (string)$parts['host'];
        }

        return $raw;
    }
}

if (!function_exists('monitoring_fallback_supported_intervals')) {
    function monitoring_fallback_supported_intervals(): array
    {
        return [10, 20, 30, 60, 120, 180, 300, 600, 1800, 21600, 43200, 86400];
    }
}

if (!function_exists('monitoring_fallback_parse_int')) {
    function monitoring_fallback_parse_int($value, int $default): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && trim($value) !== '' && is_numeric(trim($value))) {
            return (int)trim($value);
        }
        if (is_float($value)) {
            return (int)$value;
        }
        return $default;
    }
}

if (!function_exists('monitoring_fallback_escalation_default_policy')) {
    function monitoring_fallback_escalation_default_policy(): array
    {
        return [
            [
                'step_key' => 'email',
                'label' => 'Email',
                'channel' => 'email',
                'delay_minutes' => 1,
                'enabled' => true,
                'is_placeholder' => false,
                'sort_order' => 1,
            ],
            [
                'step_key' => 'sms',
                'label' => 'SMS',
                'channel' => 'sms',
                'delay_minutes' => 3,
                'enabled' => true,
                'is_placeholder' => false,
                'sort_order' => 2,
            ],
            [
                'step_key' => 'call',
                'label' => 'Appel',
                'channel' => 'call',
                'delay_minutes' => 10,
                'enabled' => true,
                'is_placeholder' => true,
                'sort_order' => 3,
            ],
        ];
    }
}

if (!function_exists('monitoring_fallback_escalation_normalize_channel')) {
    function monitoring_fallback_escalation_normalize_channel($raw): string
    {
        $channel = strtolower(trim((string)$raw));
        if (in_array($channel, ['email', 'sms', 'call'], true)) {
            return $channel;
        }
        return 'email';
    }
}

if (!function_exists('monitoring_fallback_escalation_normalize_key')) {
    function monitoring_fallback_escalation_normalize_key($raw, string $fallback = ''): string
    {
        $value = strtolower(trim((string)$raw));
        $value = preg_replace('/[^a-z0-9_-]+/', '_', $value);
        $value = trim((string)$value, '_-');
        if ($value === '') {
            $value = strtolower(trim($fallback));
            $value = preg_replace('/[^a-z0-9_-]+/', '_', $value);
            $value = trim((string)$value, '_-');
        }
        if ($value === '') {
            $value = 'step';
        }
        if (strlen($value) > 32) {
            $value = substr($value, 0, 32);
        }
        return $value;
    }
}

if (!function_exists('monitoring_fallback_escalation_normalize_delay')) {
    function monitoring_fallback_escalation_normalize_delay($raw, int $default = 1): int
    {
        $delay = monitoring_fallback_parse_int($raw, $default);
        if ($delay < 0) {
            return 0;
        }
        if ($delay > 1440) {
            return 1440;
        }
        return $delay;
    }
}

if (!function_exists('monitoring_fallback_ensure_escalation_tables')) {
    function monitoring_fallback_ensure_escalation_tables(mysqli $conn): void
    {
        $conn->query("
            CREATE TABLE IF NOT EXISTS monitoring_escalation_policy (
                step_key VARCHAR(32) NOT NULL,
                label VARCHAR(80) NOT NULL,
                channel ENUM('email','sms','call') NOT NULL DEFAULT 'email',
                delay_minutes INT NOT NULL DEFAULT 1,
                enabled TINYINT(1) NOT NULL DEFAULT 1,
                is_placeholder TINYINT(1) NOT NULL DEFAULT 0,
                sort_order INT NOT NULL DEFAULT 1,
                updated_by_user_id INT NULL,
                updated_by_name VARCHAR(140) NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (step_key),
                KEY idx_monitoring_escalation_policy_sort (sort_order, delay_minutes)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $conn->query("
            CREATE TABLE IF NOT EXISTS monitoring_escalation_events (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                incident_id INT NOT NULL,
                step_key VARCHAR(32) NOT NULL,
                channel ENUM('email','sms','call') NOT NULL,
                delay_minutes INT NOT NULL DEFAULT 0,
                status ENUM('pending','sent','failed','skipped','placeholder') NOT NULL DEFAULT 'pending',
                details VARCHAR(255) NULL,
                triggered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_monitoring_escalation_incident_step (incident_id, step_key),
                KEY idx_monitoring_escalation_incident (incident_id),
                KEY idx_monitoring_escalation_triggered_at (triggered_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $indexRes = $conn->query("
            SHOW INDEX FROM monitoring_escalation_events
            WHERE Key_name = 'uniq_monitoring_escalation_incident_step'
        ");
        $hasUnique = $indexRes instanceof mysqli_result && $indexRes->num_rows > 0;
        if ($indexRes instanceof mysqli_result) {
            $indexRes->free();
        }
        if (!$hasUnique) {
            $conn->query("
                DELETE e1 FROM monitoring_escalation_events e1
                INNER JOIN monitoring_escalation_events e2
                  ON e1.incident_id = e2.incident_id
                 AND e1.step_key = e2.step_key
                 AND e1.id > e2.id
            ");
            $conn->query("
                ALTER TABLE monitoring_escalation_events
                ADD UNIQUE KEY uniq_monitoring_escalation_incident_step (incident_id, step_key)
            ");
        }

        $defaults = monitoring_fallback_escalation_default_policy();
        $stmt = $conn->prepare("
            INSERT IGNORE INTO monitoring_escalation_policy
                (step_key, label, channel, delay_minutes, enabled, is_placeholder, sort_order, updated_by_name, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'Système', NOW())
        ");
        if (!$stmt) {
            return;
        }
        foreach ($defaults as $index => $step) {
            $stepKey = monitoring_fallback_escalation_normalize_key($step['step_key'] ?? '', 'step_' . ($index + 1));
            $label = trim((string)($step['label'] ?? 'Palier ' . ($index + 1)));
            if ($label === '') {
                $label = 'Palier ' . ($index + 1);
            }
            if (strlen($label) > 80) {
                $label = substr($label, 0, 80);
            }
            $channel = monitoring_fallback_escalation_normalize_channel($step['channel'] ?? 'email');
            $delay = monitoring_fallback_escalation_normalize_delay($step['delay_minutes'] ?? 1, 1);
            $enabled = !empty($step['enabled']) ? 1 : 0;
            $placeholder = ($channel === 'call' || !empty($step['is_placeholder'])) ? 1 : 0;
            $sortOrder = monitoring_fallback_parse_int($step['sort_order'] ?? ($index + 1), $index + 1);
            if ($sortOrder <= 0) {
                $sortOrder = $index + 1;
            }
            $stmt->bind_param('sssiiii', $stepKey, $label, $channel, $delay, $enabled, $placeholder, $sortOrder);
            $stmt->execute();
        }
        $stmt->close();
    }
}

if (!function_exists('monitoring_fallback_load_escalation_policy')) {
    function monitoring_fallback_load_escalation_policy(mysqli $conn): array
    {
        monitoring_fallback_ensure_escalation_tables($conn);
        $res = $conn->query("
            SELECT step_key, label, channel, delay_minutes, enabled, is_placeholder, sort_order
            FROM monitoring_escalation_policy
            ORDER BY sort_order ASC, delay_minutes ASC, step_key ASC
        ");
        $rows = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                if (!is_array($row)) {
                    continue;
                }
                $channel = monitoring_fallback_escalation_normalize_channel($row['channel'] ?? 'email');
                $label = trim((string)($row['label'] ?? ''));
                if ($label === '') {
                    $label = strtoupper($channel);
                }
                if (strlen($label) > 80) {
                    $label = substr($label, 0, 80);
                }
                $rows[] = [
                    'step_key' => monitoring_fallback_escalation_normalize_key($row['step_key'] ?? '', 'step'),
                    'label' => $label,
                    'channel' => $channel,
                    'delay_minutes' => monitoring_fallback_escalation_normalize_delay($row['delay_minutes'] ?? 1, 1),
                    'enabled' => ((int)($row['enabled'] ?? 1) === 1),
                    'is_placeholder' => ($channel === 'call') || ((int)($row['is_placeholder'] ?? 0) === 1),
                    'sort_order' => max(1, monitoring_fallback_parse_int($row['sort_order'] ?? 1, 1)),
                ];
            }
            $res->free();
        }
        if (count($rows) === 0) {
            $rows = monitoring_fallback_escalation_default_policy();
        }
        usort($rows, static function (array $left, array $right): int {
            $sortLeft = (int)($left['sort_order'] ?? 0);
            $sortRight = (int)($right['sort_order'] ?? 0);
            if ($sortLeft !== $sortRight) {
                return $sortLeft <=> $sortRight;
            }
            $delayLeft = (int)($left['delay_minutes'] ?? 0);
            $delayRight = (int)($right['delay_minutes'] ?? 0);
            if ($delayLeft !== $delayRight) {
                return $delayLeft <=> $delayRight;
            }
            return strcmp((string)($left['step_key'] ?? ''), (string)($right['step_key'] ?? ''));
        });
        foreach ($rows as $idx => &$row) {
            $row['sort_order'] = $idx + 1;
        }
        unset($row);
        return $rows;
    }
}

if (!function_exists('monitoring_fallback_escalation_format_duration')) {
    function monitoring_fallback_escalation_format_duration(int $seconds): string
    {
        $safe = max(0, $seconds);
        $days = (int)floor($safe / 86400);
        $hours = (int)floor(($safe % 86400) / 3600);
        $minutes = (int)floor(($safe % 3600) / 60);
        $secs = (int)($safe % 60);
        if ($days > 0) {
            return $days . 'j '
                . str_pad((string)$hours, 2, '0', STR_PAD_LEFT)
                . ':'
                . str_pad((string)$minutes, 2, '0', STR_PAD_LEFT)
                . ':'
                . str_pad((string)$secs, 2, '0', STR_PAD_LEFT);
        }
        return str_pad((string)$hours, 2, '0', STR_PAD_LEFT)
            . ':'
            . str_pad((string)$minutes, 2, '0', STR_PAD_LEFT)
            . ':'
            . str_pad((string)$secs, 2, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('monitoring_fallback_escalation_incident_ref')) {
    function monitoring_fallback_escalation_incident_ref(int $incidentId, string $siteUrl): string
    {
        $base = 'INC-' . str_pad((string)$incidentId, 6, '0', STR_PAD_LEFT);
        $host = monitoring_fallback_extract_host($siteUrl);
        if ($host !== '') {
            return $base . ' · ' . $host;
        }
        $safeUrl = trim($siteUrl);
        if ($safeUrl !== '') {
            return $base . ' · ' . $safeUrl;
        }
        return $base;
    }
}

if (!function_exists('monitoring_fallback_escalation_subject')) {
    function monitoring_fallback_escalation_subject(string $label, string $incidentRef): string
    {
        $safeLabel = trim((string)preg_replace('/\s+/', ' ', $label));
        if ($safeLabel === '') {
            $safeLabel = 'Escalade';
        }
        $safeRef = trim((string)preg_replace('/\s+/', ' ', $incidentRef));
        $subject = 'Escalade monitoring · ' . $safeLabel;
        if ($safeRef !== '') {
            $subject .= ' · ' . $safeRef;
        }
        if (strlen($subject) > 160) {
            $subject = substr($subject, 0, 160);
        }
        return $subject;
    }
}

if (!function_exists('monitoring_fallback_escalation_message')) {
    function monitoring_fallback_escalation_message(string $label, string $incidentRef, int $elapsedSeconds, bool $compact): string
    {
        $safeLabel = trim((string)preg_replace('/\s+/', ' ', $label));
        if ($safeLabel === '') {
            $safeLabel = 'Escalade';
        }
        $safeRef = trim((string)preg_replace('/\s+/', ' ', $incidentRef));
        $duration = monitoring_fallback_escalation_format_duration($elapsedSeconds);
        if ($compact) {
            $parts = ['Escalade ' . $safeLabel . '.', 'T+' . $duration . '.', 'Moteur monitoring.'];
            if ($safeRef !== '') {
                array_splice($parts, 1, 0, ['Incident: ' . $safeRef . '.']);
            }
            $msg = trim(implode(' ', $parts));
            if (strlen($msg) > 480) {
                $msg = substr($msg, 0, 480);
            }
            return $msg;
        }
        $lines = ['Le palier d’escalade ' . $safeLabel . ' vient d’être déclenché.'];
        if ($safeRef !== '') {
            $lines[] = 'Contexte incident : ' . $safeRef . '.';
        }
        $lines[] = 'Temps écoulé depuis l’ouverture : ' . $duration . '.';
        $lines[] = 'Horodatage : ' . date('d/m/Y H:i:s') . '.';
        $lines[] = 'Source : moteur monitoring automatique.';
        return implode("\n", $lines);
    }
}

if (!function_exists('monitoring_fallback_dispatch_escalation_step')) {
    function monitoring_fallback_dispatch_escalation_step(
        array $targets,
        array $step,
        int $incidentId,
        string $siteUrl,
        int $elapsedSeconds
    ): array {
        $channel = monitoring_fallback_escalation_normalize_channel($step['channel'] ?? 'email');
        $label = trim((string)($step['label'] ?? strtoupper($channel)));
        if ($label === '') {
            $label = strtoupper($channel);
        }
        $incidentRef = monitoring_fallback_escalation_incident_ref($incidentId, $siteUrl);

        if (!empty($step['is_placeholder']) || $channel === 'call') {
            return ['status' => 'placeholder', 'details' => 'Appel en placeholder'];
        }

        if ($channel === 'email') {
            $emails = (is_array($targets['emails'] ?? null)) ? $targets['emails'] : [];
            $targeted = 0;
            $seen = [];
            foreach ($emails as $emailTarget) {
                if (!is_array($emailTarget)) {
                    continue;
                }
                $email = strtolower(trim((string)($emailTarget['email'] ?? '')));
                if ($email === '' || isset($seen[$email])) {
                    continue;
                }
                $seen[$email] = true;
                $targeted++;
            }
            if ($targeted <= 0) {
                return ['status' => 'skipped', 'details' => 'Aucun destinataire email actif'];
            }
            $subject = monitoring_fallback_escalation_subject($label, $incidentRef);
            $message = monitoring_fallback_escalation_message($label, $incidentRef, $elapsedSeconds, false);
            alert_batch_send_grouped_email($targets, $subject, $message);
            return ['status' => 'sent', 'details' => 'Email ' . $targeted . '/' . $targeted . ' envoyés'];
        }

        if ($channel === 'sms') {
            $smsTargets = (is_array($targets['sms'] ?? null)) ? $targets['sms'] : [];
            $seen = [];
            $targeted = 0;
            $sent = 0;
            $message = monitoring_fallback_escalation_message($label, $incidentRef, $elapsedSeconds, true);
            foreach ($smsTargets as $smsTarget) {
                if (!is_array($smsTarget)) {
                    continue;
                }
                $user = trim((string)($smsTarget['user'] ?? ''));
                $password = trim((string)($smsTarget['password'] ?? ''));
                if ($user === '' || $password === '') {
                    continue;
                }
                $key = $user . '|' . $password;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $targeted++;
                if (send_sms($user, $password, $message)) {
                    $sent++;
                }
            }
            if ($targeted <= 0) {
                return ['status' => 'skipped', 'details' => 'Aucun destinataire SMS actif'];
            }
            $failed = max(0, $targeted - $sent);
            if ($sent <= 0) {
                return ['status' => 'failed', 'details' => 'SMS 0/' . $targeted . ' envoyé'];
            }
            if ($failed > 0) {
                return ['status' => 'sent', 'details' => 'SMS ' . $sent . '/' . $targeted . ' envoyés, ' . $failed . ' en échec'];
            }
            return ['status' => 'sent', 'details' => 'SMS ' . $sent . '/' . $targeted . ' envoyés'];
        }

        return ['status' => 'skipped', 'details' => 'Canal d’escalade non supporté'];
    }
}

if (!function_exists('monitoring_fallback_apply_escalation_policy')) {
    function monitoring_fallback_apply_escalation_policy(mysqli $conn, array $cfg): array
    {
        require_once __DIR__ . '/includes/alert.php';
        monitoring_fallback_ensure_escalation_tables($conn);

        $stats = [
            'triggered' => 0,
            'sent' => 0,
            'failed' => 0,
            'skipped' => 0,
            'placeholder' => 0,
        ];
        $maxAgeMinutes = max(1, monitoring_fallback_parse_int($cfg['monitoring_escalation_max_age_minutes'] ?? 360, 360));
        $maxAgeSeconds = $maxAgeMinutes * 60;
        $maxDispatchPerRun = max(1, monitoring_fallback_parse_int($cfg['monitoring_escalation_max_notifications_per_run'] ?? 20, 20));
        $dispatchCount = 0;
        $policy = monitoring_fallback_load_escalation_policy($conn);
        $enabledSteps = array_values(array_filter($policy, static function (array $step): bool {
            return !empty($step['enabled']);
        }));
        if (count($enabledSteps) === 0) {
            return $stats;
        }

        try {
            $targets = alert_updates_build_targets(
                $conn,
                (string)($cfg['sms_user'] ?? ''),
                (string)($cfg['sms_password'] ?? '')
            );
        } catch (Throwable $e) {
            $targets = ['sms' => [], 'emails' => []];
            error_log('Escalade targets indisponibles: ' . $e->getMessage());
        }
        if (!is_array($targets)) {
            $targets = ['sms' => [], 'emails' => []];
        }

        $incidentsRes = $conn->query("
            SELECT i.id, i.started_at, s.url AS site_url
            FROM incidents i
            LEFT JOIN sites s ON s.id = i.site_id
            WHERE i.status = 0
              AND i.started_at IS NOT NULL
            ORDER BY i.started_at ASC, i.id ASC
        ");
        if (!$incidentsRes) {
            return $stats;
        }
        $incidents = $incidentsRes->fetch_all(MYSQLI_ASSOC);
        $incidentsRes->free();

        $insertStmt = $conn->prepare("
            INSERT INTO monitoring_escalation_events
                (incident_id, step_key, channel, delay_minutes, status, details, triggered_at)
            VALUES (?, ?, ?, ?, 'pending', '', NOW())
        ");
        $updateStmt = $conn->prepare("
            UPDATE monitoring_escalation_events
            SET status = ?, details = ?, triggered_at = NOW()
            WHERE incident_id = ? AND step_key = ?
            LIMIT 1
        ");
        if (!$insertStmt || !$updateStmt) {
            if ($insertStmt) {
                $insertStmt->close();
            }
            if ($updateStmt) {
                $updateStmt->close();
            }
            return $stats;
        }

        $nowTs = time();
        foreach ($incidents as $incident) {
            $incidentId = (int)($incident['id'] ?? 0);
            if ($incidentId <= 0) {
                continue;
            }
            $startedTs = strtotime((string)($incident['started_at'] ?? ''));
            if ($startedTs === false || $startedTs <= 0) {
                continue;
            }
            $elapsedSeconds = max(0, $nowTs - $startedTs);
            $siteUrl = trim((string)($incident['site_url'] ?? ''));
            $isStale = $elapsedSeconds > $maxAgeSeconds;
            $hasSite = $siteUrl !== '';

            foreach ($enabledSteps as $step) {
                $stepKey = monitoring_fallback_escalation_normalize_key($step['step_key'] ?? '', 'step');
                $channel = monitoring_fallback_escalation_normalize_channel($step['channel'] ?? 'email');
                $delay = monitoring_fallback_escalation_normalize_delay($step['delay_minutes'] ?? 1, 1);
                if ($elapsedSeconds < ($delay * 60)) {
                    continue;
                }

                $insertStmt->bind_param('issi', $incidentId, $stepKey, $channel, $delay);
                $insertOk = $insertStmt->execute();
                if (!$insertOk) {
                    if ((int)$insertStmt->errno === 1062) {
                        continue;
                    }
                    error_log('Escalade insert failed for incident ' . $incidentId . ' step ' . $stepKey . ': ' . $insertStmt->error);
                    continue;
                }

                if ($isStale) {
                    $status = 'skipped';
                    $details = 'Incident ignoré: ancienneté ' . monitoring_fallback_escalation_format_duration($elapsedSeconds) . ' (>' . $maxAgeMinutes . ' min).';
                } elseif (!$hasSite) {
                    $status = 'skipped';
                    $details = 'Incident ignoré: site introuvable.';
                } elseif ($dispatchCount >= $maxDispatchPerRun) {
                    $status = 'skipped';
                    $details = 'Escalade limitée: quota par exécution (' . $maxDispatchPerRun . ') atteint.';
                } else {
                    $dispatchCount++;
                    $dispatch = monitoring_fallback_dispatch_escalation_step($targets, $step, $incidentId, $siteUrl, $elapsedSeconds);
                    $status = trim((string)($dispatch['status'] ?? 'failed'));
                    if (!in_array($status, ['sent', 'failed', 'skipped', 'placeholder'], true)) {
                        $status = 'failed';
                    }
                    $details = trim((string)($dispatch['details'] ?? ''));
                }
                if (strlen($details) > 255) {
                    $details = substr($details, 0, 255);
                }

                $updateStmt->bind_param('ssis', $status, $details, $incidentId, $stepKey);
                $updateStmt->execute();

                $stats['triggered']++;
                if (array_key_exists($status, $stats)) {
                    $stats[$status]++;
                }
            }
        }

        $insertStmt->close();
        $updateStmt->close();
        return $stats;
    }
}

if (!function_exists('monitoring_fallback_normalize_interval')) {
    function monitoring_fallback_normalize_interval($value, int $default): int
    {
        $interval = monitoring_fallback_parse_int($value, $default);
        $allowed = monitoring_fallback_supported_intervals();
        if (in_array($interval, $allowed, true)) {
            return $interval;
        }
        return $default;
    }
}

if (!function_exists('monitoring_fallback_is_truthy')) {
    function monitoring_fallback_is_truthy($value): bool
    {
        $raw = strtolower(trim((string)$value));
        return in_array($raw, ['1', 'true', 'yes', 'on'], true);
    }
}

if (!function_exists('monitoring_fallback_is_due')) {
    function monitoring_fallback_is_due(int $nowTs, int $intervalSec, int $toleranceSec, bool $forceRun): bool
    {
        if ($forceRun) {
            return true;
        }
        if ($intervalSec <= 0) {
            return false;
        }
        $mod = $nowTs % $intervalSec;
        return ($mod <= $toleranceSec) || (($intervalSec - $mod) <= $toleranceSec);
    }
}

if (!function_exists('monitoring_fallback_is_due_by_last_probe')) {
    function monitoring_fallback_is_due_by_last_probe(
        int $nowTs,
        int $intervalSec,
        int $toleranceSec,
        bool $forceRun,
        ?int $lastProbeTs
    ): bool {
        if ($forceRun) {
            return true;
        }
        if ($intervalSec <= 0) {
            return false;
        }
        if ($lastProbeTs === null || $lastProbeTs <= 0) {
            return true;
        }
        $effectiveTolerance = max(0, min($toleranceSec, max(0, $intervalSec - 1)));
        $minElapsed = max(1, $intervalSec - $effectiveTolerance);
        return ($nowTs - $lastProbeTs) >= $minElapsed;
    }
}

if (!function_exists('monitoring_fallback_scheduler_settings')) {
    function monitoring_fallback_scheduler_settings(array $cfg): array
    {
        $httpRaw = getenv('MONITORING_HTTP_INTERVAL_SEC');
        if ($httpRaw === false || trim((string)$httpRaw) === '') {
            $httpRaw = $cfg['http_interval_sec'] ?? '60';
        }

        $icmpRaw = getenv('MONITORING_ICMP_INTERVAL_SEC');
        if ($icmpRaw === false || trim((string)$icmpRaw) === '') {
            $icmpRaw = $cfg['icmp_interval_sec'] ?? '60';
        }

        $toleranceRaw = getenv('MONITORING_SCHEDULER_TOLERANCE_SEC');
        if ($toleranceRaw === false || trim((string)$toleranceRaw) === '') {
            $toleranceRaw = $cfg['scheduler_tolerance_sec'] ?? '5';
        }

        $forceRaw = getenv('MONITORING_SCHEDULER_FORCE_RUN');
        if ($forceRaw === false || trim((string)$forceRaw) === '') {
            $forceRaw = $cfg['scheduler_force_run'] ?? '0';
        }

        $httpInterval = monitoring_fallback_normalize_interval($httpRaw, 60);
        $icmpInterval = monitoring_fallback_normalize_interval($icmpRaw, 60);
        $tolerance = max(0, monitoring_fallback_parse_int($toleranceRaw, 5));
        $forceRun = monitoring_fallback_is_truthy($forceRaw);

        return [
            'now_unix' => time(),
            'http_interval_sec' => $httpInterval,
            'icmp_interval_sec' => $icmpInterval,
            'tolerance_sec' => $tolerance,
            'force_run' => $forceRun,
        ];
    }
}

if (!function_exists('monitoring_fallback_has_column')) {
    function monitoring_fallback_has_column(mysqli $conn, string $tableName, string $columnName): bool
    {
        $stmt = $conn->prepare("
            SELECT 1 AS present
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
            LIMIT 1
        ");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('ss', $tableName, $columnName);
        $stmt->execute();
        $res = $stmt->get_result();
        $exists = (bool)($res ? $res->fetch_assoc() : false);
        $stmt->close();
        return $exists;
    }
}

if (!function_exists('monitoring_fallback_ensure_sites_runtime_columns')) {
    function monitoring_fallback_ensure_sites_runtime_columns(mysqli $conn): void
    {
        $definitions = [
            'probe_interval_sec' => "INT NOT NULL DEFAULT 60",
            'calc_method' => "VARCHAR(24) NOT NULL DEFAULT 'inherit'",
            'http_methods' => "VARCHAR(128) NOT NULL DEFAULT 'GET,POST,PUT,HEAD,DELETE,PATCH,OPTIONS'",
            'http_redirect_modes' => "VARCHAR(32) NOT NULL DEFAULT 'follow,no_follow'",
            'http_primary_method' => "VARCHAR(16) NOT NULL DEFAULT 'GET'",
            'http_primary_redirect' => "VARCHAR(16) NOT NULL DEFAULT 'follow'",
        ];
        foreach ($definitions as $column => $ddl) {
            if (monitoring_fallback_has_column($conn, 'sites', $column)) {
                continue;
            }
            $conn->query("ALTER TABLE sites ADD COLUMN {$column} {$ddl}");
        }
    }
}

if (!function_exists('monitoring_fallback_ensure_hourly_calc_columns')) {
    function monitoring_fallback_ensure_hourly_calc_columns(mysqli $conn): void
    {
        $definitions = [
            'total_seconds' => "INT NULL",
            'offline_seconds' => "INT NULL",
            'degraded_seconds' => "INT NULL",
            'maintenance_seconds' => "INT NULL",
            'availability_ratio' => "DECIMAL(7,4) NULL",
            'health_score' => "DECIMAL(7,4) NULL",
            'calc_method' => "VARCHAR(24) NULL",
        ];
        foreach ($definitions as $column => $ddl) {
            if (monitoring_fallback_has_column($conn, 'hourly_stats', $column)) {
                continue;
            }
            $conn->query("ALTER TABLE hourly_stats ADD COLUMN {$column} {$ddl}");
        }
    }
}

if (!function_exists('monitoring_fallback_ensure_daily_calc_columns')) {
    function monitoring_fallback_ensure_daily_calc_columns(mysqli $conn): void
    {
        $definitions = [
            'total_seconds' => "INT NULL",
            'offline_seconds' => "INT NULL",
            'degraded_seconds' => "INT NULL",
            'maintenance_seconds' => "INT NULL",
            'availability_ratio' => "DECIMAL(7,4) NULL",
            'health_score' => "DECIMAL(7,4) NULL",
            'calc_method' => "VARCHAR(24) NULL",
        ];
        foreach ($definitions as $column => $ddl) {
            if (monitoring_fallback_has_column($conn, 'daily_stats', $column)) {
                continue;
            }
            $conn->query("ALTER TABLE daily_stats ADD COLUMN {$column} {$ddl}");
        }
    }
}

if (!function_exists('monitoring_fallback_ensure_calc_settings_table')) {
    function monitoring_fallback_ensure_calc_settings_table(mysqli $conn): void
    {
        $conn->query("
            CREATE TABLE IF NOT EXISTS monitoring_calc_settings (
                singleton_id TINYINT NOT NULL DEFAULT 1,
                switch_at DATETIME NOT NULL,
                default_calc_method VARCHAR(24) NOT NULL DEFAULT 'time_weighted',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (singleton_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $res = $conn->query("SELECT singleton_id FROM monitoring_calc_settings WHERE singleton_id = 1 LIMIT 1");
        $exists = $res && $res->fetch_assoc();
        if ($res) {
            $res->free();
        }
        if ($exists) {
            return;
        }
        $switchAt = date('Y-m-d 00:00:00', strtotime('tomorrow'));
        $stmt = $conn->prepare("INSERT INTO monitoring_calc_settings (singleton_id, switch_at, default_calc_method) VALUES (1, ?, 'time_weighted')");
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('s', $switchAt);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('monitoring_fallback_normalize_calc_method')) {
    function monitoring_fallback_normalize_calc_method($value): string
    {
        $method = strtolower(trim((string)$value));
        if (in_array($method, ['inherit', 'legacy', 'time_weighted'], true)) {
            return $method;
        }
        return 'inherit';
    }
}

if (!function_exists('monitoring_fallback_calc_settings')) {
    function monitoring_fallback_calc_settings(mysqli $conn): array
    {
        monitoring_fallback_ensure_calc_settings_table($conn);
        $settings = [
            'switch_at' => null,
            'switch_ts' => null,
            'default_calc_method' => 'time_weighted',
        ];
        $res = $conn->query("SELECT switch_at, default_calc_method FROM monitoring_calc_settings WHERE singleton_id = 1 LIMIT 1");
        if (!$res) {
            return $settings;
        }
        $row = $res->fetch_assoc();
        $res->free();
        if (!$row) {
            return $settings;
        }
        $switchAt = trim((string)($row['switch_at'] ?? ''));
        $switchTs = strtotime($switchAt);
        if ($switchAt !== '' && $switchTs !== false) {
            $settings['switch_at'] = $switchAt;
            $settings['switch_ts'] = (int)$switchTs;
        }
        $defaultMethod = strtolower(trim((string)($row['default_calc_method'] ?? 'time_weighted')));
        if (!in_array($defaultMethod, ['legacy', 'time_weighted'], true)) {
            $defaultMethod = 'time_weighted';
        }
        $settings['default_calc_method'] = $defaultMethod;
        return $settings;
    }
}

if (!function_exists('monitoring_fallback_effective_calc_method')) {
    function monitoring_fallback_effective_calc_method(string $siteCalcMethod, int $slotStartTs, array $settings): string
    {
        $siteMethod = monitoring_fallback_normalize_calc_method($siteCalcMethod);
        if ($siteMethod === 'legacy' || $siteMethod === 'time_weighted') {
            return $siteMethod;
        }
        $defaultMethod = in_array(($settings['default_calc_method'] ?? ''), ['legacy', 'time_weighted'], true)
            ? (string)$settings['default_calc_method']
            : 'time_weighted';
        $switchTs = isset($settings['switch_ts']) && is_int($settings['switch_ts']) ? (int)$settings['switch_ts'] : null;
        if ($switchTs === null) {
            return $defaultMethod;
        }
        if ($slotStartTs >= $switchTs) {
            return $defaultMethod;
        }
        return 'legacy';
    }
}

if (!function_exists('monitoring_fallback_status_bucket')) {
    function monitoring_fallback_status_bucket($status): string
    {
        $state = strtolower(trim((string)$status));
        if (in_array($state, ['online', 'up', 'ok', 'success', 'yes'], true)) {
            return 'online';
        }
        if ($state === 'maintenance') {
            return 'maintenance';
        }
        if (in_array($state, ['partial', 'partially', 'degraded', 'warn'], true)) {
            return 'degraded';
        }
        if (in_array($state, ['offline', 'down', 'error', 'failed', 'timeout', 'no'], true)) {
            return 'offline';
        }
        return 'unknown';
    }
}

if (!function_exists('monitoring_fallback_weighted_hour_metrics')) {
    function monitoring_fallback_weighted_hour_metrics(mysqli $conn, int $siteId, string $dateVal, int $hourVal): array
    {
        $slotStart = strtotime($dateVal . ' ' . str_pad((string)$hourVal, 2, '0', STR_PAD_LEFT) . ':00:00');
        if ($slotStart === false) {
            return [
                'total_seconds' => 3600,
                'offline_seconds' => 3600,
                'degraded_seconds' => 0,
                'maintenance_seconds' => 0,
                'availability_ratio' => 0.0,
                'health_score' => 0.0,
                'minutes_offline' => 60,
            ];
        }
        $slotEnd = $slotStart + 3600;

        $prevStatus = 'unknown';
        $prevStmt = $conn->prepare(
            "SELECT status
             FROM probes
             WHERE site_id = ? AND checked_at < ?
             ORDER BY checked_at DESC
             LIMIT 1"
        );
        if ($prevStmt) {
            $slotStartStr = date('Y-m-d H:i:s', $slotStart);
            $prevStmt->bind_param('is', $siteId, $slotStartStr);
            $prevStmt->execute();
            $prevRow = $prevStmt->get_result()->fetch_assoc();
            $prevStmt->close();
            if ($prevRow) {
                $prevStatus = monitoring_fallback_status_bucket($prevRow['status'] ?? 'unknown');
            }
        }

        $rows = [];
        $rowsStmt = $conn->prepare(
            "SELECT checked_at, status
             FROM probes
             WHERE site_id = ? AND checked_at >= ? AND checked_at < ?
             ORDER BY checked_at ASC"
        );
        if ($rowsStmt) {
            $slotStartStr = date('Y-m-d H:i:s', $slotStart);
            $slotEndStr = date('Y-m-d H:i:s', $slotEnd);
            $rowsStmt->bind_param('iss', $siteId, $slotStartStr, $slotEndStr);
            $rowsStmt->execute();
            $rows = $rowsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $rowsStmt->close();
        }

        $totals = [
            'online' => 0,
            'offline' => 0,
            'degraded' => 0,
            'maintenance' => 0,
            'unknown' => 0,
        ];

        $cursor = $slotStart;
        $current = $prevStatus;
        foreach ($rows as $row) {
            $checkedAtTs = strtotime((string)($row['checked_at'] ?? ''));
            if ($checkedAtTs === false) {
                continue;
            }
            if ($checkedAtTs < $slotStart) {
                $current = monitoring_fallback_status_bucket($row['status'] ?? 'unknown');
                continue;
            }
            if ($checkedAtTs > $slotEnd) {
                $checkedAtTs = $slotEnd;
            }
            $delta = $checkedAtTs - $cursor;
            if ($delta > 0) {
                if (!isset($totals[$current])) {
                    $current = 'unknown';
                }
                $totals[$current] += $delta;
            }
            $cursor = $checkedAtTs;
            $current = monitoring_fallback_status_bucket($row['status'] ?? 'unknown');
        }

        $remaining = $slotEnd - $cursor;
        if ($remaining > 0) {
            if (!isset($totals[$current])) {
                $current = 'unknown';
            }
            $totals[$current] += $remaining;
        }

        $totalSeconds = 3600;
        $maintenanceSeconds = (int)$totals['maintenance'];
        $degradedSeconds = (int)$totals['degraded'];
        $onlineSeconds = (int)$totals['online'];
        $offlineSeconds = (int)$totals['offline'] + (int)$totals['unknown'];
        $denominator = $totalSeconds - $maintenanceSeconds;

        if ($denominator <= 0) {
            $availability = null;
            $health = null;
        } else {
            $availability = round(max(0.0, min(1.0, ($denominator - $offlineSeconds) / $denominator)), 4);
            $health = round(max(0.0, min(1.0, ($onlineSeconds + (0.5 * $degradedSeconds)) / $denominator)), 4);
        }

        $minutesOffline = (int)round($offlineSeconds / 60);
        if ($minutesOffline < 0) {
            $minutesOffline = 0;
        }
        if ($minutesOffline > 60) {
            $minutesOffline = 60;
        }

        return [
            'total_seconds' => $totalSeconds,
            'offline_seconds' => $offlineSeconds,
            'degraded_seconds' => $degradedSeconds,
            'maintenance_seconds' => $maintenanceSeconds,
            'availability_ratio' => $availability,
            'health_score' => $health,
            'minutes_offline' => $minutesOffline,
        ];
    }
}

if (!function_exists('monitoring_fallback_http_probe')) {
    function monitoring_fallback_http_probe(string $url, string $method = 'GET', bool $followRedirects = true): array
    {
        $start = microtime(true);
        $httpCode = 0;
        $redirected = false;
        $normalizedMethod = strtoupper(trim($method));
        if (!in_array($normalizedMethod, ['GET', 'POST', 'PUT', 'HEAD', 'DELETE', 'PATCH', 'OPTIONS'], true)) {
            $normalizedMethod = 'GET';
        }

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => $followRedirects,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_NOSIGNAL => true,
                CURLOPT_USERAGENT => 'Insight-Monitor/1.0',
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_CUSTOMREQUEST => $normalizedMethod,
                CURLOPT_NOBODY => $normalizedMethod === 'HEAD',
            ]);

            $body = curl_exec($ch);
            if ($body !== false) {
                $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $effectiveUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
                if ($effectiveUrl !== '') {
                    $redirected = rtrim($effectiveUrl, '/') !== rtrim($url, '/');
                }
            }
            curl_close($ch);
        } else {
            $context = stream_context_create([
                'http' => [
                    'method' => $normalizedMethod,
                    'timeout' => 20,
                    'ignore_errors' => true,
                    'follow_location' => $followRedirects ? 1 : 0,
                    'max_redirects' => $followRedirects ? 5 : 0,
                    'header' => "User-Agent: Insight-Monitor/1.0\r\n",
                ],
            ]);
            $raw = @file_get_contents($url, false, $context);
            if ($raw !== false && isset($http_response_header[0])) {
                if (preg_match('/\s(\d{3})\s/', (string)$http_response_header[0], $m)) {
                    $httpCode = (int)$m[1];
                }
                foreach ((array)$http_response_header as $headerLine) {
                    if (stripos((string)$headerLine, 'Location:') === 0) {
                        $redirected = true;
                        break;
                    }
                }
            }
        }

        $elapsedMs = round((microtime(true) - $start) * 1000, 2);
        $online = $httpCode >= 200 && $httpCode < 400;

        return [
            'status' => $online ? 'online' : 'offline',
            'response_time' => $online ? $elapsedMs : null,
            'http_code' => $httpCode > 0 ? $httpCode : null,
            'http_method' => $normalizedMethod,
            'follow_redirects' => $followRedirects,
            'redirected' => $redirected,
        ];
    }
}

if (!function_exists('monitoring_fallback_ping_probe')) {
    function monitoring_fallback_ping_probe(string $host): array
    {
        $start = microtime(true);
        $exit = 1;

        if ($host !== '') {
            $cmd = 'ping -c 1 -W 1 ' . escapeshellarg($host) . ' >/dev/null 2>&1';
            @exec($cmd, $out, $exit);
        }

        $elapsedMs = round((microtime(true) - $start) * 1000, 2);
        $online = ($exit === 0);

        return [
            'status' => $online ? 'online' : 'offline',
            'response_time' => $online ? $elapsedMs : null,
            'http_code' => null,
        ];
    }
}

if (!function_exists('monitoring_fallback_tcp_target')) {
    function monitoring_fallback_tcp_target(string $target): array
    {
        $value = trim($target);
        if ($value === '') {
            return ['', 0];
        }
        $parsed = parse_url(str_contains($value, '://') ? $value : 'tcp://' . $value);
        if (!is_array($parsed)) {
            return ['', 0];
        }
        $host = trim((string)($parsed['host'] ?? ''));
        $port = (int)($parsed['port'] ?? 0);
        if ($host === '' || $port < 1 || $port > 65535) {
            return ['', 0];
        }
        return [$host, $port];
    }
}

if (!function_exists('monitoring_fallback_tcp_probe')) {
    function monitoring_fallback_tcp_probe(string $host, int $port): array
    {
        $start = microtime(true);
        $socket = false;
        if ($host !== '' && $port > 0) {
            $socket = @fsockopen($host, $port, $errorCode, $errorMessage, 3.0);
        }
        $online = is_resource($socket);
        if ($online) {
            fclose($socket);
        }
        $elapsedMs = round((microtime(true) - $start) * 1000, 2);
        return [
            'status' => $online ? 'online' : 'offline',
            'response_time' => $online ? $elapsedMs : null,
            'http_code' => null,
        ];
    }
}

if (!function_exists('monitoring_fallback_ssl_probe')) {
    function monitoring_fallback_ssl_probe(string $url): array
    {
        $host = monitoring_fallback_extract_host($url);
        if ($host === '') {
            return [
                'host' => null,
                'port' => 443,
                'is_valid' => 0,
                'valid_from' => null,
                'valid_to' => null,
                'days_remaining' => null,
                'issuer_name' => null,
                'issuer_cn' => null,
                'subject_cn' => null,
                'san' => null,
                'tls_version' => null,
                'cipher_name' => null,
                'error_message' => 'invalid_host',
            ];
        }

        $context = stream_context_create([
            'ssl' => [
                'capture_peer_cert' => true,
                'verify_peer' => false,
                'verify_peer_name' => false,
                'SNI_enabled' => true,
                'peer_name' => $host,
            ],
        ]);

        $client = @stream_socket_client(
            'ssl://' . $host . ':443',
            $errno,
            $errstr,
            10,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if ($client === false) {
            return [
                'host' => $host,
                'port' => 443,
                'is_valid' => 0,
                'valid_from' => null,
                'valid_to' => null,
                'days_remaining' => null,
                'issuer_name' => null,
                'issuer_cn' => null,
                'subject_cn' => null,
                'san' => null,
                'tls_version' => null,
                'cipher_name' => null,
                'error_message' => ($errstr !== '' ? $errstr : ('ssl_error_' . $errno)),
            ];
        }

        $params = stream_context_get_params($client);
        @fclose($client);

        $cert = $params['options']['ssl']['peer_certificate'] ?? null;
        if (!$cert) {
            return [
                'host' => $host,
                'port' => 443,
                'is_valid' => 0,
                'valid_from' => null,
                'valid_to' => null,
                'days_remaining' => null,
                'issuer_name' => null,
                'issuer_cn' => null,
                'subject_cn' => null,
                'san' => null,
                'tls_version' => null,
                'cipher_name' => null,
                'error_message' => 'peer_certificate_missing',
            ];
        }

        $certInfo = @openssl_x509_parse($cert);
        if (!is_array($certInfo)) {
            return [
                'host' => $host,
                'port' => 443,
                'is_valid' => 0,
                'valid_from' => null,
                'valid_to' => null,
                'days_remaining' => null,
                'issuer_name' => null,
                'issuer_cn' => null,
                'subject_cn' => null,
                'san' => null,
                'tls_version' => null,
                'cipher_name' => null,
                'error_message' => 'cert_parse_failed',
            ];
        }

        $validFromTs = isset($certInfo['validFrom_time_t']) ? (int)$certInfo['validFrom_time_t'] : null;
        $validToTs = isset($certInfo['validTo_time_t']) ? (int)$certInfo['validTo_time_t'] : null;
        $daysRemaining = null;
        if ($validToTs !== null) {
            $daysRemaining = (int)floor(($validToTs - time()) / 86400);
        }

        $issuer = $certInfo['issuer'] ?? [];
        $subject = $certInfo['subject'] ?? [];
        $issuerCn = is_array($issuer) ? ($issuer['CN'] ?? null) : null;
        $issuerOrg = is_array($issuer) ? ($issuer['O'] ?? null) : null;
        $subjectCn = is_array($subject) ? ($subject['CN'] ?? null) : null;
        $issuerName = $issuerOrg ?: $issuerCn;

        $san = null;
        if (!empty($certInfo['extensions']['subjectAltName'])) {
            $san = (string)$certInfo['extensions']['subjectAltName'];
        }

        return [
            'host' => $host,
            'port' => 443,
            'is_valid' => ($daysRemaining !== null && $daysRemaining >= 0) ? 1 : 0,
            'valid_from' => $validFromTs ? date('Y-m-d H:i:s', $validFromTs) : null,
            'valid_to' => $validToTs ? date('Y-m-d H:i:s', $validToTs) : null,
            'days_remaining' => $daysRemaining,
            'issuer_name' => $issuerName,
            'issuer_cn' => $issuerCn,
            'subject_cn' => $subjectCn,
            'san' => $san,
            'tls_version' => null,
            'cipher_name' => null,
            'error_message' => null,
        ];
    }
}

if (!function_exists('monitoring_fallback_insert_ssl')) {
    function monitoring_fallback_insert_ssl(mysqli $conn, int $siteId, array $ssl): void
    {
        if (empty($ssl['host'])) {
            return;
        }

        $stmt = $conn->prepare(
            "INSERT INTO ssl_checks
            (site_id, host, port, checked_by, is_valid, valid_from, valid_to, days_remaining, issuer_name, issuer_cn, subject_cn, san, tls_version, cipher_name, error_message, checked_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
        );
        if (!$stmt) {
            return;
        }

        $port = (int)($ssl['port'] ?? 443);
        $checkedBy = 'php';
        $isValid = isset($ssl['is_valid']) ? (int)$ssl['is_valid'] : null;
        $daysRemaining = isset($ssl['days_remaining']) ? (int)$ssl['days_remaining'] : null;
        $host = (string)$ssl['host'];
        $validFrom = $ssl['valid_from'] ?? null;
        $validTo = $ssl['valid_to'] ?? null;
        $issuerName = $ssl['issuer_name'] ?? null;
        $issuerCn = $ssl['issuer_cn'] ?? null;
        $subjectCn = $ssl['subject_cn'] ?? null;
        $san = $ssl['san'] ?? null;
        $tlsVersion = $ssl['tls_version'] ?? null;
        $cipherName = $ssl['cipher_name'] ?? null;
        $errorMessage = $ssl['error_message'] ?? null;

        $stmt->bind_param(
            'isisississsssss',
            $siteId,
            $host,
            $port,
            $checkedBy,
            $isValid,
            $validFrom,
            $validTo,
            $daysRemaining,
            $issuerName,
            $issuerCn,
            $subjectCn,
            $san,
            $tlsVersion,
            $cipherName,
            $errorMessage
        );
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('monitoring_fallback_insert_probe')) {
    function monitoring_fallback_probe_source_node(): string
    {
        $fromEnv = trim((string)(getenv('MONITORING_REPLICA_SOURCE_NODE') ?: ''));
        if ($fromEnv !== '') {
            return $fromEnv;
        }
        $host = trim((string)(gethostname() ?: ''));
        return $host !== '' ? $host : 'monitoring';
    }

    function monitoring_fallback_probes_has_source_columns(mysqli $conn): bool
    {
        static $cache = null;
        if (is_bool($cache)) {
            return $cache;
        }
        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS c
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'probes'
               AND COLUMN_NAME IN ('source_node', 'source_probe_id')"
        );
        if (!$stmt) {
            $cache = false;
            return false;
        }
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $cache = ((int)($row['c'] ?? 0) >= 2);
        return $cache;
    }

    function monitoring_fallback_insert_probe(mysqli $conn, int $siteId, string $probeType, array $result): void
    {
        $status = (string)($result['status'] ?? 'offline');
        $responseTime = ($status === 'online' && isset($result['response_time'])) ? (float)$result['response_time'] : null;
        $httpCode = isset($result['http_code']) ? (int)$result['http_code'] : null;
        $checkedBy = 'php';
        $sourceNode = monitoring_fallback_probe_source_node();
        $sourceProbeId = (string)round(microtime(true) * 1000000);

        if (monitoring_fallback_probes_has_source_columns($conn)) {
            $stmt = $conn->prepare(
                "INSERT INTO probes (site_id, probe_type, status, response_time, http_code, checked_by, checked_at, source_node, source_probe_id) VALUES (?, ?, ?, ?, ?, ?, NOW(), ?, ?)"
            );
            if ($stmt) {
                $stmt->bind_param('issdisss', $siteId, $probeType, $status, $responseTime, $httpCode, $checkedBy, $sourceNode, $sourceProbeId);
                $stmt->execute();
                $stmt->close();
                return;
            }
        }

        $stmt = $conn->prepare(
            "INSERT INTO probes (site_id, probe_type, status, response_time, http_code, checked_by, checked_at) VALUES (?, ?, ?, ?, ?, ?, NOW())"
        );
        if (!$stmt) {
            throw new RuntimeException('Unable to prepare probes insert.');
        }
        $stmt->bind_param('issdis', $siteId, $probeType, $status, $responseTime, $httpCode, $checkedBy);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('monitoring_fallback_open_incident')) {
    function monitoring_fallback_open_incident(mysqli $conn, int $siteId, string $siteUrl, array $result, array $cfg, array &$notificationBatch): bool
    {
        $recentStmt = $conn->prepare("SELECT status FROM probes WHERE site_id = ? ORDER BY checked_at DESC LIMIT 3");
        if (!$recentStmt) {
            return false;
        }
        $recentStmt->bind_param('i', $siteId);
        $recentStmt->execute();
        $recent = $recentStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $recentStmt->close();

        if (count($recent) !== 3) {
            return false;
        }
        foreach ($recent as $row) {
            if (($row['status'] ?? '') !== 'offline') {
                return false;
            }
        }

        $openStmt = $conn->prepare("SELECT id FROM incidents WHERE site_id = ? AND status = 0 LIMIT 1");
        if (!$openStmt) {
            return false;
        }
        $openStmt->bind_param('i', $siteId);
        $openStmt->execute();
        $open = $openStmt->get_result()->fetch_assoc();
        $openStmt->close();
        if ($open) {
            return false;
        }

        $httpCode = isset($result['http_code']) ? (int)$result['http_code'] : null;
        $insert = $conn->prepare("INSERT INTO incidents (site_id, started_at, http_code, postmortem, ai_created) VALUES (?, NOW(), ?, NULL, 0)");
        if (!$insert) {
            return false;
        }
        $insert->bind_param('ii', $siteId, $httpCode);
        $insert->execute();
        $insert->close();

        require_once __DIR__ . '/includes/alert.php';
        alert_batch_queue_incident($notificationBatch, $siteUrl, 'open');
        return true;
    }
}

if (!function_exists('monitoring_fallback_close_incident')) {
    function monitoring_fallback_close_incident(mysqli $conn, int $siteId, string $siteUrl, array $cfg, array &$notificationBatch): bool
    {
        $openStmt = $conn->prepare(
            "SELECT id, started_at, http_code FROM incidents WHERE site_id = ? AND status = 0 ORDER BY started_at DESC LIMIT 1"
        );
        if (!$openStmt) {
            return false;
        }
        $openStmt->bind_param('i', $siteId);
        $openStmt->execute();
        $row = $openStmt->get_result()->fetch_assoc();
        $openStmt->close();

        if (!$row) {
            return false;
        }

        $incidentId = (int)($row['id'] ?? 0);
        if ($incidentId <= 0) {
            return false;
        }

        $upd = $conn->prepare("UPDATE incidents SET ended_at = NOW(), status = 1 WHERE id = ?");
        if (!$upd) {
            return false;
        }
        $upd->bind_param('i', $incidentId);
        $upd->execute();
        $upd->close();

        $pmText = '';
        $timeout = true;
        $startedAt = trim((string)($row['started_at'] ?? ''));
        $endedAt = date('Y-m-d H:i:s');
        $startedTs = strtotime($startedAt);
        $endedTs = strtotime($endedAt);
        if ($endedTs === false || $endedTs <= 0) {
            $endedTs = time();
        }
        $durationSec = ($startedTs !== false && $startedTs > 0) ? max(0, $endedTs - $startedTs) : 0;
        $httpCode = isset($row['http_code']) ? (int)$row['http_code'] : 0;
        $hours = intdiv($durationSec, 3600);
        $minutes = intdiv($durationSec % 3600, 60);
        $seconds = $durationSec % 60;
        $duration = $hours > 0
            ? $hours . ' h ' . $minutes . ' min'
            : ($minutes > 0 ? $minutes . ' min ' . $seconds . ' s' : $seconds . ' s');
        $pmText = 'Incident résolu après une indisponibilité de ' . $duration . '.';
        if ($httpCode > 0) {
            $pmText .= ' Dernier code HTTP observé : ' . $httpCode . '.';
        }
        $timeout = false;
        $pmUpd = $conn->prepare("UPDATE incidents SET postmortem = ?, ai_created = 0 WHERE id = ?");
        if ($pmUpd) {
            $pmUpd->bind_param('si', $pmText, $incidentId);
            $pmUpd->execute();
            $pmUpd->close();
        }

        require_once __DIR__ . '/includes/alert.php';
        alert_batch_queue_incident($notificationBatch, $siteUrl, 'close', $pmText, $timeout);
        return true;
    }
}

if (!function_exists('run_monitoring_php_fallback')) {
    function run_monitoring_php_fallback(): array
    {
        try {
            $cfg = monitoring_fallback_config();
            $conn = monitoring_fallback_db($cfg);
            monitoring_fallback_ensure_ssl_table($conn);
            monitoring_fallback_ensure_checked_by_column($conn, 'probes', 'php');
            monitoring_fallback_ensure_checked_by_column($conn, 'ssl_checks', 'php');
            monitoring_fallback_ensure_maintenance_table($conn);
            monitoring_fallback_ensure_escalation_tables($conn);
            monitoring_fallback_ensure_sites_runtime_columns($conn);

            require_once __DIR__ . '/includes/alert.php';
            $notificationBatch = [
                'incident_open' => [],
                'incident_close' => [],
                'status_offline' => [],
                'status_online' => [],
            ];

            $sitesRes = $conn->query("
                SELECT
                    id,
                    url,
                    probe_type,
                    probe_interval_sec,
                    http_primary_method,
                    http_primary_redirect
                FROM sites
            ");
            if (!$sitesRes) {
                $conn->close();
                return ['ok' => false, 'status_code' => 500, 'message' => 'Failed to fetch sites.'];
            }
            $sites = $sitesRes->fetch_all(MYSQLI_ASSOC);
            $sitesRes->free();
            $siteIds = [];
            foreach ($sites as $site) {
                $sid = (int)($site['id'] ?? 0);
                if ($sid > 0) {
                    $siteIds[] = $sid;
                }
            }
            $maintenanceScope = monitoring_fallback_active_maintenance_scope($conn, $siteIds);
            $maintenanceGlobal = !empty($maintenanceScope['global']);
            $maintenanceSites = [];
            foreach (($maintenanceScope['sites'] ?? []) as $sid) {
                $sid = (int)$sid;
                if ($sid > 0) {
                    $maintenanceSites[$sid] = true;
                }
            }

            $processed = 0;
            $errors = 0;
            $opened = 0;
            $closed = 0;
            $underMaintenance = 0;
            $skippedScheduler = 0;
            $scheduler = monitoring_fallback_scheduler_settings($cfg);
            $nowTs = (int)($scheduler['now_unix'] ?? time());
            $httpIntervalSec = (int)($scheduler['http_interval_sec'] ?? 60);
            $icmpIntervalSec = (int)($scheduler['icmp_interval_sec'] ?? 60);
            $toleranceSec = (int)($scheduler['tolerance_sec'] ?? 5);
            $forceRun = !empty($scheduler['force_run']);

            foreach ($sites as $site) {
                $siteId = (int)($site['id'] ?? 0);
                $siteUrl = (string)($site['url'] ?? '');
                $probeType = strtolower((string)($site['probe_type'] ?? 'http'));

                if ($siteId <= 0 || $siteUrl === '') {
                    continue;
                }

                $probeFamily = '';
                if ($probeType === 'http') {
                    $probeFamily = 'http';
                } elseif ($probeType === 'ping' || $probeType === 'icmp') {
                    $probeFamily = 'icmp';
                } elseif ($probeType === 'tcp') {
                    $probeFamily = 'tcp';
                } else {
                    continue;
                }

                $defaultInterval = ($probeFamily === 'http') ? $httpIntervalSec : $icmpIntervalSec;
                $intervalSec = monitoring_fallback_normalize_interval($site['probe_interval_sec'] ?? null, $defaultInterval);
                if (!monitoring_fallback_is_due($nowTs, $intervalSec, $toleranceSec, $forceRun)) {
                    $skippedScheduler++;
                    continue;
                }

                try {
                    if ($probeType === 'ping' || $probeType === 'icmp') {
                        $host = monitoring_fallback_extract_host($siteUrl);
                        $result = monitoring_fallback_ping_probe($host);
                    } elseif ($probeType === 'tcp') {
                        [$host, $port] = monitoring_fallback_tcp_target($siteUrl);
                        $result = monitoring_fallback_tcp_probe($host, $port);
                    } else {
                        $httpMethod = strtoupper(trim((string)($site['http_primary_method'] ?? 'GET')));
                        $redirectRaw = strtolower(trim((string)($site['http_primary_redirect'] ?? 'follow')));
                        $followRedirects = !in_array($redirectRaw, ['no_follow', 'nofollow', 'strict', 'off', 'false', 'no', '0'], true);
                        $result = monitoring_fallback_http_probe($siteUrl, $httpMethod, $followRedirects);
                        $ssl = monitoring_fallback_ssl_probe($siteUrl);
                        monitoring_fallback_insert_ssl($conn, $siteId, $ssl);
                        $probeType = 'http';
                    }

                    monitoring_fallback_insert_probe($conn, $siteId, $probeType, $result);

                    $status = (string)($result['status'] ?? 'offline');
                    $justClosed = false;
                    $siteUnderMaintenance = $maintenanceGlobal || isset($maintenanceSites[$siteId]);
                    if ($siteUnderMaintenance) {
                        $underMaintenance++;
                        if ($status === 'online') {
                            if (monitoring_fallback_close_incident($conn, $siteId, $siteUrl, $cfg, $notificationBatch)) {
                                $closed++;
                            }
                        }
                        alertSite(
                            $conn,
                            (string)($cfg['sms_user'] ?? ''),
                            (string)($cfg['sms_password'] ?? ''),
                            $siteId,
                            $siteUrl,
                            'online',
                            true,
                            $notificationBatch
                        );
                    } else {
                        if ($status !== 'online') {
                            if (monitoring_fallback_open_incident($conn, $siteId, $siteUrl, $result, $cfg, $notificationBatch)) {
                                $opened++;
                            }
                        } else {
                            if (monitoring_fallback_close_incident($conn, $siteId, $siteUrl, $cfg, $notificationBatch)) {
                                $closed++;
                                $justClosed = true;
                            }
                        }

                        alertSite(
                            $conn,
                            (string)($cfg['sms_user'] ?? ''),
                            (string)($cfg['sms_password'] ?? ''),
                            $siteId,
                            $siteUrl,
                            $status,
                            $justClosed,
                            $notificationBatch
                        );
                    }
                    $processed++;
                } catch (Throwable $e) {
                    $errors++;
                    error_log('PHP fallback monitor failed for site ' . $siteId . ': ' . $e->getMessage());
                }
            }

            try {
                alert_batch_flush(
                    $notificationBatch,
                    (string)($cfg['sms_user'] ?? ''),
                    (string)($cfg['sms_password'] ?? ''),
                    $conn
                );
            } catch (Throwable $e) {
                error_log('Notification batch indisponible: ' . $e->getMessage());
            }

            $escalationStats = [
                'triggered' => 0,
                'sent' => 0,
                'failed' => 0,
                'skipped' => 0,
                'placeholder' => 0,
            ];
            try {
                $escalationStats = monitoring_fallback_apply_escalation_policy($conn, $cfg);
            } catch (Throwable $e) {
                error_log('Escalade fallback indisponible: ' . $e->getMessage());
            }
            $conn->close();

            return [
                'ok' => true,
                'sites_checked' => $processed,
                'errors' => $errors,
                'incidents_opened' => $opened,
                'incidents_closed' => $closed,
                'sites_under_maintenance' => $underMaintenance,
                'sites_skipped_scheduler' => $skippedScheduler,
                'scheduler' => $scheduler,
                'mode' => 'degraded_php_fallback',
                'escalation' => $escalationStats,
            ];
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'status_code' => 500,
                'message' => 'PHP fallback monitor failed: ' . $e->getMessage(),
            ];
        }
    }
}

if (!function_exists('run_hourly_php_fallback')) {
    function run_hourly_php_fallback(): array
    {
        try {
            $cfg = monitoring_fallback_config();
            $conn = monitoring_fallback_db($cfg);
            monitoring_fallback_ensure_sites_runtime_columns($conn);
            monitoring_fallback_ensure_hourly_calc_columns($conn);
            $calcSettings = monitoring_fallback_calc_settings($conn);

            $sitesRes = $conn->query("SELECT id, calc_method FROM sites");
            if (!$sitesRes) {
                $conn->close();
                return ['ok' => false, 'status_code' => 500, 'message' => 'Failed to fetch sites.'];
            }

            $processed = 0;
            $badData = 0;

            while ($site = $sitesRes->fetch_assoc()) {
                $siteId = (int)($site['id'] ?? 0);
                $siteCalcMethod = (string)($site['calc_method'] ?? 'inherit');
                if ($siteId <= 0) {
                    continue;
                }

                $stmt = $conn->prepare(
                    "SELECT DISTINCT DATE(checked_at) AS date_val, HOUR(checked_at) AS hour_val
                     FROM probes
                     WHERE site_id = ?
                       AND (DATE(checked_at) < CURDATE()
                         OR (DATE(checked_at) = CURDATE() AND HOUR(checked_at) < HOUR(NOW())))
                     ORDER BY DATE(checked_at), HOUR(checked_at)"
                );
                if (!$stmt) {
                    $badData++;
                    continue;
                }
                $stmt->bind_param('i', $siteId);
                $stmt->execute();
                $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();

                foreach ($rows as $row) {
                    $dateVal = (string)($row['date_val'] ?? '');
                    $hourVal = (int)($row['hour_val'] ?? 0);
                    if ($dateVal === '') {
                        $badData++;
                        continue;
                    }

                    try {
                        $slotStartTs = strtotime($dateVal . ' ' . str_pad((string)$hourVal, 2, '0', STR_PAD_LEFT) . ':00:00');
                        if ($slotStartTs === false) {
                            $badData++;
                            continue;
                        }
                        $effectiveCalcMethod = monitoring_fallback_effective_calc_method($siteCalcMethod, (int)$slotStartTs, $calcSettings);
                        $mins = array_fill(0, 60, '0');

                        $mStmt = $conn->prepare(
                            "SELECT MINUTE(checked_at) AS m, status
                             FROM probes
                             WHERE site_id = ? AND DATE(checked_at) = ? AND HOUR(checked_at) = ?
                             ORDER BY MINUTE(checked_at) ASC"
                        );
                        if (!$mStmt) {
                            $badData++;
                            continue;
                        }
                        $mStmt->bind_param('isi', $siteId, $dateVal, $hourVal);
                        $mStmt->execute();
                        $mRows = $mStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                        $mStmt->close();

                        foreach ($mRows as $mr) {
                            $minute = (int)($mr['m'] ?? 0);
                            if ($minute >= 0 && $minute < 60 && (($mr['status'] ?? '') === 'online')) {
                                $mins[$minute] = '1';
                            }
                        }

                        $binary = implode('', $mins);
                        $minutesOfflineLegacy = (int)substr_count($binary, '0');

                        $aStmt = $conn->prepare(
                            "SELECT AVG(response_time) AS avg_response_time
                             FROM probes
                             WHERE site_id = ? AND DATE(checked_at) = ? AND HOUR(checked_at) = ?"
                        );
                        if (!$aStmt) {
                            $badData++;
                            continue;
                        }
                        $aStmt->bind_param('isi', $siteId, $dateVal, $hourVal);
                        $aStmt->execute();
                        $avgRow = $aStmt->get_result()->fetch_assoc();
                        $aStmt->close();

                        $avg = isset($avgRow['avg_response_time']) ? (float)$avgRow['avg_response_time'] : 0.0;
                        $weighted = monitoring_fallback_weighted_hour_metrics($conn, $siteId, $dateVal, $hourVal);

                        $storedMinutesOffline = $minutesOfflineLegacy;
                        $totalSeconds = 3600;
                        $offlineSeconds = max(0, min(3600, $minutesOfflineLegacy * 60));
                        $degradedSeconds = 0;
                        $maintenanceSeconds = 0;
                        $availabilityRatio = round(max(0.0, min(1.0, (3600 - $offlineSeconds) / 3600)), 4);
                        $healthScore = $availabilityRatio;

                        if ($effectiveCalcMethod === 'time_weighted') {
                            $storedMinutesOffline = (int)($weighted['minutes_offline'] ?? 60);
                            $totalSeconds = (int)($weighted['total_seconds'] ?? 3600);
                            $offlineSeconds = (int)($weighted['offline_seconds'] ?? 3600);
                            $degradedSeconds = (int)($weighted['degraded_seconds'] ?? 0);
                            $maintenanceSeconds = (int)($weighted['maintenance_seconds'] ?? 0);
                            $availabilityRatio = array_key_exists('availability_ratio', $weighted) ? $weighted['availability_ratio'] : null;
                            $healthScore = array_key_exists('health_score', $weighted) ? $weighted['health_score'] : null;
                        }

                        $availabilitySql = $availabilityRatio === null ? null : number_format((float)$availabilityRatio, 4, '.', '');
                        $healthSql = $healthScore === null ? null : number_format((float)$healthScore, 4, '.', '');

                        $uStmt = $conn->prepare(
                            "INSERT INTO hourly_stats (site_id, date, hour, avg_response_time, minutes_offline, binary_sequence, total_seconds, offline_seconds, degraded_seconds, maintenance_seconds, availability_ratio, health_score, calc_method, checked_at)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                             ON DUPLICATE KEY UPDATE
                               avg_response_time = VALUES(avg_response_time),
                               minutes_offline = VALUES(minutes_offline),
                               binary_sequence = VALUES(binary_sequence),
                               total_seconds = VALUES(total_seconds),
                               offline_seconds = VALUES(offline_seconds),
                               degraded_seconds = VALUES(degraded_seconds),
                               maintenance_seconds = VALUES(maintenance_seconds),
                               availability_ratio = VALUES(availability_ratio),
                               health_score = VALUES(health_score),
                               calc_method = VALUES(calc_method),
                               checked_at = NOW()"
                        );
                        if (!$uStmt) {
                            $badData++;
                            continue;
                        }
                        $uStmt->bind_param(
                            'isidisiiiisss',
                            $siteId,
                            $dateVal,
                            $hourVal,
                            $avg,
                            $storedMinutesOffline,
                            $binary,
                            $totalSeconds,
                            $offlineSeconds,
                            $degradedSeconds,
                            $maintenanceSeconds,
                            $availabilitySql,
                            $healthSql,
                            $effectiveCalcMethod
                        );
                        $uStmt->execute();
                        $uStmt->close();

                        $processed++;
                    } catch (Throwable $e) {
                        $badData++;
                        error_log('PHP fallback hourly failed: ' . $e->getMessage());
                    }
                }
            }

            $sitesRes->free();
            $conn->close();

            return [
                'ok' => true,
                'processed' => $processed,
                'bad_data' => $badData,
                'calc_settings' => $calcSettings,
                'mode' => 'degraded_php_fallback',
            ];
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'status_code' => 500,
                'message' => 'PHP fallback hourly failed: ' . $e->getMessage(),
            ];
        }
    }
}

if (!function_exists('run_daily_php_fallback')) {
    function run_daily_php_fallback(): array
    {
        try {
            $cfg = monitoring_fallback_config();
            $conn = monitoring_fallback_db($cfg);
            monitoring_fallback_ensure_hourly_calc_columns($conn);
            monitoring_fallback_ensure_daily_calc_columns($conn);

            $sitesRes = $conn->query("SELECT id FROM sites");
            if (!$sitesRes) {
                $conn->close();
                return ['ok' => false, 'status_code' => 500, 'message' => 'Failed to fetch sites.'];
            }

            $processed = 0;
            $badData = 0;

            while ($site = $sitesRes->fetch_assoc()) {
                $siteId = (int)($site['id'] ?? 0);
                if ($siteId <= 0) {
                    continue;
                }

                $dStmt = $conn->prepare(
                    "SELECT DISTINCT DATE(date) AS date_val
                     FROM hourly_stats
                     WHERE site_id = ? AND DATE(date) < CURDATE()
                     ORDER BY DATE(date) ASC"
                );
                if (!$dStmt) {
                    $badData++;
                    continue;
                }
                $dStmt->bind_param('i', $siteId);
                $dStmt->execute();
                $dates = $dStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $dStmt->close();

                foreach ($dates as $dateRow) {
                    $dateVal = (string)($dateRow['date_val'] ?? '');
                    if ($dateVal === '') {
                        $badData++;
                        continue;
                    }

                    try {
                        $aStmt = $conn->prepare(
                            "SELECT
                                AVG(avg_response_time) AS avg_response_time,
                                SUM(minutes_offline) AS total_minutes_offline,
                                SUM(COALESCE(total_seconds, 3600)) AS total_seconds,
                                SUM(COALESCE(offline_seconds, COALESCE(minutes_offline, 0) * 60)) AS offline_seconds,
                                SUM(COALESCE(degraded_seconds, 0)) AS degraded_seconds,
                                SUM(COALESCE(maintenance_seconds, 0)) AS maintenance_seconds,
                                SUM(CASE WHEN COALESCE(calc_method, 'legacy') = 'time_weighted' THEN 1 ELSE 0 END) AS weighted_rows,
                                COUNT(*) AS row_count,
                                SUM(
                                    CASE
                                        WHEN health_score IS NULL THEN 0
                                        ELSE health_score * GREATEST(0, COALESCE(total_seconds, 3600) - COALESCE(maintenance_seconds, 0))
                                    END
                                ) AS weighted_health_numerator,
                                SUM(
                                    CASE
                                        WHEN health_score IS NULL THEN 0
                                        ELSE GREATEST(0, COALESCE(total_seconds, 3600) - COALESCE(maintenance_seconds, 0))
                                    END
                                ) AS weighted_health_denominator
                             FROM hourly_stats
                             WHERE site_id = ? AND DATE(date) = ?"
                        );
                        if (!$aStmt) {
                            $badData++;
                            continue;
                        }
                        $aStmt->bind_param('is', $siteId, $dateVal);
                        $aStmt->execute();
                        $agg = $aStmt->get_result()->fetch_assoc();
                        $aStmt->close();

                        if (!$agg) {
                            $badData++;
                            continue;
                        }

                        $avg = isset($agg['avg_response_time']) ? (float)$agg['avg_response_time'] : 0.0;
                        $offline = isset($agg['total_minutes_offline']) ? (int)$agg['total_minutes_offline'] : 0;
                        $totalSeconds = isset($agg['total_seconds']) ? (int)$agg['total_seconds'] : 0;
                        $offlineSeconds = isset($agg['offline_seconds']) ? (int)$agg['offline_seconds'] : 0;
                        $degradedSeconds = isset($agg['degraded_seconds']) ? (int)$agg['degraded_seconds'] : 0;
                        $maintenanceSeconds = isset($agg['maintenance_seconds']) ? (int)$agg['maintenance_seconds'] : 0;
                        $weightedRows = isset($agg['weighted_rows']) ? (int)$agg['weighted_rows'] : 0;
                        $rowCount = isset($agg['row_count']) ? (int)$agg['row_count'] : 0;
                        $weightedHealthNumerator = isset($agg['weighted_health_numerator']) ? (float)$agg['weighted_health_numerator'] : 0.0;
                        $weightedHealthDenominator = isset($agg['weighted_health_denominator']) ? (float)$agg['weighted_health_denominator'] : 0.0;
                        if ($totalSeconds <= 0 && $rowCount > 0) {
                            $totalSeconds = $rowCount * 3600;
                        }

                        $denominator = $totalSeconds - $maintenanceSeconds;
                        if ($denominator <= 0) {
                            $availabilityRatio = null;
                        } else {
                            $availabilityRatio = round(max(0.0, min(1.0, ($denominator - $offlineSeconds) / $denominator)), 4);
                        }

                        if ($weightedHealthDenominator > 0) {
                            $healthScore = round(max(0.0, min(1.0, $weightedHealthNumerator / $weightedHealthDenominator)), 4);
                        } elseif ($denominator <= 0) {
                            $healthScore = null;
                        } else {
                            $healthScore = $availabilityRatio;
                        }

                        $calcMethod = $weightedRows > 0 ? 'time_weighted' : 'legacy';
                        if ($offline <= 0 && $offlineSeconds > 0) {
                            $offline = (int)round($offlineSeconds / 60);
                        }

                        $availabilitySql = $availabilityRatio === null ? null : number_format((float)$availabilityRatio, 4, '.', '');
                        $healthSql = $healthScore === null ? null : number_format((float)$healthScore, 4, '.', '');

                        $uStmt = $conn->prepare(
                            "INSERT INTO daily_stats (site_id, date, avg_response_time, minutes_offline, total_seconds, offline_seconds, degraded_seconds, maintenance_seconds, availability_ratio, health_score, calc_method, created_at)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                             ON DUPLICATE KEY UPDATE
                               avg_response_time = VALUES(avg_response_time),
                               minutes_offline = VALUES(minutes_offline),
                               total_seconds = VALUES(total_seconds),
                               offline_seconds = VALUES(offline_seconds),
                               degraded_seconds = VALUES(degraded_seconds),
                               maintenance_seconds = VALUES(maintenance_seconds),
                               availability_ratio = VALUES(availability_ratio),
                               health_score = VALUES(health_score),
                               calc_method = VALUES(calc_method),
                               created_at = NOW()"
                        );
                        if (!$uStmt) {
                            $badData++;
                            continue;
                        }
                        $avgRounded = (int)round($avg);
                        $uStmt->bind_param(
                            'isiiiiiisss',
                            $siteId,
                            $dateVal,
                            $avgRounded,
                            $offline,
                            $totalSeconds,
                            $offlineSeconds,
                            $degradedSeconds,
                            $maintenanceSeconds,
                            $availabilitySql,
                            $healthSql,
                            $calcMethod
                        );
                        $uStmt->execute();
                        $uStmt->close();

                        $processed++;
                    } catch (Throwable $e) {
                        $badData++;
                        error_log('PHP fallback daily failed: ' . $e->getMessage());
                    }
                }
            }

            $sitesRes->free();
            $conn->close();

            return [
                'ok' => true,
                'processed' => $processed,
                'bad_data' => $badData,
                'mode' => 'degraded_php_fallback',
            ];
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'status_code' => 500,
                'message' => 'PHP fallback daily failed: ' . $e->getMessage(),
            ];
        }
    }
}
