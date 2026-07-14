<?php

declare(strict_types=1);

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/_bootstrap.php';
require_once dirname(__DIR__) . '/_python_engine.php';

function insight_probes_allowed_intervals(): array
{
    return [10, 20, 30, 60, 120, 180, 300, 600, 1800, 21600, 43200, 86400];
}

function insight_probes_bool(mixed $value, bool $default = false): bool
{
    if (is_bool($value)) {
        return $value;
    }
    if ($value === null || trim((string)$value) === '') {
        return $default;
    }
    return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
}

function insight_probes_bounded_int(mixed $value, int $default, int $minimum, int $maximum): int
{
    $parsed = filter_var($value, FILTER_VALIDATE_INT);
    return $parsed === false ? $default : max($minimum, min($maximum, (int)$parsed));
}

function insight_probes_secret_key(): string
{
    $rawKey = insight_admin_env('INSIGHT_NOTIFICATION_ENCRYPTION_KEY');
    if (strlen($rawKey) < 32 || !function_exists('sodium_crypto_secretbox')) {
        throw new RuntimeException('notification_encryption_unavailable');
    }
    $key = null;
    if (preg_match('/^[a-f0-9]{64}$/i', $rawKey) === 1) {
        $decoded = hex2bin($rawKey);
        $key = is_string($decoded) ? $decoded : null;
    }
    if (!is_string($key) || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
        $base64 = strtr($rawKey, '-_', '+/');
        $decoded = base64_decode($base64 . str_repeat('=', (4 - strlen($base64) % 4) % 4), true);
        $key = is_string($decoded) && strlen($decoded) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES
            ? $decoded
            : hash('sha256', $rawKey, true);
    }
    return $key;
}

function insight_probes_encrypt_config(array $config): string
{
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $payload = json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $encrypted = $nonce . sodium_crypto_secretbox($payload, $nonce, insight_probes_secret_key());
    return 'v1:' . rtrim(strtr(base64_encode($encrypted), '+/', '-_'), '=');
}

function insight_probes_decrypt_config(string $ciphertext): array
{
    if (!str_starts_with($ciphertext, 'v1:')) {
        return [];
    }
    $encoded = substr($ciphertext, 3);
    $decoded = base64_decode(strtr($encoded, '-_', '+/') . str_repeat('=', (4 - strlen($encoded) % 4) % 4), true);
    if (!is_string($decoded) || strlen($decoded) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
        return [];
    }
    $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $payload = sodium_crypto_secretbox_open(substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, insight_probes_secret_key());
    if (!is_string($payload)) {
        return [];
    }
    $config = json_decode($payload, true);
    return is_array($config) && !array_is_list($config) ? $config : [];
}

function insight_probes_encrypt_password(string $password): string
{
    return insight_probes_encrypt_config(['password' => $password]);
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

    if (in_array($probeType, ['http', 'browser'], true)) {
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

    if ($probeType === 'websocket') {
        $url = preg_match('#^wss?://#i', $value) === 1 ? $value : 'wss://' . $value;
        $parsed = parse_url($url);
        $scheme = is_array($parsed) ? strtolower((string)($parsed['scheme'] ?? '')) : '';
        $host = is_array($parsed) ? trim((string)($parsed['host'] ?? ''), '[]') : '';
        if (!is_array($parsed) || !in_array($scheme, ['ws', 'wss'], true) || !insight_probes_valid_host($host) || isset($parsed['user']) || isset($parsed['pass']) || isset($parsed['fragment'])) {
            return ['ok' => false, 'error' => 'admin.probes.errorWebSocket'];
        }
        return ['ok' => true, 'target' => $url];
    }

    if ($probeType === 'mqtt') {
        $url = preg_match('#^mqtts?://#i', $value) === 1 ? $value : 'mqtt://' . $value;
        $parsed = parse_url($url);
        $scheme = is_array($parsed) ? strtolower((string)($parsed['scheme'] ?? '')) : '';
        $host = is_array($parsed) ? trim((string)($parsed['host'] ?? ''), '[]') : '';
        $port = is_array($parsed) && isset($parsed['port']) ? (int)$parsed['port'] : 0;
        if (!is_array($parsed) || !in_array($scheme, ['mqtt', 'mqtts'], true) || !insight_probes_valid_host($host) || ($port !== 0 && ($port < 1 || $port > 65535)) || isset($parsed['user']) || isset($parsed['pass']) || isset($parsed['fragment'])) {
            return ['ok' => false, 'error' => 'admin.probes.errorMqtt'];
        }
        return ['ok' => true, 'target' => $url];
    }

    if ($probeType === 'sql') {
        $parsed = parse_url($value);
        $scheme = is_array($parsed) ? strtolower((string)($parsed['scheme'] ?? '')) : '';
        $host = is_array($parsed) ? trim((string)($parsed['host'] ?? ''), '[]') : '';
        $database = is_array($parsed) ? trim((string)($parsed['path'] ?? ''), '/') : '';
        $port = is_array($parsed) && isset($parsed['port']) ? (int)$parsed['port'] : 0;
        if (!is_array($parsed) || !in_array($scheme, ['mysql', 'mariadb', 'postgres', 'postgresql'], true) || !insight_probes_valid_host($host) || $database === '' || ($port !== 0 && ($port < 1 || $port > 65535)) || isset($parsed['user']) || isset($parsed['pass']) || isset($parsed['query']) || isset($parsed['fragment'])) {
            return ['ok' => false, 'error' => 'admin.probes.errorSql'];
        }
        return ['ok' => true, 'target' => $value];
    }

    if ($probeType === 'docker') {
        $url = str_starts_with(strtolower($value), 'docker://') ? $value : 'docker://' . $value;
        $parsed = parse_url($url);
        $host = is_array($parsed) ? strtolower((string)($parsed['host'] ?? '')) : '';
        $container = is_array($parsed) ? trim((string)($parsed['path'] ?? ''), '/') : '';
        $port = is_array($parsed) && isset($parsed['port']) ? (int)$parsed['port'] : 0;
        if (!is_array($parsed) || strtolower((string)($parsed['scheme'] ?? '')) !== 'docker' || $host === '' || $container === '' || (!in_array($host, ['local', 'socket'], true) && ($port < 1 || $port > 65535)) || isset($parsed['user']) || isset($parsed['pass']) || isset($parsed['query']) || isset($parsed['fragment'])) {
            return ['ok' => false, 'error' => 'admin.probes.errorDocker'];
        }
        return ['ok' => true, 'target' => $url];
    }

    if ($probeType === 'grpc') {
        $url = preg_match('#^grpcs?://#i', $value) === 1 ? $value : 'grpc://' . $value;
        $parsed = parse_url($url);
        $scheme = is_array($parsed) ? strtolower((string)($parsed['scheme'] ?? '')) : '';
        $host = is_array($parsed) ? trim((string)($parsed['host'] ?? ''), '[]') : '';
        $port = is_array($parsed) && isset($parsed['port']) ? (int)$parsed['port'] : 0;
        if (!is_array($parsed) || !in_array($scheme, ['grpc', 'grpcs'], true) || !insight_probes_valid_host($host) || ($port !== 0 && ($port < 1 || $port > 65535)) || isset($parsed['user']) || isset($parsed['pass']) || isset($parsed['path']) || isset($parsed['query']) || isset($parsed['fragment'])) {
            return ['ok' => false, 'error' => 'admin.probes.errorGrpc'];
        }
        return ['ok' => true, 'target' => $url];
    }

    if ($probeType === 'redis') {
        $url = preg_match('#^rediss?://#i', $value) === 1 ? $value : 'redis://' . $value;
        $parsed = parse_url($url);
        $scheme = is_array($parsed) ? strtolower((string)($parsed['scheme'] ?? '')) : '';
        $host = is_array($parsed) ? trim((string)($parsed['host'] ?? ''), '[]') : '';
        $database = is_array($parsed) ? trim((string)($parsed['path'] ?? ''), '/') : '';
        $port = is_array($parsed) && isset($parsed['port']) ? (int)$parsed['port'] : 0;
        if (!is_array($parsed) || !in_array($scheme, ['redis', 'rediss'], true) || !insight_probes_valid_host($host) || ($database !== '' && (!ctype_digit($database) || (int)$database > 15)) || ($port !== 0 && ($port < 1 || $port > 65535)) || isset($parsed['user']) || isset($parsed['pass']) || isset($parsed['query']) || isset($parsed['fragment'])) {
            return ['ok' => false, 'error' => 'admin.probes.errorRedis'];
        }
        return ['ok' => true, 'target' => $url];
    }

    if ($probeType === 'smtp') {
        $url = preg_match('#^smtps?://#i', $value) === 1 ? $value : 'smtp://' . $value;
        $parsed = parse_url($url);
        $scheme = is_array($parsed) ? strtolower((string)($parsed['scheme'] ?? '')) : '';
        $host = is_array($parsed) ? trim((string)($parsed['host'] ?? ''), '[]') : '';
        $port = is_array($parsed) && isset($parsed['port']) ? (int)$parsed['port'] : 0;
        if (!is_array($parsed) || !in_array($scheme, ['smtp', 'smtps'], true) || !insight_probes_valid_host($host) || ($port !== 0 && ($port < 1 || $port > 65535)) || isset($parsed['user']) || isset($parsed['pass']) || isset($parsed['path']) || isset($parsed['query']) || isset($parsed['fragment'])) {
            return ['ok' => false, 'error' => 'admin.probes.errorSmtp'];
        }
        return ['ok' => true, 'target' => $url];
    }

    if ($probeType === 'rabbitmq') {
        $url = preg_match('#^amqps?://#i', $value) === 1 ? $value : 'amqp://' . $value;
        $parsed = parse_url($url);
        $scheme = is_array($parsed) ? strtolower((string)($parsed['scheme'] ?? '')) : '';
        $host = is_array($parsed) ? trim((string)($parsed['host'] ?? ''), '[]') : '';
        $port = is_array($parsed) && isset($parsed['port']) ? (int)$parsed['port'] : 0;
        if (!is_array($parsed) || !in_array($scheme, ['amqp', 'amqps'], true) || !insight_probes_valid_host($host) || ($port !== 0 && ($port < 1 || $port > 65535)) || isset($parsed['user']) || isset($parsed['pass']) || isset($parsed['query']) || isset($parsed['fragment'])) {
            return ['ok' => false, 'error' => 'admin.probes.errorRabbitMq'];
        }
        return ['ok' => true, 'target' => $url];
    }

    if ($probeType === 'snmp') {
        $url = preg_match('#^snmp://#i', $value) === 1 ? $value : 'snmp://' . $value;
        $parsed = parse_url($url);
        $host = is_array($parsed) ? trim((string)($parsed['host'] ?? ''), '[]') : '';
        $port = is_array($parsed) && isset($parsed['port']) ? (int)$parsed['port'] : 0;
        if (!is_array($parsed) || strtolower((string)($parsed['scheme'] ?? '')) !== 'snmp' || !insight_probes_valid_host($host) || ($port !== 0 && ($port < 1 || $port > 65535)) || isset($parsed['user']) || isset($parsed['pass']) || isset($parsed['path']) || isset($parsed['query']) || isset($parsed['fragment'])) {
            return ['ok' => false, 'error' => 'admin.probes.errorSnmp'];
        }
        return ['ok' => true, 'target' => $url];
    }

    if ($probeType === 'service') {
        $url = preg_match('#^agent://#i', $value) === 1 ? $value : 'agent://' . $value;
        $parsed = parse_url($url);
        $node = is_array($parsed) ? strtolower((string)($parsed['host'] ?? '')) : '';
        $path = is_array($parsed) ? trim((string)($parsed['path'] ?? ''), '/') : '';
        if (!is_array($parsed) || strtolower((string)($parsed['scheme'] ?? '')) !== 'agent' || preg_match('/^[a-z0-9][a-z0-9._-]{2,63}$/', $node) !== 1 || preg_match('#^(systemd|pm2)/[A-Za-z0-9@_.:-]{1,160}$#', $path) !== 1 || isset($parsed['user']) || isset($parsed['pass']) || isset($parsed['query']) || isset($parsed['fragment'])) {
            return ['ok' => false, 'error' => 'admin.probes.errorService'];
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

    if ($probeType === 'dns') {
        if (str_contains($value, '://') || str_contains($value, '/') || str_contains($value, ':') || !insight_probes_valid_host($value)) {
            return ['ok' => false, 'error' => 'admin.probes.errorDns'];
        }
        return ['ok' => true, 'target' => strtolower(rtrim($value, '.'))];
    }

    if ($probeType === 'heartbeat') {
        $slug = trim((string)preg_replace('/[^a-z0-9]+/i', '-', strtolower($value)), '-');
        if ($slug === '') {
            return ['ok' => false, 'error' => 'admin.probes.errorHeartbeat'];
        }
        return ['ok' => true, 'target' => substr($slug, 0, 180)];
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

function insight_probes_json_object(mixed $value, int $maximumEntries = 50): ?array
{
    if (is_array($value)) {
        $decoded = $value;
    } else {
        $raw = trim((string)$value);
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
    }
    if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded)) || count($decoded) > $maximumEntries) {
        return null;
    }
    return $decoded;
}

function insight_probes_advanced_config(array $input, string $probeType): array
{
    $existing = is_array($input['_existing_probe_config'] ?? null) ? $input['_existing_probe_config'] : [];
    if (($existing['kind'] ?? '') !== $probeType) {
        $existing = [];
    }
    $config = ['kind' => $probeType];
    if ($probeType === 'browser') {
        $variables = insight_probes_json_object($input['browser_variables_json'] ?? '', 100);
        if ($variables === null) {
            throw new InvalidArgumentException('admin.probes.errorVariables');
        }
        $config['variables'] = $variables !== [] ? $variables : (is_array($existing['variables'] ?? null) ? $existing['variables'] : []);
        $config['capture_success_screenshot'] = insight_probes_bool($input['capture_success_screenshot'] ?? null, false);
    } elseif ($probeType === 'websocket') {
        $headers = insight_probes_json_object($input['websocket_headers_json'] ?? '', 50);
        if ($headers === null) {
            throw new InvalidArgumentException('admin.probes.errorHeaders');
        }
        $config['headers'] = $headers !== [] ? $headers : (is_array($existing['headers'] ?? null) ? $existing['headers'] : []);
        $config['send'] = mb_substr((string)($input['websocket_send'] ?? ''), 0, 20000, 'UTF-8');
        $config['expect'] = mb_substr((string)($input['websocket_expect'] ?? ''), 0, 20000, 'UTF-8');
    } elseif ($probeType === 'mqtt') {
        $config['username'] = mb_substr(trim((string)($input['mqtt_username'] ?? '')), 0, 255, 'UTF-8');
        $password = (string)($input['mqtt_password'] ?? '');
        $config['password'] = $password !== '' ? $password : (string)($existing['password'] ?? '');
        $config['expect'] = mb_substr((string)($input['mqtt_expect'] ?? ''), 0, 20000, 'UTF-8');
        $config['qos'] = insight_probes_bounded_int($input['mqtt_qos'] ?? null, 0, 0, 2);
    } elseif ($probeType === 'sql') {
        $query = trim((string)($input['sql_query'] ?? 'SELECT 1'));
        $withoutFinal = str_ends_with($query, ';') ? rtrim(substr($query, 0, -1)) : $query;
        if ($withoutFinal === '' || str_contains($withoutFinal, ';') || preg_match('/^(SELECT|SHOW|WITH|EXPLAIN)\b/i', $withoutFinal) !== 1) {
            throw new InvalidArgumentException('admin.probes.errorSqlReadOnly');
        }
        $config['username'] = mb_substr(trim((string)($input['sql_username'] ?? '')), 0, 255, 'UTF-8');
        $password = (string)($input['sql_password'] ?? '');
        $config['password'] = $password !== '' ? $password : (string)($existing['password'] ?? '');
        $config['query'] = mb_substr($withoutFinal, 0, 20000, 'UTF-8');
        $config['expect'] = mb_substr((string)($input['sql_expect'] ?? ''), 0, 20000, 'UTF-8');
    } elseif (in_array($probeType, ['grpc', 'redis', 'smtp', 'rabbitmq'], true)) {
        $prefix = $probeType . '_';
        $config['username'] = mb_substr(trim((string)($input[$prefix . 'username'] ?? '')), 0, 255, 'UTF-8');
        $password = (string)($input[$prefix . 'password'] ?? '');
        $config['password'] = $password !== '' ? $password : (string)($existing['password'] ?? '');
        if ($probeType === 'grpc') {
            $config['service'] = mb_substr(trim((string)($input['grpc_service'] ?? '')), 0, 255, 'UTF-8');
        } elseif ($probeType === 'smtp') {
            $encryption = strtolower(trim((string)($input['smtp_encryption'] ?? 'starttls')));
            $config['encryption'] = in_array($encryption, ['ssl', 'starttls', 'none'], true) ? $encryption : 'starttls';
        }
    } elseif ($probeType === 'snmp') {
        $community = (string)($input['snmp_community'] ?? '');
        $config['community'] = $community !== '' ? mb_substr($community, 0, 255, 'UTF-8') : (string)($existing['community'] ?? '');
        $oid = trim((string)($input['snmp_oid'] ?? '1.3.6.1.2.1.1.3.0'));
        if (preg_match('/^[0-9]+(?:\.[0-9]+)+$/', $oid) !== 1) {
            throw new InvalidArgumentException('admin.probes.errorSnmpOid');
        }
        $config['oid'] = $oid;
        $config['expect'] = mb_substr((string)($input['snmp_expect'] ?? ''), 0, 500, 'UTF-8');
    }
    return $config;
}

function insight_probes_validate(array $input): array
{
    $probeType = strtolower(trim((string)($input['probe_type'] ?? '')));
    if (!in_array($probeType, ['http', 'browser', 'websocket', 'icmp', 'tcp', 'dns', 'heartbeat', 'mqtt', 'sql', 'docker', 'grpc', 'redis', 'smtp', 'rabbitmq', 'snmp', 'service'], true)) {
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
    $headers = $input['request_headers_json'] ?? '';
    if (is_array($headers)) {
        $headers = json_encode($headers, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    if (!is_string($headers)) {
        return ['ok' => false, 'error' => 'admin.probes.errorHeaders'];
    }
    if (trim($headers) !== '') {
        $decodedHeaders = json_decode($headers, true);
        if (!is_array($decodedHeaders) || ($decodedHeaders !== [] && array_is_list($decodedHeaders)) || count($decodedHeaders) > 50) {
            return ['ok' => false, 'error' => 'admin.probes.errorHeaders'];
        }
    }
    $keywordMode = strtolower(trim((string)($input['keyword_mode'] ?? 'none')));
    if (!in_array($keywordMode, ['none', 'contains', 'absent'], true)) {
        $keywordMode = 'none';
    }
    $dnsRecordType = strtoupper(trim((string)($input['dns_record_type'] ?? 'A')));
    if (!in_array($dnsRecordType, ['A', 'AAAA', 'CNAME', 'MX', 'NS', 'TXT'], true)) {
        return ['ok' => false, 'error' => 'admin.probes.errorDnsRecord'];
    }
    $statusCodes = trim((string)($input['accepted_status_codes'] ?? '200-399'));
    if (preg_match('/^[1-5]\d\d(?:-[1-5]\d\d)?(?:\s*,\s*[1-5]\d\d(?:-[1-5]\d\d)?)*$/', $statusCodes) !== 1) {
        return ['ok' => false, 'error' => 'admin.probes.errorStatusCodes'];
    }
    $httpMethod = strtoupper(trim((string)($input['http_method'] ?? 'GET')));
    if (!in_array($httpMethod, ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], true)) {
        return ['ok' => false, 'error' => 'admin.probes.errorHttpMethod'];
    }
    $httpRedirect = strtolower(trim((string)($input['http_redirect'] ?? 'follow')));
    if (!in_array($httpRedirect, ['follow', 'no_follow'], true)) {
        return ['ok' => false, 'error' => 'admin.probes.errorHttpRedirect'];
    }
    $passwordCiphertext = '';
    $password = (string)($input['basic_auth_password'] ?? '');
    if ($password !== '') {
        try {
            $passwordCiphertext = insight_probes_encrypt_password($password);
        } catch (Throwable) {
            return ['ok' => false, 'error' => 'admin.probes.errorEncryption'];
        }
    }
    $calcMethod = strtolower(trim((string)($input['calc_method'] ?? 'inherit')));
    if (!in_array($calcMethod, ['inherit', 'legacy', 'time_weighted', 'sample_ratio', 'interval_capped', 'strict_sla'], true)) {
        return ['ok' => false, 'error' => 'admin.probes.errorCalculation'];
    }
    $browserScript = trim((string)($input['browser_script'] ?? ''));
    if ($browserScript !== '') {
        $scenario = json_decode($browserScript, true);
        $actions = ['goto', 'click', 'fill', 'press', 'wait_for', 'expect_text', 'expect_url', 'evaluate', 'screenshot'];
        if (!is_array($scenario) || !array_is_list($scenario) || count($scenario) > 50) {
            return ['ok' => false, 'error' => 'admin.probes.errorBrowserScenario'];
        }
        foreach ($scenario as $step) {
            if (!is_array($step) || array_is_list($step) || !in_array(strtolower(trim((string)($step['action'] ?? ''))), $actions, true)) {
                return ['ok' => false, 'error' => 'admin.probes.errorBrowserScenario'];
            }
        }
        $browserScript = json_encode($scenario, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    try {
        $advancedConfig = insight_probes_advanced_config($input, $probeType);
        $probeConfigCiphertext = count($advancedConfig) > 1 ? insight_probes_encrypt_config($advancedConfig) : '';
    } catch (InvalidArgumentException $exception) {
        return ['ok' => false, 'error' => $exception->getMessage()];
    } catch (Throwable) {
        return ['ok' => false, 'error' => 'admin.probes.errorEncryption'];
    }
    return [
        'ok' => true,
        'probe_type' => $probeType,
        'interval_sec' => (int)$interval,
        'target' => (string)$target['target'],
        'calc_method' => $calcMethod,
        'name' => mb_substr(trim((string)($input['name'] ?? '')), 0, 160, 'UTF-8'),
        'active' => insight_probes_bool($input['active'] ?? null, true),
        'timeout_sec' => insight_probes_bounded_int($input['timeout_sec'] ?? null, 10, 1, 120),
        'retry_count' => insight_probes_bounded_int($input['retry_count'] ?? null, 2, 0, 10),
        'failure_threshold' => insight_probes_bounded_int($input['failure_threshold'] ?? null, 2, 1, 20),
        'recovery_threshold' => insight_probes_bounded_int($input['recovery_threshold'] ?? null, 2, 1, 20),
        'accepted_status_codes' => preg_replace('/\s+/', '', $statusCodes),
        'http_method' => $httpMethod,
        'http_redirect' => $httpRedirect,
        'keyword_text' => mb_substr((string)($input['keyword_text'] ?? ''), 0, 20000, 'UTF-8'),
        'keyword_mode' => $keywordMode,
        'json_path' => mb_substr(trim((string)($input['json_path'] ?? '')), 0, 500, 'UTF-8'),
        'json_expected_value' => mb_substr((string)($input['json_expected_value'] ?? ''), 0, 20000, 'UTF-8'),
        'request_headers_json' => $headers,
        'request_body' => mb_substr((string)($input['request_body'] ?? ''), 0, 65535, 'UTF-8'),
        'basic_auth_username' => mb_substr(trim((string)($input['basic_auth_username'] ?? '')), 0, 255, 'UTF-8'),
        'basic_auth_password_ciphertext' => $passwordCiphertext,
        'probe_config_ciphertext' => $probeConfigCiphertext,
        'browser_script' => $browserScript,
        'diagnostics_enabled' => insight_probes_bool($input['diagnostics_enabled'] ?? null, true),
        'diagnostic_capture_body' => insight_probes_bool($input['diagnostic_capture_body'] ?? null, false),
        'tls_verify' => insight_probes_bool($input['tls_verify'] ?? null, true),
        'tls_expiry_threshold_days' => insight_probes_bounded_int($input['tls_expiry_threshold_days'] ?? null, 14, 1, 365),
        'dns_record_type' => $dnsRecordType,
        'dns_expected_value' => mb_substr(trim((string)($input['dns_expected_value'] ?? '')), 0, 500, 'UTF-8'),
        'heartbeat_grace_sec' => insight_probes_bounded_int($input['heartbeat_grace_sec'] ?? null, 300, 10, 2592000),
        'slo_target_percent' => max(0.0, min(100.0, (float)($input['slo_target_percent'] ?? 99.9))),
        'public_visible' => insight_probes_bool($input['public_visible'] ?? null, true),
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

function insight_probes_preview_defaults(array $probe): array
{
    return array_replace([
        'name' => '',
        'active' => true,
        'timeout_sec' => 10,
        'retry_count' => 2,
        'failure_threshold' => 2,
        'recovery_threshold' => 2,
        'calc_method' => 'inherit',
        'accepted_status_codes' => '200-399',
        'http_method' => 'GET',
        'http_redirect' => 'follow',
        'keyword_text' => '',
        'keyword_mode' => 'none',
        'json_path' => '',
        'json_expected_value' => '',
        'request_headers_json' => '',
        'request_body' => '',
        'basic_auth_username' => '',
        'basic_auth_password_ciphertext' => '',
        'probe_config_ciphertext' => '',
        'browser_script' => '',
        'diagnostics_enabled' => true,
        'diagnostic_capture_body' => false,
        'tls_verify' => true,
        'tls_expiry_threshold_days' => 14,
        'dns_record_type' => 'A',
        'dns_expected_value' => '',
        'heartbeat_grace_sec' => 300,
        'slo_target_percent' => 99.9,
        'public_visible' => true,
    ], $probe);
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
    $probe = insight_probes_preview_defaults($probe);
    $rows = insight_probes_preview_rows();
    foreach ($rows as $row) {
        if (strcasecmp((string)($row['url'] ?? ''), (string)$probe['target']) === 0 && (string)($row['probe_type'] ?? '') === (string)$probe['probe_type']) {
            return ['ok' => false, 'status_code' => 409, 'error' => 'admin.probes.errorDuplicate'];
        }
    }
    $created = [
        'id' => 900000 + count($rows) + 1,
        'url' => (string)$probe['target'],
        'probe_type' => (string)$probe['probe_type'],
        'probe_interval_sec' => (int)$probe['interval_sec'],
        'name' => (string)$probe['name'],
        'active' => (bool)$probe['active'],
        'timeout_sec' => (int)$probe['timeout_sec'],
        'retry_count' => (int)$probe['retry_count'],
        'failure_threshold' => (int)$probe['failure_threshold'],
        'recovery_threshold' => (int)$probe['recovery_threshold'],
        'calc_method' => (string)$probe['calc_method'],
        'accepted_status_codes' => (string)$probe['accepted_status_codes'],
        'http_primary_method' => (string)$probe['http_method'],
        'http_primary_redirect' => (string)$probe['http_redirect'],
        'keyword_text' => (string)$probe['keyword_text'],
        'keyword_mode' => (string)$probe['keyword_mode'],
        'json_path' => (string)$probe['json_path'],
        'json_expected_value' => (string)$probe['json_expected_value'],
        'request_headers_json' => (string)$probe['request_headers_json'],
        'request_body' => (string)$probe['request_body'],
        'basic_auth_username' => (string)$probe['basic_auth_username'],
        'basic_auth_password_ciphertext' => (string)$probe['basic_auth_password_ciphertext'],
        'probe_config_ciphertext' => (string)$probe['probe_config_ciphertext'],
        'browser_script' => (string)$probe['browser_script'],
        'diagnostics_enabled' => (bool)$probe['diagnostics_enabled'],
        'diagnostic_capture_body' => (bool)$probe['diagnostic_capture_body'],
        'tls_verify' => (bool)$probe['tls_verify'],
        'tls_expiry_threshold_days' => (int)$probe['tls_expiry_threshold_days'],
        'dns_record_type' => (string)$probe['dns_record_type'],
        'dns_expected_value' => (string)$probe['dns_expected_value'],
        'heartbeat_grace_sec' => (int)$probe['heartbeat_grace_sec'],
        'slo_target_percent' => (float)$probe['slo_target_percent'],
        'public_visible' => (bool)$probe['public_visible'],
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
    $probe = insight_probes_preview_defaults($probe);
    $rows = insight_probes_preview_rows();
    $found = false;
    foreach ($rows as $index => $row) {
        if ((int)($row['id'] ?? 0) === $probeId) {
            $found = true;
            continue;
        }
        if (strcasecmp((string)($row['url'] ?? ''), (string)$probe['target']) === 0 && (string)($row['probe_type'] ?? '') === (string)$probe['probe_type']) {
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
            'name' => (string)$probe['name'],
            'active' => (bool)$probe['active'],
            'timeout_sec' => (int)$probe['timeout_sec'],
            'retry_count' => (int)$probe['retry_count'],
            'failure_threshold' => (int)$probe['failure_threshold'],
            'recovery_threshold' => (int)$probe['recovery_threshold'],
            'calc_method' => (string)$probe['calc_method'],
            'accepted_status_codes' => (string)$probe['accepted_status_codes'],
            'http_primary_method' => (string)$probe['http_method'],
            'http_primary_redirect' => (string)$probe['http_redirect'],
            'keyword_text' => (string)$probe['keyword_text'],
            'keyword_mode' => (string)$probe['keyword_mode'],
            'json_path' => (string)$probe['json_path'],
            'json_expected_value' => (string)$probe['json_expected_value'],
            'request_headers_json' => (string)$probe['request_headers_json'],
            'request_body' => (string)$probe['request_body'],
            'basic_auth_username' => (string)$probe['basic_auth_username'],
            'basic_auth_password_ciphertext' => (string)$probe['basic_auth_password_ciphertext'],
            'probe_config_ciphertext' => (string)$probe['probe_config_ciphertext'],
            'browser_script' => (string)$probe['browser_script'],
            'diagnostics_enabled' => (bool)$probe['diagnostics_enabled'],
            'diagnostic_capture_body' => (bool)$probe['diagnostic_capture_body'],
            'tls_verify' => (bool)$probe['tls_verify'],
            'tls_expiry_threshold_days' => (int)$probe['tls_expiry_threshold_days'],
            'dns_record_type' => (string)$probe['dns_record_type'],
            'dns_expected_value' => (string)$probe['dns_expected_value'],
            'heartbeat_grace_sec' => (int)$probe['heartbeat_grace_sec'],
            'slo_target_percent' => (float)$probe['slo_target_percent'],
            'public_visible' => (bool)$probe['public_visible'],
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

function insight_probes_existing_advanced_config(array $config, int $probeId): array
{
    $database = insight_probes_database($config);
    try {
        $statement = $database->prepare('SELECT probe_config_ciphertext FROM sites WHERE id = ? LIMIT 1');
        if (!$statement instanceof mysqli_stmt) {
            return [];
        }
        $statement->bind_param('i', $probeId);
        $statement->execute();
        $result = $statement->get_result();
        $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
        if ($result instanceof mysqli_result) {
            $result->free();
        }
        $statement->close();
        return is_array($row) ? insight_probes_decrypt_config((string)($row['probe_config_ciphertext'] ?? '')) : [];
    } finally {
        $database->close();
    }
}

function insight_probes_python_arguments(array $probe): array
{
    $probe = insight_probes_preview_defaults($probe);
    return [
        '--site-url', (string)$probe['target'],
        '--probe-type', (string)$probe['probe_type'],
        '--interval-sec', (string)$probe['interval_sec'],
        '--calc-method', (string)$probe['calc_method'],
        '--name', (string)$probe['name'],
        '--active', $probe['active'] ? '1' : '0',
        '--timeout-sec', (string)$probe['timeout_sec'],
        '--retry-count', (string)$probe['retry_count'],
        '--failure-threshold', (string)$probe['failure_threshold'],
        '--recovery-threshold', (string)$probe['recovery_threshold'],
        '--accepted-status-codes', (string)$probe['accepted_status_codes'],
        '--http-methods', (string)$probe['http_method'],
        '--http-primary-method', (string)$probe['http_method'],
        '--http-redirect-modes', (string)$probe['http_redirect'],
        '--http-primary-redirect', (string)$probe['http_redirect'],
        '--keyword-text', (string)$probe['keyword_text'],
        '--keyword-mode', (string)$probe['keyword_mode'],
        '--json-path', (string)$probe['json_path'],
        '--json-expected-value', (string)$probe['json_expected_value'],
        '--request-headers-json', (string)$probe['request_headers_json'],
        '--request-body', (string)$probe['request_body'],
        '--basic-auth-username', (string)$probe['basic_auth_username'],
        '--basic-auth-password-ciphertext', (string)$probe['basic_auth_password_ciphertext'],
        '--probe-config-ciphertext', (string)$probe['probe_config_ciphertext'],
        '--browser-script', (string)$probe['browser_script'],
        '--diagnostics-enabled', $probe['diagnostics_enabled'] ? '1' : '0',
        '--diagnostic-capture-body', $probe['diagnostic_capture_body'] ? '1' : '0',
        '--tls-verify', $probe['tls_verify'] ? '1' : '0',
        '--tls-expiry-threshold-days', (string)$probe['tls_expiry_threshold_days'],
        '--dns-record-type', (string)$probe['dns_record_type'],
        '--dns-expected-value', (string)$probe['dns_expected_value'],
        '--heartbeat-grace-sec', (string)$probe['heartbeat_grace_sec'],
        '--slo-target-percent', (string)$probe['slo_target_percent'],
        '--public-visible', $probe['public_visible'] ? '1' : '0',
    ];
}

function insight_probes_create_database(array $config, array $probe): array
{
    return insight_probes_python_result(insight_python_engine(array_merge([
        'actions',
        'add',
    ], insight_probes_python_arguments($probe))));
}

function insight_probes_update_database(array $config, int $probeId, array $probe): array
{
    return insight_probes_python_result(insight_python_engine(array_merge([
        'actions',
        'update',
        '--site-id',
        (string)$probeId,
    ], insight_probes_python_arguments($probe))));
}

function insight_probes_delete_database(array $config, int $probeId): array
{
    return insight_probes_python_result(insight_python_engine([
        'actions',
        'delete',
        '--site-id',
        (string)$probeId,
    ]));
}

function insight_probes_python_result(array $result): array
{
    if ($result['ok'] ?? false) {
        $result['mode'] = 'database';
        unset($result['exit_code']);
        return $result;
    }
    $error = match ((string)($result['error_code'] ?? '')) {
        'duplicate' => 'admin.probes.errorDuplicate',
        'not_found' => 'admin.probes.errorNotFound',
        'invalid_type' => 'admin.probes.errorType',
        'invalid_interval' => 'admin.probes.errorInterval',
        'invalid_target' => 'admin.probes.errorTarget',
        'invalid_headers' => 'admin.probes.errorHeaders',
        default => 'admin.probes.errorDatabase',
    };
    return [
        'ok' => false,
        'status_code' => (int)($result['status_code'] ?? 503),
        'error' => $error,
    ];
}

function insight_probes_create(array $config, array $input): array
{
    $probe = insight_probes_validate($input);
    if (!($probe['ok'] ?? false)) {
        return ['ok' => false, 'status_code' => 422, 'error' => (string)($probe['error'] ?? 'admin.probes.errorGeneric')];
    }
    try {
        $result = insight_probes_create_database($config, $probe);
        if (($result['ok'] ?? false) && isset($result['probe']['heartbeat_token'])) {
            $base = rtrim((string)($config['public_url'] ?? ''), '/');
            $result['probe']['heartbeat_url'] = ($base !== '' ? $base : '') . '/api/heartbeat.php?token=' . rawurlencode((string)$result['probe']['heartbeat_token']);
        }
        return $result;
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
    try {
        $input['_existing_probe_config'] = insight_probes_existing_advanced_config($config, $probeId);
    } catch (Throwable) {
        $input['_existing_probe_config'] = [];
    }
    $probe = insight_probes_validate($input);
    if (!($probe['ok'] ?? false)) {
        return ['ok' => false, 'status_code' => 422, 'error' => (string)($probe['error'] ?? 'admin.probes.errorGeneric')];
    }
    try {
        $result = insight_probes_update_database($config, $probeId, $probe);
        if (($result['ok'] ?? false) && isset($result['probe']['heartbeat_token'])) {
            $base = rtrim((string)($config['public_url'] ?? ''), '/');
            $result['probe']['heartbeat_url'] = ($base !== '' ? $base : '') . '/api/heartbeat.php?token=' . rawurlencode((string)$result['probe']['heartbeat_token']);
        }
        return $result;
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
