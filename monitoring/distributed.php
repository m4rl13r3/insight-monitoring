<?php

declare(strict_types=1);

final class InsightDistributedException extends RuntimeException
{
    public function __construct(string $message, public readonly int $statusCode = 400)
    {
        parent::__construct($message);
    }
}

function insight_dist_env(string $name, string $default = ''): string
{
    $value = getenv($name);
    if ($value === false || trim((string)$value) === '') {
        return $default;
    }
    return trim((string)$value);
}

function insight_dist_env_int(string $name, int $default, int $minimum, int $maximum): int
{
    $value = filter_var(insight_dist_env($name, (string)$default), FILTER_VALIDATE_INT);
    if ($value === false) {
        return $default;
    }
    return max($minimum, min($maximum, (int)$value));
}

function insight_dist_env_bool(string $name, bool $default = false): bool
{
    $value = strtolower(insight_dist_env($name, $default ? '1' : '0'));
    return in_array($value, ['1', 'true', 'yes', 'on'], true);
}

function insight_dist_format_unix_milliseconds(float $timestamp): string
{
    $date = DateTimeImmutable::createFromFormat('U.u', number_format($timestamp, 6, '.', ''));
    if (!$date instanceof DateTimeImmutable) {
        throw new RuntimeException('Conversion d’horodatage distribuée impossible.');
    }
    return $date
        ->setTimezone(new DateTimeZone(date_default_timezone_get()))
        ->format('Y-m-d H:i:s.v');
}

function insight_dist_execute(mysqli $connection, string $sql, array $params = []): mysqli_stmt
{
    $statement = $connection->prepare($sql);
    if (!$statement) {
        throw new RuntimeException('Préparation SQL distribuée impossible: ' . $connection->error);
    }
    if ($params !== []) {
        $types = '';
        $values = [];
        foreach ($params as $value) {
            if (is_int($value) || is_bool($value)) {
                $types .= 'i';
                $values[] = (int)$value;
            } elseif (is_float($value)) {
                $types .= 'd';
                $values[] = $value;
            } else {
                $types .= 's';
                $values[] = $value;
            }
        }
        $references = [$types];
        foreach ($values as $index => $value) {
            $references[] = &$values[$index];
        }
        call_user_func_array([$statement, 'bind_param'], $references);
    }
    if (!$statement->execute()) {
        $message = $statement->error;
        $statement->close();
        throw new RuntimeException('Exécution SQL distribuée impossible: ' . $message);
    }
    return $statement;
}

function insight_dist_query_all(mysqli $connection, string $sql, array $params = []): array
{
    $statement = insight_dist_execute($connection, $sql, $params);
    $result = $statement->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $statement->close();
    return is_array($rows) ? $rows : [];
}

function insight_dist_query_one(mysqli $connection, string $sql, array $params = []): ?array
{
    $rows = insight_dist_query_all($connection, $sql, $params);
    return isset($rows[0]) && is_array($rows[0]) ? $rows[0] : null;
}

function insight_dist_column_exists(mysqli $connection, string $table, string $column): bool
{
    $row = insight_dist_query_one(
        $connection,
        'SELECT 1 AS present FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
        [$table, $column]
    );
    return $row !== null;
}

function insight_dist_ensure_schema(mysqli $connection): void
{
    $queries = [
        "CREATE TABLE IF NOT EXISTS monitoring_nodes (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            node_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            display_name VARCHAR(120) NOT NULL,
            region VARCHAR(64) NULL,
            zone VARCHAR(64) NULL,
            version VARCHAR(32) NULL,
            status ENUM('active','paused','revoked') NOT NULL DEFAULT 'active',
            capabilities JSON NULL,
            connectivity_status ENUM('online','offline','unknown') NOT NULL DEFAULT 'unknown',
            clock_skew_ms INT NOT NULL DEFAULT 0,
            last_ip_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
            first_seen_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
            last_seen_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
            last_config_at DATETIME(3) NULL,
            updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
            PRIMARY KEY (id),
            UNIQUE KEY uniq_monitoring_nodes_key (node_key),
            KEY idx_monitoring_nodes_status_seen (status, last_seen_at),
            KEY idx_monitoring_nodes_region (region, zone)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS monitoring_assignments (
            site_id INT NOT NULL,
            node_id BIGINT UNSIGNED NOT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            assigned_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
            updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
            PRIMARY KEY (site_id, node_id),
            KEY idx_monitoring_assignments_node (node_id, active),
            KEY idx_monitoring_assignments_active (active, site_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS monitoring_agent_requests (
            node_id BIGINT UNSIGNED NOT NULL,
            nonce_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            received_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
            PRIMARY KEY (node_id, nonce_hash),
            KEY idx_monitoring_agent_requests_received (received_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS monitoring_agent_batches (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            node_id BIGINT UNSIGNED NOT NULL,
            batch_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            payload_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            sample_count INT UNSIGNED NOT NULL DEFAULT 0,
            accepted_count INT UNSIGNED NOT NULL DEFAULT 0,
            duplicate_count INT UNSIGNED NOT NULL DEFAULT 0,
            rejected_count INT UNSIGNED NOT NULL DEFAULT 0,
            received_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
            PRIMARY KEY (id),
            UNIQUE KEY uniq_monitoring_agent_batch (node_id, batch_id),
            KEY idx_monitoring_agent_batches_received (received_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS monitoring_observations (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            site_id INT NOT NULL,
            node_id BIGINT UNSIGNED NOT NULL,
            sample_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            batch_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
            status ENUM('online','offline','degraded','unknown') NOT NULL DEFAULT 'unknown',
            response_time_ms DECIMAL(12,3) NULL,
            http_code INT NULL,
            error_code VARCHAR(64) NULL,
            error_message VARCHAR(255) NULL,
            metadata JSON NULL,
            observed_at DATETIME(3) NOT NULL,
            received_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
            PRIMARY KEY (id),
            UNIQUE KEY uniq_monitoring_observation_sample (node_id, sample_id),
            KEY idx_monitoring_observations_site_time (site_id, observed_at),
            KEY idx_monitoring_observations_node_time (node_id, observed_at),
            KEY idx_monitoring_observations_received (received_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS monitoring_consensus_current (
            site_id INT NOT NULL,
            status ENUM('online','offline','degraded','unknown') NOT NULL DEFAULT 'unknown',
            nodes_expected SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            nodes_fresh SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            nodes_online SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            nodes_offline SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            nodes_degraded SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            nodes_missing SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            success_quorum SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            failure_quorum SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            confidence DECIMAL(8,5) NOT NULL DEFAULT 0,
            response_median_ms DECIMAL(12,3) NULL,
            response_p95_ms DECIMAL(12,3) NULL,
            window_started_at DATETIME(3) NULL,
            last_observation_at DATETIME(3) NULL,
            evaluated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
            PRIMARY KEY (site_id),
            KEY idx_monitoring_consensus_status (status, evaluated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS monitoring_consensus_snapshots (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            site_id INT NOT NULL,
            bucket_at DATETIME NOT NULL,
            status ENUM('online','offline','degraded','unknown') NOT NULL DEFAULT 'unknown',
            nodes_expected SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            nodes_fresh SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            nodes_online SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            nodes_offline SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            nodes_degraded SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            nodes_missing SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            success_quorum SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            failure_quorum SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            confidence DECIMAL(8,5) NOT NULL DEFAULT 0,
            response_median_ms DECIMAL(12,3) NULL,
            response_p95_ms DECIMAL(12,3) NULL,
            evaluated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
            PRIMARY KEY (id),
            UNIQUE KEY uniq_monitoring_consensus_bucket (site_id, bucket_at),
            KEY idx_monitoring_consensus_snapshots_bucket (bucket_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
    foreach ($queries as $query) {
        if (!$connection->query($query)) {
            throw new RuntimeException('Création du schéma distribué impossible: ' . $connection->error);
        }
    }
    $columns = [
        'probe_replication_factor' => 'SMALLINT UNSIGNED NOT NULL DEFAULT 0',
        'probe_success_quorum' => 'SMALLINT UNSIGNED NOT NULL DEFAULT 0',
        'probe_failure_quorum' => 'SMALLINT UNSIGNED NOT NULL DEFAULT 0',
    ];
    foreach ($columns as $column => $definition) {
        if (!insight_dist_column_exists($connection, 'sites', $column)) {
            if (!$connection->query("ALTER TABLE sites ADD COLUMN {$column} {$definition}")) {
                throw new RuntimeException('Migration du schéma distribué impossible: ' . $connection->error);
            }
        }
    }
}

function insight_dist_validate_node_key(string $nodeKey): string
{
    $normalized = strtolower(trim($nodeKey));
    if (preg_match('/^[a-z0-9][a-z0-9._-]{2,63}$/', $normalized) !== 1) {
        throw new InsightDistributedException('Identifiant de nœud invalide.', 400);
    }
    return $normalized;
}

function insight_dist_master_secret(): string
{
    $secret = insight_dist_env('INSIGHT_AGENT_MASTER_SECRET');
    if (strlen($secret) < 32) {
        throw new InsightDistributedException('INSIGHT_AGENT_MASTER_SECRET doit contenir au moins 32 caractères.', 503);
    }
    return $secret;
}

function insight_dist_derive_node_secret(string $nodeKey, ?string $masterSecret = null): string
{
    $key = insight_dist_validate_node_key($nodeKey);
    $master = $masterSecret ?? insight_dist_master_secret();
    return hash_hmac('sha256', 'insight-agent-v1:' . $key, $master);
}

function insight_dist_signature_payload(
    string $nodeKey,
    string $timestamp,
    string $nonce,
    string $rawBody
): string {
    return "v1\n{$nodeKey}\n{$timestamp}\n{$nonce}\n" . hash('sha256', $rawBody);
}

function insight_dist_verify_signature(
    string $nodeKey,
    string $timestamp,
    string $nonce,
    string $signature,
    string $rawBody
): void {
    $key = insight_dist_validate_node_key($nodeKey);
    if (!ctype_digit($timestamp)) {
        throw new InsightDistributedException('Horodatage agent invalide.', 401);
    }
    if (preg_match('/^[A-Za-z0-9._:-]{16,96}$/', $nonce) !== 1) {
        throw new InsightDistributedException('Nonce agent invalide.', 401);
    }
    if (preg_match('/^[a-f0-9]{64}$/i', $signature) !== 1) {
        throw new InsightDistributedException('Signature agent invalide.', 401);
    }
    $window = insight_dist_env_int('INSIGHT_AGENT_HMAC_WINDOW_SEC', 300, 30, 3600);
    if (abs(time() - (int)$timestamp) > $window) {
        throw new InsightDistributedException('Signature agent expirée.', 401);
    }
    $secret = insight_dist_derive_node_secret($key);
    $expected = hash_hmac('sha256', insight_dist_signature_payload($key, $timestamp, $nonce, $rawBody), $secret);
    if (!hash_equals($expected, strtolower($signature))) {
        throw new InsightDistributedException('Signature agent invalide.', 401);
    }
}

function insight_dist_clean_text(mixed $value, int $maximum): ?string
{
    $text = trim((string)$value);
    if ($text === '') {
        return null;
    }
    return mb_substr($text, 0, $maximum, 'UTF-8');
}

function insight_dist_client_ip_hash(string $masterSecret): string
{
    $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    return hash_hmac('sha256', $ip, $masterSecret);
}

function insight_dist_register_node(mysqli $connection, string $nodeKey, array $body): array
{
    $key = insight_dist_validate_node_key($nodeKey);
    $existing = insight_dist_query_one($connection, 'SELECT * FROM monitoring_nodes WHERE node_key = ? LIMIT 1', [$key]);
    if ($existing === null && !insight_dist_env_bool('INSIGHT_AGENT_AUTO_REGISTER', true)) {
        throw new InsightDistributedException('Enregistrement automatique des nœuds désactivé.', 403);
    }
    if (($existing['status'] ?? '') === 'revoked') {
        throw new InsightDistributedException('Ce nœud a été révoqué.', 403);
    }
    $node = isset($body['node']) && is_array($body['node']) ? $body['node'] : [];
    $displayName = insight_dist_clean_text($node['display_name'] ?? $key, 120) ?? $key;
    $region = insight_dist_clean_text($node['region'] ?? null, 64);
    $zone = insight_dist_clean_text($node['zone'] ?? null, 64);
    $version = insight_dist_clean_text($node['version'] ?? null, 32);
    $connectivity = strtolower(trim((string)($node['connectivity_status'] ?? 'unknown')));
    if (!in_array($connectivity, ['online', 'offline', 'unknown'], true)) {
        $connectivity = 'unknown';
    }
    $capabilities = isset($node['capabilities']) && is_array($node['capabilities']) ? $node['capabilities'] : [];
    $capabilitiesJson = json_encode($capabilities, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($capabilitiesJson) || strlen($capabilitiesJson) > 8192) {
        $capabilitiesJson = '{}';
    }
    $sentAtMs = filter_var($body['sent_at_ms'] ?? null, FILTER_VALIDATE_INT);
    $clockSkew = $sentAtMs === false || $sentAtMs === null
        ? 0
        : max(-2147483648, min(2147483647, (int)round((microtime(true) * 1000) - (int)$sentAtMs)));
    $ipHash = insight_dist_client_ip_hash(insight_dist_master_secret());
    $statement = insight_dist_execute(
        $connection,
        "INSERT INTO monitoring_nodes
            (node_key, display_name, region, zone, version, capabilities, connectivity_status, clock_skew_ms, last_ip_hash)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            display_name = VALUES(display_name),
            region = VALUES(region),
            zone = VALUES(zone),
            version = VALUES(version),
            capabilities = VALUES(capabilities),
            connectivity_status = VALUES(connectivity_status),
            clock_skew_ms = VALUES(clock_skew_ms),
            last_ip_hash = VALUES(last_ip_hash),
            last_seen_at = CURRENT_TIMESTAMP(3),
            updated_at = CURRENT_TIMESTAMP(3)",
        [$key, $displayName, $region, $zone, $version, $capabilitiesJson, $connectivity, $clockSkew, $ipHash]
    );
    $statement->close();
    $registered = insight_dist_query_one($connection, 'SELECT * FROM monitoring_nodes WHERE node_key = ? LIMIT 1', [$key]);
    if ($registered === null) {
        throw new RuntimeException('Enregistrement du nœud impossible.');
    }
    return $registered;
}

function insight_dist_remember_nonce(mysqli $connection, int $nodeId, string $nonce): void
{
    $nonceHash = hash('sha256', $nonce);
    try {
        $statement = insight_dist_execute(
            $connection,
            'INSERT INTO monitoring_agent_requests (node_id, nonce_hash) VALUES (?, ?)',
            [$nodeId, $nonceHash]
        );
        $statement->close();
    } catch (Throwable $exception) {
        if ((int)$connection->errno === 1062 || str_contains(strtolower($exception->getMessage()), 'duplicate')) {
            throw new InsightDistributedException('Requête agent déjà reçue.', 409);
        }
        throw $exception;
    }
    $retention = insight_dist_env_int('INSIGHT_AGENT_NONCE_RETENTION_SEC', 86400, 3600, 604800);
    $cutoff = date('Y-m-d H:i:s', time() - $retention);
    $statement = insight_dist_execute($connection, 'DELETE FROM monitoring_agent_requests WHERE received_at < ?', [$cutoff]);
    $statement->close();
}

function insight_dist_rendezvous_nodes(int $siteId, array $nodes, int $desired): array
{
    if ($nodes === []) {
        return [];
    }
    $limit = $desired <= 0 ? count($nodes) : min(count($nodes), $desired);
    $scored = [];
    foreach ($nodes as $node) {
        $nodeKey = (string)($node['node_key'] ?? '');
        if ($nodeKey === '') {
            continue;
        }
        $node['score'] = hash('sha256', 'insight-assignment-v1|' . $siteId . '|' . $nodeKey);
        $scored[] = $node;
    }
    usort($scored, static function (array $left, array $right): int {
        $byScore = strcmp((string)$right['score'], (string)$left['score']);
        if ($byScore !== 0) {
            return $byScore;
        }
        return strcmp((string)$left['node_key'], (string)$right['node_key']);
    });
    return array_slice($scored, 0, $limit);
}

function insight_dist_refresh_assignments(mysqli $connection): array
{
    $nodes = insight_dist_query_all(
        $connection,
        "SELECT id, node_key FROM monitoring_nodes WHERE status = 'active' ORDER BY node_key"
    );
    $sites = insight_dist_query_all(
        $connection,
        'SELECT id, probe_replication_factor FROM sites ORDER BY id'
    );
    $defaultReplicas = insight_dist_env_int('INSIGHT_AGENT_DEFAULT_REPLICAS', 3, 0, 1000);
    $pairs = [];
    foreach ($sites as $site) {
        $siteId = (int)($site['id'] ?? 0);
        if ($siteId <= 0) {
            continue;
        }
        $configured = (int)($site['probe_replication_factor'] ?? 0);
        $desired = $configured > 0 ? $configured : $defaultReplicas;
        foreach (insight_dist_rendezvous_nodes($siteId, $nodes, $desired) as $node) {
            $pairs[] = [$siteId, (int)$node['id']];
        }
    }
    $connection->begin_transaction();
    try {
        if (!$connection->query('UPDATE monitoring_assignments SET active = 0 WHERE active <> 0')) {
            throw new RuntimeException($connection->error);
        }
        foreach ($pairs as [$siteId, $nodeId]) {
            $statement = insight_dist_execute(
                $connection,
                'INSERT INTO monitoring_assignments (site_id, node_id, active) VALUES (?, ?, 1)
                 ON DUPLICATE KEY UPDATE active = 1, updated_at = CURRENT_TIMESTAMP(3)',
                [$siteId, $nodeId]
            );
            $statement->close();
        }
        $connection->commit();
    } catch (Throwable $exception) {
        $connection->rollback();
        throw $exception;
    }
    return [
        'nodes' => count($nodes),
        'sites' => count($sites),
        'assignments' => count($pairs),
    ];
}

function insight_dist_config_for_node(mysqli $connection, array $node): array
{
    insight_dist_refresh_assignments($connection);
    $nodeId = (int)$node['id'];
    $rows = insight_dist_query_all(
        $connection,
        "SELECT
            s.id,
            s.url,
            s.probe_type,
            s.probe_interval_sec,
            s.http_methods,
            s.http_redirect_modes,
            s.http_primary_method,
            s.http_primary_redirect,
            s.probe_success_quorum,
            s.probe_failure_quorum
         FROM monitoring_assignments a
         INNER JOIN sites s ON s.id = a.site_id
         WHERE a.node_id = ? AND a.active = 1
         ORDER BY s.id",
        [$nodeId]
    );
    $targets = [];
    foreach ($rows as $row) {
        $targets[] = [
            'site_id' => (int)$row['id'],
            'url' => (string)$row['url'],
            'probe_type' => (string)($row['probe_type'] ?? 'http'),
            'interval_sec' => max(10, (int)($row['probe_interval_sec'] ?? 60)),
            'http_methods' => (string)($row['http_methods'] ?? 'GET'),
            'http_redirect_modes' => (string)($row['http_redirect_modes'] ?? 'follow'),
            'http_primary_method' => (string)($row['http_primary_method'] ?? 'GET'),
            'http_primary_redirect' => (string)($row['http_primary_redirect'] ?? 'follow'),
            'success_quorum' => (int)($row['probe_success_quorum'] ?? 0),
            'failure_quorum' => (int)($row['probe_failure_quorum'] ?? 0),
        ];
    }
    $configHash = hash('sha256', json_encode($targets, JSON_UNESCAPED_SLASHES) ?: '[]');
    $statement = insight_dist_execute(
        $connection,
        'UPDATE monitoring_nodes SET last_config_at = CURRENT_TIMESTAMP(3), last_seen_at = CURRENT_TIMESTAMP(3) WHERE id = ?',
        [$nodeId]
    );
    $statement->close();
    return [
        'config_version' => $configHash,
        'generated_at' => gmdate('c'),
        'refresh_after_sec' => insight_dist_env_int('INSIGHT_AGENT_CONFIG_REFRESH_SEC', 60, 10, 3600),
        'batch_size' => insight_dist_env_int('INSIGHT_AGENT_BATCH_SIZE', 200, 1, 1000),
        'targets' => $targets,
    ];
}

function insight_dist_parse_observed_at(mixed $value): string
{
    $raw = trim((string)$value);
    if ($raw === '') {
        throw new InvalidArgumentException('Horodatage manquant.');
    }
    try {
        $date = new DateTimeImmutable($raw);
    } catch (Throwable) {
        throw new InvalidArgumentException('Horodatage invalide.');
    }
    $timestamp = $date->getTimestamp();
    $maxAgeDays = insight_dist_env_int('INSIGHT_AGENT_MAX_SAMPLE_AGE_DAYS', 7, 1, 90);
    if ($timestamp < time() - ($maxAgeDays * 86400) || $timestamp > time() + 300) {
        throw new InvalidArgumentException('Horodatage hors fenêtre.');
    }
    return $date->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s.v');
}

function insight_dist_clean_metadata(mixed $value): ?string
{
    if (!is_array($value) || $value === []) {
        return null;
    }
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json) || strlen($json) > 8192) {
        return null;
    }
    return $json;
}

function insight_dist_percentile(array $values, float $percentile): ?float
{
    if ($values === []) {
        return null;
    }
    sort($values, SORT_NUMERIC);
    $count = count($values);
    $index = max(0, min($count - 1, (int)ceil($percentile * $count) - 1));
    return round((float)$values[$index], 3);
}

function insight_dist_consensus_from_observations(
    array $observations,
    int $expected,
    int $configuredSuccessQuorum = 0,
    int $configuredFailureQuorum = 0
): array {
    $expected = max(0, $expected);
    $majority = max(1, intdiv($expected, 2) + 1);
    $successQuorum = $configuredSuccessQuorum > 0
        ? min(max(1, $configuredSuccessQuorum), max(1, $expected))
        : $majority;
    $failureQuorum = $configuredFailureQuorum > 0
        ? min(max(1, $configuredFailureQuorum), max(1, $expected))
        : $majority;
    $counts = ['online' => 0, 'offline' => 0, 'degraded' => 0];
    $responseTimes = [];
    $lastObservation = null;
    foreach ($observations as $observation) {
        $status = strtolower(trim((string)($observation['status'] ?? 'unknown')));
        if (isset($counts[$status])) {
            $counts[$status]++;
        }
        if (in_array($status, ['online', 'degraded'], true) && is_numeric($observation['response_time_ms'] ?? null)) {
            $responseTimes[] = max(0.0, (float)$observation['response_time_ms']);
        }
        $observedAt = trim((string)($observation['observed_at'] ?? ''));
        if ($observedAt !== '' && ($lastObservation === null || $observedAt > $lastObservation)) {
            $lastObservation = $observedAt;
        }
    }
    $fresh = array_sum($counts);
    $missing = max(0, $expected - $fresh);
    if ($expected <= 0 || $fresh <= 0) {
        $status = 'unknown';
    } elseif ($counts['offline'] >= $failureQuorum) {
        $status = 'offline';
    } elseif (
        $counts['online'] >= $successQuorum
        && $counts['offline'] === 0
        && $counts['degraded'] === 0
    ) {
        $status = 'online';
    } elseif ($fresh < min($successQuorum, $failureQuorum)) {
        $status = 'unknown';
    } else {
        $status = 'degraded';
    }
    $winner = match ($status) {
        'online' => $counts['online'],
        'offline' => $counts['offline'],
        'degraded' => max($counts),
        default => 0,
    };
    $confidence = $expected > 0 ? round($winner / $expected, 5) : 0.0;
    return [
        'status' => $status,
        'nodes_expected' => $expected,
        'nodes_fresh' => $fresh,
        'nodes_online' => $counts['online'],
        'nodes_offline' => $counts['offline'],
        'nodes_degraded' => $counts['degraded'],
        'nodes_missing' => $missing,
        'success_quorum' => $successQuorum,
        'failure_quorum' => $failureQuorum,
        'confidence' => $confidence,
        'response_median_ms' => insight_dist_percentile($responseTimes, 0.5),
        'response_p95_ms' => insight_dist_percentile($responseTimes, 0.95),
        'last_observation_at' => $lastObservation,
    ];
}

function insight_dist_recent_snapshot_statuses(mysqli $connection, int $siteId, int $limit): array
{
    $limit = max(1, min(20, $limit));
    $rows = insight_dist_query_all(
        $connection,
        "SELECT status FROM monitoring_consensus_snapshots WHERE site_id = ? ORDER BY bucket_at DESC LIMIT {$limit}",
        [$siteId]
    );
    return array_map(static fn(array $row): string => (string)($row['status'] ?? 'unknown'), $rows);
}

function insight_dist_update_incident(
    mysqli $connection,
    int $siteId,
    string $siteUrl,
    array $consensus,
    string $bucketAt
): void {
    if (!insight_dist_env_bool('INSIGHT_DISTRIBUTED_INCIDENTS', true)) {
        return;
    }
    $status = (string)$consensus['status'];
    if (!in_array($status, ['online', 'offline'], true)) {
        return;
    }
    $required = $status === 'offline'
        ? insight_dist_env_int('INSIGHT_CONSENSUS_FAILURE_WINDOWS', 2, 1, 20)
        : insight_dist_env_int('INSIGHT_CONSENSUS_RECOVERY_WINDOWS', 2, 1, 20);
    $statuses = insight_dist_recent_snapshot_statuses($connection, $siteId, $required);
    if (count($statuses) < $required || count(array_filter($statuses, static fn(string $value): bool => $value === $status)) !== $required) {
        return;
    }
    $open = insight_dist_query_one(
        $connection,
        'SELECT id FROM incidents WHERE site_id = ? AND ended_at IS NULL AND (resolved IS NULL OR resolved = 0) ORDER BY started_at DESC LIMIT 1',
        [$siteId]
    );
    if ($status === 'offline' && $open === null) {
        $incidentCode = 'DST-' . $siteId . '-' . gmdate('YmdHis', strtotime($bucketAt) ?: time());
        $statement = insight_dist_execute(
            $connection,
            "INSERT INTO incidents
                (site_id, incident_code, started_at, incident_date, source_mode, site_label, resolved, status)
             VALUES (?, ?, ?, ?, 'system', ?, 0, 0)",
            [$siteId, $incidentCode, $bucketAt, $bucketAt, $siteUrl]
        );
        $statement->close();
    } elseif ($status === 'online' && $open !== null) {
        $statement = insight_dist_execute(
            $connection,
            'UPDATE incidents SET ended_at = ?, resolved = 1, status = 1, updated_at = CURRENT_TIMESTAMP WHERE id = ?',
            [$bucketAt, (int)$open['id']]
        );
        $statement->close();
    }
}

function insight_dist_evaluate_site(mysqli $connection, int $siteId): array
{
    $site = insight_dist_query_one(
        $connection,
        'SELECT id, url, probe_interval_sec, probe_success_quorum, probe_failure_quorum FROM sites WHERE id = ? LIMIT 1',
        [$siteId]
    );
    if ($site === null) {
        throw new InvalidArgumentException('Site distribué introuvable.');
    }
    $interval = max(10, (int)($site['probe_interval_sec'] ?? 60));
    $baseFreshness = insight_dist_env_int('INSIGHT_CONSENSUS_FRESHNESS_SEC', 180, 30, 86400);
    $freshness = max($baseFreshness, $interval * 3);
    $cutoff = insight_dist_format_unix_milliseconds(microtime(true) - $freshness);
    $rows = insight_dist_query_all(
        $connection,
        "SELECT
            a.node_id,
            o.status,
            o.response_time_ms,
            o.observed_at
         FROM monitoring_assignments a
         LEFT JOIN monitoring_observations o ON o.id = (
            SELECT o2.id
            FROM monitoring_observations o2
            WHERE o2.site_id = a.site_id AND o2.node_id = a.node_id
            ORDER BY o2.observed_at DESC, o2.id DESC
            LIMIT 1
         )
         WHERE a.site_id = ? AND a.active = 1
         ORDER BY a.node_id",
        [$siteId]
    );
    $freshRows = [];
    foreach ($rows as $row) {
        $observedAt = trim((string)($row['observed_at'] ?? ''));
        if ($observedAt !== '' && $observedAt >= $cutoff) {
            $freshRows[] = $row;
        }
    }
    $consensus = insight_dist_consensus_from_observations(
        $freshRows,
        count($rows),
        (int)($site['probe_success_quorum'] ?? 0),
        (int)($site['probe_failure_quorum'] ?? 0)
    );
    $bucketSeconds = insight_dist_env_int('INSIGHT_CONSENSUS_BUCKET_SEC', 60, 10, 3600);
    $bucketEpoch = intdiv(time(), $bucketSeconds) * $bucketSeconds;
    $bucketAt = date('Y-m-d H:i:s', $bucketEpoch);
    $params = [
        $siteId,
        $consensus['status'],
        $consensus['nodes_expected'],
        $consensus['nodes_fresh'],
        $consensus['nodes_online'],
        $consensus['nodes_offline'],
        $consensus['nodes_degraded'],
        $consensus['nodes_missing'],
        $consensus['success_quorum'],
        $consensus['failure_quorum'],
        $consensus['confidence'],
        $consensus['response_median_ms'],
        $consensus['response_p95_ms'],
        $cutoff,
        $consensus['last_observation_at'],
    ];
    $statement = insight_dist_execute(
        $connection,
        "INSERT INTO monitoring_consensus_current
            (site_id, status, nodes_expected, nodes_fresh, nodes_online, nodes_offline, nodes_degraded, nodes_missing, success_quorum, failure_quorum, confidence, response_median_ms, response_p95_ms, window_started_at, last_observation_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            status = VALUES(status),
            nodes_expected = VALUES(nodes_expected),
            nodes_fresh = VALUES(nodes_fresh),
            nodes_online = VALUES(nodes_online),
            nodes_offline = VALUES(nodes_offline),
            nodes_degraded = VALUES(nodes_degraded),
            nodes_missing = VALUES(nodes_missing),
            success_quorum = VALUES(success_quorum),
            failure_quorum = VALUES(failure_quorum),
            confidence = VALUES(confidence),
            response_median_ms = VALUES(response_median_ms),
            response_p95_ms = VALUES(response_p95_ms),
            window_started_at = VALUES(window_started_at),
            last_observation_at = VALUES(last_observation_at),
            evaluated_at = CURRENT_TIMESTAMP(3)",
        $params
    );
    $statement->close();
    $snapshotParams = array_merge([$siteId, $bucketAt], array_slice($params, 1, 12));
    $statement = insight_dist_execute(
        $connection,
        "INSERT INTO monitoring_consensus_snapshots
            (site_id, bucket_at, status, nodes_expected, nodes_fresh, nodes_online, nodes_offline, nodes_degraded, nodes_missing, success_quorum, failure_quorum, confidence, response_median_ms, response_p95_ms)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            status = VALUES(status),
            nodes_expected = VALUES(nodes_expected),
            nodes_fresh = VALUES(nodes_fresh),
            nodes_online = VALUES(nodes_online),
            nodes_offline = VALUES(nodes_offline),
            nodes_degraded = VALUES(nodes_degraded),
            nodes_missing = VALUES(nodes_missing),
            success_quorum = VALUES(success_quorum),
            failure_quorum = VALUES(failure_quorum),
            confidence = VALUES(confidence),
            response_median_ms = VALUES(response_median_ms),
            response_p95_ms = VALUES(response_p95_ms),
            evaluated_at = CURRENT_TIMESTAMP(3)",
        $snapshotParams
    );
    $statement->close();
    $sourceNode = 'consensus:' . $siteId;
    $statement = insight_dist_execute(
        $connection,
        "INSERT INTO probes
            (site_id, probe_type, status, response_time, http_code, checked_by, checked_at, source_node, source_probe_id)
         VALUES (?, 'distributed', ?, ?, NULL, 'dst', ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            status = VALUES(status),
            response_time = VALUES(response_time),
            checked_at = VALUES(checked_at)",
        [$siteId, $consensus['status'], $consensus['response_median_ms'], $bucketAt, $sourceNode, $bucketEpoch]
    );
    $statement->close();
    insight_dist_update_incident($connection, $siteId, (string)$site['url'], $consensus, $bucketAt);
    return array_merge($consensus, ['site_id' => $siteId, 'bucket_at' => $bucketAt]);
}

function insight_dist_evaluate_all(mysqli $connection): array
{
    insight_dist_refresh_assignments($connection);
    $sites = insight_dist_query_all($connection, 'SELECT id FROM sites ORDER BY id');
    $results = [];
    foreach ($sites as $site) {
        $siteId = (int)($site['id'] ?? 0);
        if ($siteId > 0) {
            $results[] = insight_dist_evaluate_site($connection, $siteId);
        }
    }
    return $results;
}

function insight_dist_ingest_batch(
    mysqli $connection,
    array $node,
    array $body,
    string $payloadSha256
): array {
    $nodeId = (int)$node['id'];
    $batchId = strtolower(trim((string)($body['batch_id'] ?? '')));
    if (preg_match('/^[a-f0-9-]{16,64}$/', $batchId) !== 1) {
        throw new InsightDistributedException('Identifiant de lot invalide.', 422);
    }
    $observations = $body['observations'] ?? null;
    if (!is_array($observations)) {
        throw new InsightDistributedException('Liste d’observations invalide.', 422);
    }
    $maximum = insight_dist_env_int('INSIGHT_AGENT_BATCH_SIZE', 200, 1, 1000);
    if (count($observations) > $maximum) {
        throw new InsightDistributedException('Lot d’observations trop volumineux.', 413);
    }
    $existing = insight_dist_query_one(
        $connection,
        'SELECT payload_sha256, accepted_count, duplicate_count, rejected_count FROM monitoring_agent_batches WHERE node_id = ? AND batch_id = ? LIMIT 1',
        [$nodeId, $batchId]
    );
    if ($existing !== null) {
        if (!hash_equals((string)$existing['payload_sha256'], $payloadSha256)) {
            throw new InsightDistributedException('Cet identifiant de lot désigne déjà un autre contenu.', 409);
        }
        return [
            'batch_id' => $batchId,
            'accepted' => (int)$existing['accepted_count'],
            'duplicates' => (int)$existing['duplicate_count'],
            'rejected' => (int)$existing['rejected_count'],
            'already_processed' => true,
            'consensus' => [],
        ];
    }
    $siteRows = insight_dist_query_all($connection, 'SELECT id FROM sites');
    $validSites = [];
    foreach ($siteRows as $siteRow) {
        $validSites[(int)$siteRow['id']] = true;
    }
    $accepted = 0;
    $duplicates = 0;
    $rejected = 0;
    $errors = [];
    $affectedSites = [];
    $connection->begin_transaction();
    try {
        foreach ($observations as $index => $observation) {
            try {
                if (!is_array($observation)) {
                    throw new InvalidArgumentException('Observation non structurée.');
                }
                $sampleId = strtolower(trim((string)($observation['sample_id'] ?? '')));
                if (preg_match('/^[a-z0-9-]{16,64}$/', $sampleId) !== 1) {
                    throw new InvalidArgumentException('Identifiant d’échantillon invalide.');
                }
                $siteId = (int)($observation['site_id'] ?? 0);
                if ($siteId <= 0 || !isset($validSites[$siteId])) {
                    throw new InvalidArgumentException('Cible inconnue.');
                }
                $status = strtolower(trim((string)($observation['status'] ?? 'unknown')));
                if (!in_array($status, ['online', 'offline', 'degraded', 'unknown'], true)) {
                    throw new InvalidArgumentException('Statut invalide.');
                }
                $observedAt = insight_dist_parse_observed_at($observation['observed_at'] ?? null);
                $responseTime = is_numeric($observation['response_time_ms'] ?? null)
                    ? round(max(0.0, min(3600000.0, (float)$observation['response_time_ms'])), 3)
                    : null;
                $httpCode = filter_var($observation['http_code'] ?? null, FILTER_VALIDATE_INT);
                $httpCode = $httpCode === false ? null : max(0, min(999, (int)$httpCode));
                $errorCode = insight_dist_clean_text($observation['error_code'] ?? null, 64);
                $errorMessage = insight_dist_clean_text($observation['error_message'] ?? null, 255);
                $metadata = insight_dist_clean_metadata($observation['metadata'] ?? null);
                $statement = insight_dist_execute(
                    $connection,
                    'INSERT IGNORE INTO monitoring_observations
                        (site_id, node_id, sample_id, batch_id, status, response_time_ms, http_code, error_code, error_message, metadata, observed_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$siteId, $nodeId, $sampleId, $batchId, $status, $responseTime, $httpCode, $errorCode, $errorMessage, $metadata, $observedAt]
                );
                if ($statement->affected_rows > 0) {
                    $accepted++;
                    $affectedSites[$siteId] = true;
                } else {
                    $duplicates++;
                }
                $statement->close();
            } catch (Throwable $exception) {
                $rejected++;
                if (count($errors) < 20) {
                    $errors[] = ['index' => $index, 'message' => $exception->getMessage()];
                }
            }
        }
        $statement = insight_dist_execute(
            $connection,
            'INSERT INTO monitoring_agent_batches
                (node_id, batch_id, payload_sha256, sample_count, accepted_count, duplicate_count, rejected_count)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$nodeId, $batchId, $payloadSha256, count($observations), $accepted, $duplicates, $rejected]
        );
        $statement->close();
        $connection->commit();
    } catch (Throwable $exception) {
        $connection->rollback();
        throw $exception;
    }
    insight_dist_refresh_assignments($connection);
    $consensus = [];
    foreach (array_keys($affectedSites) as $siteId) {
        $consensus[] = insight_dist_evaluate_site($connection, (int)$siteId);
    }
    return [
        'batch_id' => $batchId,
        'accepted' => $accepted,
        'duplicates' => $duplicates,
        'rejected' => $rejected,
        'already_processed' => false,
        'errors' => $errors,
        'consensus' => $consensus,
    ];
}

function insight_dist_cleanup(mysqli $connection): array
{
    $rawDays = insight_dist_env_int('INSIGHT_AGENT_RAW_RETENTION_DAYS', 7, 1, 365);
    $snapshotDays = insight_dist_env_int('INSIGHT_CONSENSUS_RETENTION_DAYS', 90, 7, 3650);
    $batchDays = insight_dist_env_int('INSIGHT_AGENT_BATCH_RETENTION_DAYS', 7, 1, 365);
    $cutoffs = [
        'monitoring_observations' => date('Y-m-d H:i:s', time() - ($rawDays * 86400)),
        'monitoring_consensus_snapshots' => date('Y-m-d H:i:s', time() - ($snapshotDays * 86400)),
        'monitoring_agent_batches' => date('Y-m-d H:i:s', time() - ($batchDays * 86400)),
    ];
    $deleted = [];
    foreach ($cutoffs as $table => $cutoff) {
        $column = $table === 'monitoring_consensus_snapshots' ? 'bucket_at' : 'received_at';
        $statement = insight_dist_execute($connection, "DELETE FROM {$table} WHERE {$column} < ?", [$cutoff]);
        $deleted[$table] = max(0, $statement->affected_rows);
        $statement->close();
    }
    return $deleted;
}

function insight_dist_summary(mysqli $connection): array
{
    $nodeTtl = insight_dist_env_int('INSIGHT_AGENT_NODE_TTL_SEC', 180, 30, 86400);
    $cutoff = date('Y-m-d H:i:s', time() - $nodeTtl);
    $nodes = insight_dist_query_one(
        $connection,
        "SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN status = 'active' AND last_seen_at >= ? THEN 1 ELSE 0 END) AS live,
            SUM(CASE WHEN status = 'active' AND last_seen_at < ? THEN 1 ELSE 0 END) AS stale,
            SUM(CASE WHEN status = 'revoked' THEN 1 ELSE 0 END) AS revoked
         FROM monitoring_nodes",
        [$cutoff, $cutoff]
    ) ?? [];
    $consensus = insight_dist_query_one(
        $connection,
        "SELECT
            COUNT(*) AS total,
            SUM(status = 'online') AS online,
            SUM(status = 'offline') AS offline,
            SUM(status = 'degraded') AS degraded,
            SUM(status = 'unknown') AS unknown,
            MAX(evaluated_at) AS last_evaluated_at
         FROM monitoring_consensus_current"
    ) ?? [];
    return [
        'nodes' => [
            'total' => (int)($nodes['total'] ?? 0),
            'live' => (int)($nodes['live'] ?? 0),
            'stale' => (int)($nodes['stale'] ?? 0),
            'revoked' => (int)($nodes['revoked'] ?? 0),
        ],
        'consensus' => [
            'total' => (int)($consensus['total'] ?? 0),
            'online' => (int)($consensus['online'] ?? 0),
            'offline' => (int)($consensus['offline'] ?? 0),
            'degraded' => (int)($consensus['degraded'] ?? 0),
            'unknown' => (int)($consensus['unknown'] ?? 0),
            'last_evaluated_at' => $consensus['last_evaluated_at'] ?? null,
        ],
    ];
}
