<?php

declare(strict_types=1);

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/_bootstrap.php';

function insight_probes_allowed_intervals(): array
{
    return [60, 120, 300, 600, 1800];
}

function insight_probes_valid_host(string $host): bool
{
    $value = trim($host, '[]');
    if (filter_var($value, FILTER_VALIDATE_IP) !== false) {
        return true;
    }
    return filter_var($value, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
}

function insight_probes_normalize_target(string $target, string $probeType): array
{
    $value = trim($target);
    if ($value === '' || strlen($value) > 255) {
        return ['ok' => false, 'error' => 'admin.probes.errorTarget'];
    }

    if ($probeType === 'http') {
        $url = preg_match('#^https?://#i', $value) === 1 ? $value : 'https://' . $value;
        $parsed = parse_url($url);
        $scheme = is_array($parsed) ? strtolower((string)($parsed['scheme'] ?? '')) : '';
        $host = is_array($parsed) ? trim((string)($parsed['host'] ?? ''), '[]') : '';
        if (!is_array($parsed) || !in_array($scheme, ['http', 'https'], true) || !insight_probes_valid_host($host)) {
            return ['ok' => false, 'error' => 'admin.probes.errorHttp'];
        }
        if (isset($parsed['user']) || isset($parsed['pass']) || isset($parsed['fragment'])) {
            return ['ok' => false, 'error' => 'admin.probes.errorHttp'];
        }
        return ['ok' => true, 'target' => $url];
    }

    if ($probeType === 'icmp') {
        if (str_contains($value, '://') || str_contains($value, '/') || str_contains($value, ':')) {
            if (filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                return ['ok' => false, 'error' => 'admin.probes.errorIcmp'];
            }
        }
        if (!insight_probes_valid_host($value)) {
            return ['ok' => false, 'error' => 'admin.probes.errorIcmp'];
        }
        return ['ok' => true, 'target' => strtolower($value)];
    }

    if ($probeType === 'tcp') {
        $parsed = parse_url('tcp://' . $value);
        $host = is_array($parsed) ? trim((string)($parsed['host'] ?? ''), '[]') : '';
        $port = is_array($parsed) ? (int)($parsed['port'] ?? 0) : 0;
        if (
            !is_array($parsed)
            || !insight_probes_valid_host($host)
            || $port < 1
            || $port > 65535
            || isset($parsed['path'])
            || isset($parsed['query'])
            || isset($parsed['fragment'])
            || isset($parsed['user'])
            || isset($parsed['pass'])
        ) {
            return ['ok' => false, 'error' => 'admin.probes.errorTcp'];
        }
        $normalizedHost = str_contains($host, ':') ? '[' . strtolower($host) . ']' : strtolower($host);
        return ['ok' => true, 'target' => $normalizedHost . ':' . $port];
    }

    return ['ok' => false, 'error' => 'admin.probes.errorType'];
}

function insight_probes_validate(array $input): array
{
    $probeType = strtolower(trim((string)($input['probe_type'] ?? '')));
    if (!in_array($probeType, ['http', 'icmp', 'tcp'], true)) {
        return ['ok' => false, 'error' => 'admin.probes.errorType'];
    }
    $interval = filter_var($input['interval_sec'] ?? null, FILTER_VALIDATE_INT);
    if ($interval === false || !in_array((int)$interval, insight_probes_allowed_intervals(), true)) {
        return ['ok' => false, 'error' => 'admin.probes.errorInterval'];
    }
    $target = insight_probes_normalize_target((string)($input['target'] ?? ''), $probeType);
    if (!($target['ok'] ?? false)) {
        return $target;
    }
    return [
        'ok' => true,
        'probe_type' => $probeType,
        'interval_sec' => (int)$interval,
        'target' => (string)$target['target'],
    ];
}

function insight_probes_preview_path(): string
{
    return dirname(insight_admin_auth_path()) . '/dev-probes.json';
}

function insight_probes_preview_rows(): array
{
    if (!insight_auth_dev_bypass_enabled()) {
        return [];
    }
    $path = insight_probes_preview_path();
    if (!is_file($path) || !is_readable($path)) {
        return [];
    }
    $decoded = json_decode((string)file_get_contents($path), true);
    return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
}

function insight_probes_write_preview_rows(array $rows): bool
{
    $path = insight_probes_preview_path();
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        return false;
    }
    $json = json_encode(array_values($rows), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json) || file_put_contents($path, $json . PHP_EOL, LOCK_EX) === false) {
        return false;
    }
    @chmod($path, 0600);
    return true;
}

function insight_probes_create_preview(array $probe): array
{
    $rows = insight_probes_preview_rows();
    foreach ($rows as $row) {
        if (strcasecmp((string)($row['url'] ?? ''), (string)$probe['target']) === 0) {
            return ['ok' => false, 'status_code' => 409, 'error' => 'admin.probes.errorDuplicate'];
        }
    }
    $created = [
        'id' => 900000 + count($rows) + 1,
        'url' => (string)$probe['target'],
        'probe_type' => (string)$probe['probe_type'],
        'probe_interval_sec' => (int)$probe['interval_sec'],
        'status' => 'unknown',
        'response_time' => null,
        'http_code' => null,
        'checked_at' => null,
    ];
    $rows[] = $created;
    if (!insight_probes_write_preview_rows($rows)) {
        return ['ok' => false, 'status_code' => 500, 'error' => 'admin.probes.errorStorage'];
    }
    return ['ok' => true, 'status_code' => 201, 'probe' => $created, 'mode' => 'preview'];
}

function insight_probes_update_preview(int $probeId, array $probe): array
{
    $rows = insight_probes_preview_rows();
    $found = false;
    foreach ($rows as $index => $row) {
        if ((int)($row['id'] ?? 0) === $probeId) {
            $found = true;
            continue;
        }
        if (strcasecmp((string)($row['url'] ?? ''), (string)$probe['target']) === 0) {
            return ['ok' => false, 'status_code' => 409, 'error' => 'admin.probes.errorDuplicate'];
        }
    }
    if (!$found) {
        return ['ok' => false, 'status_code' => 404, 'error' => 'admin.probes.errorNotFound'];
    }
    foreach ($rows as $index => $row) {
        if ((int)($row['id'] ?? 0) !== $probeId) {
            continue;
        }
        $rows[$index] = array_merge($row, [
            'url' => (string)$probe['target'],
            'probe_type' => (string)$probe['probe_type'],
            'probe_interval_sec' => (int)$probe['interval_sec'],
            'status' => 'unknown',
            'response_time' => null,
            'http_code' => null,
            'checked_at' => null,
        ]);
        if (!insight_probes_write_preview_rows($rows)) {
            return ['ok' => false, 'status_code' => 500, 'error' => 'admin.probes.errorStorage'];
        }
        return ['ok' => true, 'status_code' => 200, 'probe' => $rows[$index], 'mode' => 'preview'];
    }
    return ['ok' => false, 'status_code' => 404, 'error' => 'admin.probes.errorNotFound'];
}

function insight_probes_delete_preview(int $probeId): array
{
    $rows = insight_probes_preview_rows();
    $filtered = array_values(array_filter(
        $rows,
        static fn(array $row): bool => (int)($row['id'] ?? 0) !== $probeId
    ));
    if (count($filtered) === count($rows)) {
        return ['ok' => false, 'status_code' => 404, 'error' => 'admin.probes.errorNotFound'];
    }
    if (!insight_probes_write_preview_rows($filtered)) {
        return ['ok' => false, 'status_code' => 500, 'error' => 'admin.probes.errorStorage'];
    }
    return ['ok' => true, 'status_code' => 200, 'deleted_id' => $probeId, 'mode' => 'preview'];
}

function insight_probes_database(array $config): mysqli
{
    mysqli_report(MYSQLI_REPORT_OFF);
    $database = mysqli_init();
    if (!$database instanceof mysqli) {
        throw new RuntimeException('database_initialization_failed');
    }
    $database->options(MYSQLI_OPT_CONNECT_TIMEOUT, 3);
    $connected = @$database->real_connect(
        (string)$config['servername'],
        (string)$config['username'],
        (string)$config['password'],
        (string)$config['dbname'],
        (int)$config['port']
    );
    if (!$connected) {
        $database->close();
        throw new RuntimeException('database_unavailable');
    }
    $database->set_charset('utf8mb4');
    return $database;
}

function insight_probes_create_database(array $config, array $probe): array
{
    $database = insight_probes_database($config);
    $target = (string)$probe['target'];
    $duplicate = $database->prepare('SELECT id FROM sites WHERE LOWER(url) = LOWER(?) LIMIT 1');
    if (!$duplicate instanceof mysqli_stmt) {
        $database->close();
        throw new RuntimeException('database_prepare_failed');
    }
    $duplicate->bind_param('s', $target);
    $duplicate->execute();
    $duplicate->store_result();
    $exists = $duplicate->num_rows > 0;
    $duplicate->close();
    if ($exists) {
        $database->close();
        return ['ok' => false, 'status_code' => 409, 'error' => 'admin.probes.errorDuplicate'];
    }

    $statement = $database->prepare('INSERT INTO sites (url, probe_type, probe_interval_sec) VALUES (?, ?, ?)');
    if (!$statement instanceof mysqli_stmt) {
        $database->close();
        throw new RuntimeException('database_prepare_failed');
    }
    $probeType = (string)$probe['probe_type'];
    $interval = (int)$probe['interval_sec'];
    $statement->bind_param('ssi', $target, $probeType, $interval);
    if (!$statement->execute()) {
        $statement->close();
        $database->close();
        throw new RuntimeException('database_insert_failed');
    }
    $created = [
        'id' => (int)$statement->insert_id,
        'url' => $target,
        'probe_type' => $probeType,
        'probe_interval_sec' => $interval,
        'status' => 'unknown',
        'response_time' => null,
        'http_code' => null,
        'checked_at' => null,
    ];
    $statement->close();
    $database->close();
    return ['ok' => true, 'status_code' => 201, 'probe' => $created, 'mode' => 'database'];
}

function insight_probes_update_database(array $config, int $probeId, array $probe): array
{
    $database = insight_probes_database($config);
    $target = (string)$probe['target'];
    $duplicate = $database->prepare('SELECT id FROM sites WHERE LOWER(url) = LOWER(?) AND id <> ? LIMIT 1');
    if (!$duplicate instanceof mysqli_stmt) {
        $database->close();
        throw new RuntimeException('database_prepare_failed');
    }
    $duplicate->bind_param('si', $target, $probeId);
    $duplicate->execute();
    $duplicate->store_result();
    $exists = $duplicate->num_rows > 0;
    $duplicate->close();
    if ($exists) {
        $database->close();
        return ['ok' => false, 'status_code' => 409, 'error' => 'admin.probes.errorDuplicate'];
    }
    $statement = $database->prepare(
        'UPDATE sites SET url = ?, probe_type = ?, probe_interval_sec = ? WHERE id = ?'
    );
    if (!$statement instanceof mysqli_stmt) {
        $database->close();
        throw new RuntimeException('database_prepare_failed');
    }
    $probeType = (string)$probe['probe_type'];
    $interval = (int)$probe['interval_sec'];
    $statement->bind_param('ssii', $target, $probeType, $interval, $probeId);
    if (!$statement->execute()) {
        $statement->close();
        $database->close();
        throw new RuntimeException('database_update_failed');
    }
    if ($statement->affected_rows < 1) {
        $existsStatement = $database->prepare('SELECT id FROM sites WHERE id = ? LIMIT 1');
        if (!$existsStatement instanceof mysqli_stmt) {
            $statement->close();
            $database->close();
            throw new RuntimeException('database_prepare_failed');
        }
        $existsStatement->bind_param('i', $probeId);
        $existsStatement->execute();
        $existsStatement->store_result();
        $probeExists = $existsStatement->num_rows > 0;
        $existsStatement->close();
        if (!$probeExists) {
            $statement->close();
            $database->close();
            return ['ok' => false, 'status_code' => 404, 'error' => 'admin.probes.errorNotFound'];
        }
    }
    $statement->close();
    $database->close();
    return [
        'ok' => true,
        'status_code' => 200,
        'probe' => [
            'id' => $probeId,
            'url' => $target,
            'probe_type' => $probeType,
            'probe_interval_sec' => $interval,
            'status' => 'unknown',
            'response_time' => null,
            'http_code' => null,
            'checked_at' => null,
        ],
        'mode' => 'database',
    ];
}

function insight_probes_delete_database(array $config, int $probeId): array
{
    $database = insight_probes_database($config);
    $statement = $database->prepare('DELETE FROM sites WHERE id = ?');
    if (!$statement instanceof mysqli_stmt) {
        $database->close();
        throw new RuntimeException('database_prepare_failed');
    }
    $statement->bind_param('i', $probeId);
    if (!$statement->execute()) {
        $statement->close();
        $database->close();
        throw new RuntimeException('database_delete_failed');
    }
    $deleted = $statement->affected_rows > 0;
    $statement->close();
    $database->close();
    if (!$deleted) {
        return ['ok' => false, 'status_code' => 404, 'error' => 'admin.probes.errorNotFound'];
    }
    return ['ok' => true, 'status_code' => 200, 'deleted_id' => $probeId, 'mode' => 'database'];
}

function insight_probes_create(array $config, array $input): array
{
    $probe = insight_probes_validate($input);
    if (!($probe['ok'] ?? false)) {
        return ['ok' => false, 'status_code' => 422, 'error' => (string)($probe['error'] ?? 'admin.probes.errorGeneric')];
    }
    try {
        return insight_probes_create_database($config, $probe);
    } catch (Throwable) {
        if (insight_auth_dev_bypass_enabled()) {
            return insight_probes_create_preview($probe);
        }
        return ['ok' => false, 'status_code' => 503, 'error' => 'admin.probes.errorDatabase'];
    }
}

function insight_probes_update(array $config, int $probeId, array $input): array
{
    if ($probeId < 1) {
        return ['ok' => false, 'status_code' => 422, 'error' => 'admin.probes.errorNotFound'];
    }
    $probe = insight_probes_validate($input);
    if (!($probe['ok'] ?? false)) {
        return ['ok' => false, 'status_code' => 422, 'error' => (string)($probe['error'] ?? 'admin.probes.errorGeneric')];
    }
    try {
        return insight_probes_update_database($config, $probeId, $probe);
    } catch (Throwable) {
        if (insight_auth_dev_bypass_enabled()) {
            return insight_probes_update_preview($probeId, $probe);
        }
        return ['ok' => false, 'status_code' => 503, 'error' => 'admin.probes.errorDatabase'];
    }
}

function insight_probes_delete(array $config, int $probeId): array
{
    if ($probeId < 1) {
        return ['ok' => false, 'status_code' => 422, 'error' => 'admin.probes.errorNotFound'];
    }
    try {
        return insight_probes_delete_database($config, $probeId);
    } catch (Throwable) {
        if (insight_auth_dev_bypass_enabled()) {
            return insight_probes_delete_preview($probeId);
        }
        return ['ok' => false, 'status_code' => 503, 'error' => 'admin.probes.errorDatabase'];
    }
}
