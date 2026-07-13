<?php

declare(strict_types=1);

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/_bootstrap.php';

function insight_notifications_events(): array
{
    return ['monitor_down', 'monitor_up', 'incident_open', 'incident_resolved'];
}

function insight_notifications_templates(): array
{
    return [
        'test' => [
            'title' => '[{{ app_name }}] Test from {{ channel_name }}',
            'body' => 'This is a test message sent by {{ app_name }} at {{ timestamp }}.',
        ],
        'monitor_down' => [
            'title' => '[{{ app_name }}] {{ domain }} is offline',
            'body' => '{{ count }} service{% if count > 1 %}s are{% else %} is{% endif %} unavailable: {{ sites }}. {{ message }}',
        ],
        'monitor_up' => [
            'title' => '[{{ app_name }}] {{ domain }} is back online',
            'body' => '{{ count }} service{% if count > 1 %}s are{% else %} is{% endif %} back online: {{ sites }}. {{ message }}',
        ],
        'incident_open' => [
            'title' => '[{{ app_name }}] Incident opened - {{ domain }}',
            'body' => 'An incident is open for {{ sites }}. {{ message }}',
        ],
        'incident_resolved' => [
            'title' => '[{{ app_name }}] Incident resolved - {{ domain }}',
            'body' => 'The incident affecting {{ sites }} is resolved. {{ message }}',
        ],
    ];
}

function insight_notifications_provider_catalog(): array
{
    return [
        ['id' => 'smtp', 'label' => 'E-mail SMTP', 'icon' => 'fa-regular fa-envelope', 'mode' => 'smtp', 'group' => 'direct'],
        ['id' => 'webhook', 'label' => 'Webhook HTTP', 'icon' => 'fa-solid fa-code', 'mode' => 'webhook', 'group' => 'direct'],
        ['id' => 'free_mobile', 'label' => 'Free Mobile SMS', 'icon' => 'fa-solid fa-mobile-screen', 'mode' => 'free_mobile', 'group' => 'direct'],
        ['id' => 'apprise', 'label' => 'Apprise · 138+ services', 'icon' => 'fa-solid fa-bell', 'mode' => 'apprise', 'group' => 'universal'],
        ['id' => 'discord', 'label' => 'Discord', 'icon' => 'fa-solid fa-gamepad', 'mode' => 'apprise', 'group' => 'messaging'],
        ['id' => 'telegram', 'label' => 'Telegram', 'icon' => 'fa-solid fa-paper-plane', 'mode' => 'apprise', 'group' => 'messaging'],
        ['id' => 'slack', 'label' => 'Slack', 'icon' => 'fa-solid fa-hashtag', 'mode' => 'apprise', 'group' => 'messaging'],
        ['id' => 'teams', 'label' => 'Microsoft Teams', 'icon' => 'fa-solid fa-users', 'mode' => 'apprise', 'group' => 'messaging'],
        ['id' => 'google_chat', 'label' => 'Google Chat', 'icon' => 'fa-regular fa-comments', 'mode' => 'apprise', 'group' => 'messaging'],
        ['id' => 'webex', 'label' => 'Cisco Webex', 'icon' => 'fa-solid fa-video', 'mode' => 'apprise', 'group' => 'messaging'],
        ['id' => 'mattermost', 'label' => 'Mattermost', 'icon' => 'fa-regular fa-message', 'mode' => 'apprise', 'group' => 'messaging'],
        ['id' => 'rocket_chat', 'label' => 'Rocket.Chat', 'icon' => 'fa-solid fa-comments', 'mode' => 'apprise', 'group' => 'messaging'],
        ['id' => 'matrix', 'label' => 'Matrix', 'icon' => 'fa-solid fa-table-cells', 'mode' => 'apprise', 'group' => 'messaging'],
        ['id' => 'signal', 'label' => 'Signal', 'icon' => 'fa-regular fa-comment-dots', 'mode' => 'apprise', 'group' => 'messaging'],
        ['id' => 'whatsapp', 'label' => 'WhatsApp', 'icon' => 'fa-solid fa-comment-dots', 'mode' => 'apprise', 'group' => 'messaging'],
        ['id' => 'ntfy', 'label' => 'ntfy', 'icon' => 'fa-solid fa-paper-plane', 'mode' => 'apprise', 'group' => 'push'],
        ['id' => 'gotify', 'label' => 'Gotify', 'icon' => 'fa-solid fa-bolt', 'mode' => 'apprise', 'group' => 'push'],
        ['id' => 'pushover', 'label' => 'Pushover', 'icon' => 'fa-solid fa-bullhorn', 'mode' => 'apprise', 'group' => 'push'],
        ['id' => 'pushbullet', 'label' => 'Pushbullet', 'icon' => 'fa-solid fa-paper-plane', 'mode' => 'apprise', 'group' => 'push'],
        ['id' => 'pagerduty', 'label' => 'PagerDuty', 'icon' => 'fa-solid fa-phone-volume', 'mode' => 'apprise', 'group' => 'on_call'],
        ['id' => 'pagertree', 'label' => 'PagerTree', 'icon' => 'fa-solid fa-sitemap', 'mode' => 'apprise', 'group' => 'on_call'],
        ['id' => 'opsgenie', 'label' => 'Opsgenie', 'icon' => 'fa-solid fa-wand-magic-sparkles', 'mode' => 'apprise', 'group' => 'on_call'],
        ['id' => 'home_assistant', 'label' => 'Home Assistant', 'icon' => 'fa-solid fa-house-signal', 'mode' => 'apprise', 'group' => 'automation'],
        ['id' => 'power_automate', 'label' => 'Power Automate', 'icon' => 'fa-solid fa-gears', 'mode' => 'apprise', 'group' => 'automation'],
        ['id' => 'sms', 'label' => 'SMS · passerelles Apprise', 'icon' => 'fa-solid fa-comment-sms', 'mode' => 'apprise', 'group' => 'telecom'],
        ['id' => 'twilio', 'label' => 'Twilio', 'icon' => 'fa-solid fa-phone', 'mode' => 'apprise', 'group' => 'telecom'],
        ['id' => 'mailgun', 'label' => 'Mailgun', 'icon' => 'fa-solid fa-at', 'mode' => 'apprise', 'group' => 'email'],
        ['id' => 'sendgrid', 'label' => 'SendGrid', 'icon' => 'fa-solid fa-envelope-open-text', 'mode' => 'apprise', 'group' => 'email'],
    ];
}

function insight_notifications_provider(string $provider): ?array
{
    foreach (insight_notifications_provider_catalog() as $item) {
        if ($item['id'] === $provider) {
            return $item;
        }
    }
    return null;
}

function insight_notifications_mask(): string
{
    return '••••••••';
}

function insight_notifications_key(): string
{
    if (!extension_loaded('sodium')) {
        throw new RuntimeException('admin.notifications.errorSodium');
    }
    $raw = insight_admin_env('INSIGHT_NOTIFICATION_ENCRYPTION_KEY');
    if (strlen($raw) < 32 && insight_auth_dev_bypass_enabled()) {
        $path = dirname(insight_admin_auth_path()) . '/notification.key';
        if (is_file($path) && is_readable($path)) {
            $raw = trim((string)file_get_contents($path));
        }
        if (strlen($raw) < 32) {
            $raw = bin2hex(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
            if (file_put_contents($path, $raw . PHP_EOL, LOCK_EX) === false) {
                throw new RuntimeException('admin.notifications.errorEncryptionKey');
            }
            @chmod($path, 0600);
        }
    }
    if (strlen($raw) < 32) {
        throw new RuntimeException('admin.notifications.errorEncryptionKey');
    }
    if (strlen($raw) === 64 && ctype_xdigit($raw)) {
        $decoded = hex2bin($raw);
        if (is_string($decoded) && strlen($decoded) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            return $decoded;
        }
    }
    try {
        $decoded = sodium_base642bin($raw, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        if (strlen($decoded) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            return $decoded;
        }
    } catch (Throwable) {
    }
    return hash('sha256', $raw, true);
}

function insight_notifications_encrypt(array $config): string
{
    $payload = json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $encrypted = $nonce . sodium_crypto_secretbox($payload, $nonce, insight_notifications_key());
    return 'v1:' . sodium_bin2base64($encrypted, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
}

function insight_notifications_decrypt(string $ciphertext): array
{
    if (!str_starts_with($ciphertext, 'v1:')) {
        throw new RuntimeException('admin.notifications.errorEncryptedConfig');
    }
    try {
        $encrypted = sodium_base642bin(substr($ciphertext, 3), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
    } catch (Throwable) {
        throw new RuntimeException('admin.notifications.errorEncryptedConfig');
    }
    if (strlen($encrypted) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
        throw new RuntimeException('admin.notifications.errorEncryptedConfig');
    }
    $nonce = substr($encrypted, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $payload = sodium_crypto_secretbox_open(substr($encrypted, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, insight_notifications_key());
    if (!is_string($payload)) {
        throw new RuntimeException('admin.notifications.errorEncryptedConfig');
    }
    $decoded = json_decode($payload, true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new RuntimeException('admin.notifications.errorEncryptedConfig');
    }
    return $decoded;
}

function insight_notifications_database(array $config): mysqli
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
    insight_notifications_ensure_schema($database);
    return $database;
}

function insight_notifications_ensure_schema(mysqli $database): void
{
    $queries = [
        "CREATE TABLE IF NOT EXISTS notification_channels (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(120) NOT NULL,
            provider VARCHAR(40) NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            config_ciphertext LONGTEXT NOT NULL,
            events_json TEXT NOT NULL,
            last_test_at DATETIME NULL,
            last_status VARCHAR(16) NOT NULL DEFAULT 'unknown',
            last_error VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_notification_channels_enabled (enabled, provider),
            KEY idx_notification_channels_status (last_status, updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS notification_templates (
            event_key VARCHAR(40) NOT NULL,
            title_template VARCHAR(500) NOT NULL,
            body_template TEXT NOT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (event_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS notification_deliveries (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            channel_id BIGINT UNSIGNED NULL,
            event_key VARCHAR(40) NOT NULL,
            status ENUM('sent','failed','skipped') NOT NULL,
            title_rendered VARCHAR(500) NULL,
            error_message VARCHAR(255) NULL,
            attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_notification_deliveries_channel (channel_id, attempted_at),
            KEY idx_notification_deliveries_status (status, attempted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
    foreach ($queries as $query) {
        if (!$database->query($query)) {
            throw new RuntimeException('database_schema_failed');
        }
    }
    $statement = $database->prepare(
        'INSERT IGNORE INTO notification_templates (event_key, title_template, body_template) VALUES (?, ?, ?)'
    );
    if (!$statement instanceof mysqli_stmt) {
        throw new RuntimeException('database_prepare_failed');
    }
    foreach (insight_notifications_templates() as $event => $template) {
        $title = $template['title'];
        $body = $template['body'];
        $statement->bind_param('sss', $event, $title, $body);
        if (!$statement->execute()) {
            $statement->close();
            throw new RuntimeException('database_template_failed');
        }
    }
    $statement->close();
}

function insight_notifications_preview_path(): string
{
    return dirname(insight_admin_auth_path()) . '/dev-notifications.json';
}

function insight_notifications_preview_read(): array
{
    $fallback = [
        'next_id' => 900001,
        'channels' => [],
        'templates' => insight_notifications_templates(),
        'deliveries' => [],
    ];
    $path = insight_notifications_preview_path();
    if (!is_file($path) || !is_readable($path)) {
        return $fallback;
    }
    $decoded = json_decode((string)file_get_contents($path), true);
    if (!is_array($decoded)) {
        return $fallback;
    }
    return [
        'next_id' => max(900001, (int)($decoded['next_id'] ?? 900001)),
        'channels' => is_array($decoded['channels'] ?? null) ? array_values($decoded['channels']) : [],
        'templates' => is_array($decoded['templates'] ?? null) ? array_replace(insight_notifications_templates(), $decoded['templates']) : insight_notifications_templates(),
        'deliveries' => is_array($decoded['deliveries'] ?? null) ? array_slice(array_values($decoded['deliveries']), 0, 40) : [],
    ];
}

function insight_notifications_preview_write(array $state): void
{
    $path = insight_notifications_preview_path();
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('admin.notifications.errorStorage');
    }
    $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    if (file_put_contents($path, $json . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('admin.notifications.errorStorage');
    }
    @chmod($path, 0600);
}

function insight_notifications_events_from_value(mixed $value): array
{
    if (is_string($value)) {
        $value = json_decode($value, true);
    }
    if (!is_array($value)) {
        return insight_notifications_events();
    }
    return array_values(array_unique(array_filter(
        array_map(static fn(mixed $event): string => trim((string)$event), $value),
        static fn(string $event): bool => in_array($event, insight_notifications_events(), true)
    )));
}

function insight_notifications_config_summary(string $provider, array $config): array
{
    $mode = (string)(insight_notifications_provider($provider)['mode'] ?? 'apprise');
    if ($mode === 'smtp') {
        return [
            'host' => (string)($config['host'] ?? ''),
            'port' => (int)($config['port'] ?? 465),
            'encryption' => (string)($config['encryption'] ?? 'ssl'),
            'username' => (string)($config['username'] ?? ''),
            'from_email' => (string)($config['from_email'] ?? ''),
            'from_name' => (string)($config['from_name'] ?? 'Insight'),
            'to' => (string)($config['to'] ?? ''),
            'has_password' => trim((string)($config['password'] ?? '')) !== '',
        ];
    }
    if ($mode === 'webhook') {
        $host = parse_url((string)($config['url'] ?? ''), PHP_URL_HOST);
        return [
            'endpoint' => is_string($host) ? $host : '',
            'method' => (string)($config['method'] ?? 'POST'),
            'payload_template' => (string)($config['payload_template'] ?? ''),
            'has_url' => trim((string)($config['url'] ?? '')) !== '',
            'has_headers' => trim((string)($config['headers'] ?? '')) !== '',
        ];
    }
    if ($mode === 'free_mobile') {
        return [
            'user' => (string)($config['user'] ?? ''),
            'has_password' => trim((string)($config['password'] ?? '')) !== '',
        ];
    }
    $destinations = preg_split('/[;\r\n]+/', (string)($config['urls'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    return [
        'destination_count' => count($destinations),
        'has_urls' => $destinations !== [],
    ];
}

function insight_notifications_public_channel(array $channel): array
{
    $provider = strtolower(trim((string)($channel['provider'] ?? 'apprise')));
    $catalog = insight_notifications_provider($provider) ?? insight_notifications_provider('apprise');
    $config = [];
    $error = null;
    try {
        $config = insight_notifications_decrypt((string)($channel['config_ciphertext'] ?? ''));
    } catch (Throwable) {
        $error = 'admin.notifications.errorEncryptedConfig';
    }
    return [
        'id' => (int)($channel['id'] ?? 0),
        'name' => (string)($channel['name'] ?? ''),
        'provider' => $provider,
        'provider_label' => (string)($catalog['label'] ?? 'Apprise'),
        'provider_icon' => (string)($catalog['icon'] ?? 'fa-solid fa-bell'),
        'provider_mode' => (string)($catalog['mode'] ?? 'apprise'),
        'enabled' => (int)($channel['enabled'] ?? 0) === 1,
        'events' => insight_notifications_events_from_value($channel['events_json'] ?? []),
        'config' => insight_notifications_config_summary($provider, $config),
        'last_test_at' => $channel['last_test_at'] ?? null,
        'last_status' => (string)($channel['last_status'] ?? 'unknown'),
        'last_error' => $error ?? ($channel['last_error'] ?? null),
        'created_at' => $channel['created_at'] ?? null,
        'updated_at' => $channel['updated_at'] ?? null,
    ];
}

function insight_notifications_string(mixed $value, int $maximum): string
{
    return mb_substr(trim((string)$value), 0, $maximum);
}

function insight_notifications_secret(array $input, array $previous, string $key): string
{
    $value = (string)($input[$key] ?? '');
    if (trim($value) === '' || hash_equals(insight_notifications_mask(), $value)) {
        return (string)($previous[$key] ?? '');
    }
    return $value;
}

function insight_notifications_validate(array $input, array $previousConfig = [], ?string $previousProvider = null): array
{
    $name = insight_notifications_string($input['name'] ?? '', 120);
    $provider = strtolower(insight_notifications_string($input['provider'] ?? '', 40));
    $catalog = insight_notifications_provider($provider);
    if ($name === '') {
        return ['ok' => false, 'error' => 'admin.notifications.errorName'];
    }
    if (!is_array($catalog)) {
        return ['ok' => false, 'error' => 'admin.notifications.errorProvider'];
    }
    if ($previousProvider !== null && $previousProvider !== $provider) {
        $previousConfig = [];
    }
    $events = insight_notifications_events_from_value($input['events'] ?? []);
    if ($events === []) {
        return ['ok' => false, 'error' => 'admin.notifications.errorEvents'];
    }
    $configInput = is_array($input['config'] ?? null) ? $input['config'] : [];
    $mode = (string)$catalog['mode'];
    $config = [];
    if ($mode === 'smtp') {
        $host = insight_notifications_string($configInput['host'] ?? '', 255);
        $port = filter_var($configInput['port'] ?? 465, FILTER_VALIDATE_INT);
        $encryption = strtolower(insight_notifications_string($configInput['encryption'] ?? 'ssl', 12));
        $username = insight_notifications_string($configInput['username'] ?? '', 255);
        $password = insight_notifications_secret($configInput, $previousConfig, 'password');
        $fromEmail = insight_notifications_string($configInput['from_email'] ?? '', 320);
        $fromName = insight_notifications_string($configInput['from_name'] ?? 'Insight', 120);
        $to = insight_notifications_string($configInput['to'] ?? '', 2000);
        if ($host === '' || $port === false || $port < 1 || $port > 65535 || !in_array($encryption, ['ssl', 'tls', 'starttls', 'none'], true)) {
            return ['ok' => false, 'error' => 'admin.notifications.errorSmtp'];
        }
        if (filter_var($fromEmail, FILTER_VALIDATE_EMAIL) === false) {
            return ['ok' => false, 'error' => 'admin.notifications.errorEmail'];
        }
        $recipients = preg_split('/[,;\r\n]+/', $to, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($recipients === [] || count(array_filter($recipients, static fn(string $email): bool => filter_var(trim($email), FILTER_VALIDATE_EMAIL) === false)) > 0) {
            return ['ok' => false, 'error' => 'admin.notifications.errorRecipients'];
        }
        $config = compact('host', 'port', 'encryption', 'username', 'password', 'fromEmail', 'fromName', 'to');
        $config['from_email'] = $config['fromEmail'];
        $config['from_name'] = $config['fromName'];
        unset($config['fromEmail'], $config['fromName']);
    } elseif ($mode === 'webhook') {
        $url = insight_notifications_secret($configInput, $previousConfig, 'url');
        $method = strtoupper(insight_notifications_string($configInput['method'] ?? 'POST', 8));
        $headers = insight_notifications_secret($configInput, $previousConfig, 'headers');
        $payloadTemplate = insight_notifications_string($configInput['payload_template'] ?? '', 20000);
        if (filter_var($url, FILTER_VALIDATE_URL) === false || (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://'))) {
            return ['ok' => false, 'error' => 'admin.notifications.errorWebhook'];
        }
        if (!in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            return ['ok' => false, 'error' => 'admin.notifications.errorWebhookMethod'];
        }
        if ($headers !== '') {
            $decodedHeaders = json_decode($headers, true);
            if (!is_array($decodedHeaders) || array_is_list($decodedHeaders)) {
                return ['ok' => false, 'error' => 'admin.notifications.errorHeaders'];
            }
        }
        $config = ['url' => $url, 'method' => $method, 'headers' => $headers, 'payload_template' => $payloadTemplate];
    } elseif ($mode === 'free_mobile') {
        $user = insight_notifications_string($configInput['user'] ?? '', 120);
        $password = insight_notifications_secret($configInput, $previousConfig, 'password');
        if ($user === '' || $password === '') {
            return ['ok' => false, 'error' => 'admin.notifications.errorFreeMobile'];
        }
        $config = compact('user', 'password');
    } else {
        $urls = insight_notifications_secret($configInput, $previousConfig, 'urls');
        if ($urls === '' || strlen($urls) > 30000) {
            return ['ok' => false, 'error' => 'admin.notifications.errorApprise'];
        }
        $destinations = preg_split('/[;\r\n]+/', $urls, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($destinations === [] || count(array_filter($destinations, static fn(string $url): bool => !str_contains(trim($url), '://'))) > 0) {
            return ['ok' => false, 'error' => 'admin.notifications.errorApprise'];
        }
        $config = ['urls' => $urls];
    }
    return [
        'ok' => true,
        'name' => $name,
        'provider' => $provider,
        'enabled' => filter_var($input['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN),
        'events' => $events,
        'config' => $config,
    ];
}

function insight_notifications_python(string $action, array $payload): array
{
    if (!function_exists('proc_open')) {
        return ['ok' => false, 'error' => 'admin.notifications.errorPython'];
    }
    require_once dirname(__DIR__, 2) . '/monitoring/python_bridge.php';
    $python = function_exists('resolve_python_bin') ? resolve_python_bin() : 'python3';
    $script = dirname(__DIR__, 2) . '/monitoring/python_monitoring/notification_cli.py';
    $command = [$python, $script, $action, '--root', dirname(__DIR__, 2) . '/monitoring'];
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $environment = getenv();
    if (!is_array($environment)) {
        $environment = [];
    }
    $pythonPath = function_exists('resolve_python_path') ? resolve_python_path() : '';
    if ($pythonPath !== '') {
        $environment['PYTHONPATH'] = $pythonPath
            . (isset($environment['PYTHONPATH']) && $environment['PYTHONPATH'] !== '' ? PATH_SEPARATOR . $environment['PYTHONPATH'] : '');
    }
    $process = @proc_open($command, $descriptors, $pipes, dirname(__DIR__, 2), $environment);
    if (!is_resource($process)) {
        return ['ok' => false, 'error' => 'admin.notifications.errorPython'];
    }
    fwrite($pipes[0], json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $stdout = '';
    $stderr = '';
    $deadline = microtime(true) + 30;
    $timedOut = false;
    while (true) {
        $stdout .= (string)stream_get_contents($pipes[1]);
        $stderr .= (string)stream_get_contents($pipes[2]);
        $status = proc_get_status($process);
        if (!($status['running'] ?? false)) {
            break;
        }
        if (microtime(true) >= $deadline) {
            $timedOut = true;
            proc_terminate($process);
            usleep(100000);
            $status = proc_get_status($process);
            if ($status['running'] ?? false) {
                proc_terminate($process, 9);
            }
            break;
        }
        usleep(20000);
    }
    $stdout .= (string)stream_get_contents($pipes[1]);
    $stderr .= (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($timedOut) {
        return ['ok' => false, 'error' => 'admin.notifications.errorTimeout'];
    }
    $lines = preg_split('/\R+/', trim((string)$stdout)) ?: [];
    $decoded = null;
    foreach (array_reverse($lines) as $line) {
        $candidate = json_decode(trim($line), true);
        if (is_array($candidate)) {
            $decoded = $candidate;
            break;
        }
    }
    if (!is_array($decoded)) {
        return [
            'ok' => false,
            'error' => 'admin.notifications.errorPython',
            'details' => mb_substr(trim((string)$stderr), 0, 255),
        ];
    }
    $decoded['exit_code'] = $exitCode;
    return $decoded;
}

function insight_notifications_template_context(array $config): array
{
    return [
        'app_name' => (string)($config['app_name'] ?? 'Insight'),
        'public_url' => (string)($config['public_url'] ?? ''),
        'timestamp' => (new DateTimeImmutable())->format(DATE_ATOM),
        'domain' => 'api.example.com',
        'sites' => 'api.example.com, status.example.com',
        'site_url' => 'https://api.example.com',
        'count' => 2,
        'status' => 'offline',
        'message' => 'The availability threshold was exceeded.',
    ];
}

function insight_notifications_validate_template(array $config, string $event, string $title, string $body): array
{
    if (!array_key_exists($event, insight_notifications_templates()) || $title === '' || mb_strlen($title) > 500 || $body === '' || mb_strlen($body) > 10000) {
        return ['ok' => false, 'error' => 'admin.notifications.errorTemplate'];
    }
    $rendered = insight_notifications_python('render', [
        'event' => $event,
        'context' => insight_notifications_template_context($config),
        'templates' => ['title' => $title, 'body' => $body],
    ]);
    if (!($rendered['ok'] ?? false)) {
        return ['ok' => false, 'error' => 'admin.notifications.errorTemplateSyntax', 'details' => $rendered['error'] ?? null];
    }
    return ['ok' => true];
}

function insight_notifications_validate_webhook_payload(array $config, array $channel): array
{
    if (($channel['provider'] ?? '') !== 'webhook') {
        return ['ok' => true];
    }
    $payloadTemplate = trim((string)($channel['config']['payload_template'] ?? ''));
    if ($payloadTemplate === '') {
        return ['ok' => true];
    }
    $context = insight_notifications_template_context($config);
    $context['title'] = '[Insight] Test webhook';
    $context['body'] = 'Message de validation Insight.';
    $rendered = insight_notifications_python('render', [
        'event' => 'test',
        'context' => $context,
        'templates' => ['title' => 'Webhook', 'body' => $payloadTemplate],
    ]);
    if (!($rendered['ok'] ?? false)) {
        return [
            'ok' => false,
            'error' => 'admin.notifications.errorWebhookPayload',
            'details' => mb_substr((string)($rendered['error'] ?? $rendered['details'] ?? ''), 0, 255),
        ];
    }
    json_decode((string)($rendered['body'] ?? ''));
    return json_last_error() === JSON_ERROR_NONE
        ? ['ok' => true]
        : ['ok' => false, 'error' => 'admin.notifications.errorWebhookPayload'];
}

function insight_notifications_database_state(array $config): array
{
    $database = insight_notifications_database($config);
    $channelsResult = $database->query('SELECT * FROM notification_channels ORDER BY enabled DESC, name ASC, id ASC');
    $templatesResult = $database->query('SELECT event_key, title_template, body_template, updated_at FROM notification_templates ORDER BY event_key');
    $deliveriesResult = $database->query(
        'SELECT d.id, d.channel_id, COALESCE(c.name, "Deleted channel") AS channel_name, d.event_key, d.status, d.title_rendered, d.error_message, d.attempted_at
         FROM notification_deliveries d LEFT JOIN notification_channels c ON c.id = d.channel_id ORDER BY d.id DESC LIMIT 20'
    );
    if (!$channelsResult instanceof mysqli_result || !$templatesResult instanceof mysqli_result || !$deliveriesResult instanceof mysqli_result) {
        $database->close();
        throw new RuntimeException('database_read_failed');
    }
    $channels = array_map('insight_notifications_public_channel', $channelsResult->fetch_all(MYSQLI_ASSOC));
    $templates = [];
    foreach ($templatesResult->fetch_all(MYSQLI_ASSOC) as $row) {
        $templates[(string)$row['event_key']] = [
            'title' => (string)$row['title_template'],
            'body' => (string)$row['body_template'],
            'updated_at' => $row['updated_at'],
        ];
    }
    $deliveries = $deliveriesResult->fetch_all(MYSQLI_ASSOC);
    $channelsResult->free();
    $templatesResult->free();
    $deliveriesResult->free();
    $database->close();
    return ['mode' => 'database', 'channels' => $channels, 'templates' => $templates, 'deliveries' => $deliveries];
}

function insight_notifications_preview_state(): array
{
    $state = insight_notifications_preview_read();
    return [
        'mode' => 'preview',
        'channels' => array_map('insight_notifications_public_channel', $state['channels']),
        'templates' => $state['templates'],
        'deliveries' => $state['deliveries'],
    ];
}

function insight_notifications_state(array $config): array
{
    try {
        $state = insight_notifications_database_state($config);
    } catch (Throwable) {
        if (!insight_auth_dev_bypass_enabled()) {
            return [
                'ok' => false,
                'status_code' => 503,
                'error' => 'admin.notifications.errorDatabase',
                'mode' => 'unavailable',
                'channels' => [],
                'templates' => insight_notifications_templates(),
                'deliveries' => [],
            ];
        }
        try {
            $state = insight_notifications_preview_state();
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'status_code' => 500,
                'error' => $exception->getMessage(),
                'mode' => 'unavailable',
                'channels' => [],
                'templates' => insight_notifications_templates(),
                'deliveries' => [],
            ];
        }
    }
    return [
        'ok' => true,
        'status_code' => 200,
        'catalog' => insight_notifications_provider_catalog(),
        'events' => insight_notifications_events(),
        'notifications_disabled' => insight_admin_env_bool('INSIGHT_DISABLE_NOTIFICATIONS', true),
        ...$state,
    ];
}

function insight_notifications_create_database(array $config, array $channel): array
{
    $database = insight_notifications_database($config);
    $statement = $database->prepare(
        'INSERT INTO notification_channels (name, provider, enabled, config_ciphertext, events_json) VALUES (?, ?, ?, ?, ?)'
    );
    if (!$statement instanceof mysqli_stmt) {
        $database->close();
        throw new RuntimeException('database_prepare_failed');
    }
    $name = $channel['name'];
    $provider = $channel['provider'];
    $enabled = $channel['enabled'] ? 1 : 0;
    $ciphertext = insight_notifications_encrypt($channel['config']);
    $events = json_encode($channel['events'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $statement->bind_param('ssiss', $name, $provider, $enabled, $ciphertext, $events);
    if (!$statement->execute()) {
        $statement->close();
        $database->close();
        throw new RuntimeException('database_insert_failed');
    }
    $id = (int)$statement->insert_id;
    $statement->close();
    $database->close();
    return ['ok' => true, 'status_code' => 201, 'id' => $id, 'mode' => 'database'];
}

function insight_notifications_create_preview(array $channel): array
{
    $state = insight_notifications_preview_read();
    $id = (int)$state['next_id'];
    $state['next_id'] = $id + 1;
    $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
    $state['channels'][] = [
        'id' => $id,
        'name' => $channel['name'],
        'provider' => $channel['provider'],
        'enabled' => $channel['enabled'] ? 1 : 0,
        'config_ciphertext' => insight_notifications_encrypt($channel['config']),
        'events_json' => $channel['events'],
        'last_test_at' => null,
        'last_status' => 'unknown',
        'last_error' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ];
    insight_notifications_preview_write($state);
    return ['ok' => true, 'status_code' => 201, 'id' => $id, 'mode' => 'preview'];
}

function insight_notifications_create(array $config, array $input): array
{
    $channel = insight_notifications_validate($input);
    if (!($channel['ok'] ?? false)) {
        return ['ok' => false, 'status_code' => 422, 'error' => $channel['error']];
    }
    $payloadValidation = insight_notifications_validate_webhook_payload($config, $channel);
    if (!($payloadValidation['ok'] ?? false)) {
        return ['ok' => false, 'status_code' => 422, 'error' => $payloadValidation['error']];
    }
    try {
        return insight_notifications_create_database($config, $channel);
    } catch (Throwable $exception) {
        if (!insight_auth_dev_bypass_enabled()) {
            return ['ok' => false, 'status_code' => 503, 'error' => $exception->getMessage() === 'admin.notifications.errorEncryptionKey' ? $exception->getMessage() : 'admin.notifications.errorDatabase'];
        }
        try {
            return insight_notifications_create_preview($channel);
        } catch (Throwable $previewException) {
            return ['ok' => false, 'status_code' => 500, 'error' => $previewException->getMessage()];
        }
    }
}

function insight_notifications_find_database(array $config, int $id): array
{
    $database = insight_notifications_database($config);
    $statement = $database->prepare('SELECT * FROM notification_channels WHERE id = ? LIMIT 1');
    if (!$statement instanceof mysqli_stmt) {
        $database->close();
        throw new RuntimeException('database_prepare_failed');
    }
    $statement->bind_param('i', $id);
    $statement->execute();
    $row = $statement->get_result()->fetch_assoc();
    $statement->close();
    $database->close();
    if (!is_array($row)) {
        throw new OutOfBoundsException('admin.notifications.errorNotFound');
    }
    return $row;
}

function insight_notifications_find_preview(int $id): array
{
    foreach (insight_notifications_preview_read()['channels'] as $channel) {
        if ((int)($channel['id'] ?? 0) === $id) {
            return $channel;
        }
    }
    throw new OutOfBoundsException('admin.notifications.errorNotFound');
}

function insight_notifications_find(array $config, int $id): array
{
    try {
        return insight_notifications_find_database($config, $id);
    } catch (OutOfBoundsException $exception) {
        throw $exception;
    } catch (Throwable) {
        if (insight_auth_dev_bypass_enabled()) {
            return insight_notifications_find_preview($id);
        }
        throw new RuntimeException('admin.notifications.errorDatabase');
    }
}

function insight_notifications_update_database(array $config, int $id, array $channel): array
{
    $database = insight_notifications_database($config);
    $statement = $database->prepare(
        'UPDATE notification_channels SET name = ?, provider = ?, enabled = ?, config_ciphertext = ?, events_json = ? WHERE id = ?'
    );
    if (!$statement instanceof mysqli_stmt) {
        $database->close();
        throw new RuntimeException('database_prepare_failed');
    }
    $name = $channel['name'];
    $provider = $channel['provider'];
    $enabled = $channel['enabled'] ? 1 : 0;
    $ciphertext = insight_notifications_encrypt($channel['config']);
    $events = json_encode($channel['events'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $statement->bind_param('ssissi', $name, $provider, $enabled, $ciphertext, $events, $id);
    if (!$statement->execute()) {
        $statement->close();
        $database->close();
        throw new RuntimeException('database_update_failed');
    }
    $statement->close();
    $database->close();
    return ['ok' => true, 'status_code' => 200, 'id' => $id, 'mode' => 'database'];
}

function insight_notifications_update_preview(int $id, array $channel): array
{
    $state = insight_notifications_preview_read();
    foreach ($state['channels'] as $index => $stored) {
        if ((int)($stored['id'] ?? 0) !== $id) {
            continue;
        }
        $state['channels'][$index] = array_replace($stored, [
            'name' => $channel['name'],
            'provider' => $channel['provider'],
            'enabled' => $channel['enabled'] ? 1 : 0,
            'config_ciphertext' => insight_notifications_encrypt($channel['config']),
            'events_json' => $channel['events'],
            'updated_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
        insight_notifications_preview_write($state);
        return ['ok' => true, 'status_code' => 200, 'id' => $id, 'mode' => 'preview'];
    }
    return ['ok' => false, 'status_code' => 404, 'error' => 'admin.notifications.errorNotFound'];
}

function insight_notifications_update(array $config, int $id, array $input): array
{
    if ($id < 1) {
        return ['ok' => false, 'status_code' => 404, 'error' => 'admin.notifications.errorNotFound'];
    }
    try {
        $stored = insight_notifications_find($config, $id);
        $previous = insight_notifications_decrypt((string)$stored['config_ciphertext']);
        $channel = insight_notifications_validate($input, $previous, (string)$stored['provider']);
        if (!($channel['ok'] ?? false)) {
            return ['ok' => false, 'status_code' => 422, 'error' => $channel['error']];
        }
        $payloadValidation = insight_notifications_validate_webhook_payload($config, $channel);
        if (!($payloadValidation['ok'] ?? false)) {
            return ['ok' => false, 'status_code' => 422, 'error' => $payloadValidation['error']];
        }
        if (insight_auth_dev_bypass_enabled() && (int)$id >= 900000) {
            return insight_notifications_update_preview($id, $channel);
        }
        return insight_notifications_update_database($config, $id, $channel);
    } catch (OutOfBoundsException $exception) {
        return ['ok' => false, 'status_code' => 404, 'error' => $exception->getMessage()];
    } catch (Throwable $exception) {
        return ['ok' => false, 'status_code' => 503, 'error' => $exception->getMessage()];
    }
}

function insight_notifications_delete_database(array $config, int $id): array
{
    $database = insight_notifications_database($config);
    $statement = $database->prepare('DELETE FROM notification_channels WHERE id = ?');
    if (!$statement instanceof mysqli_stmt) {
        $database->close();
        throw new RuntimeException('database_prepare_failed');
    }
    $statement->bind_param('i', $id);
    $statement->execute();
    $deleted = $statement->affected_rows > 0;
    $statement->close();
    $database->close();
    return $deleted
        ? ['ok' => true, 'status_code' => 200, 'deleted_id' => $id, 'mode' => 'database']
        : ['ok' => false, 'status_code' => 404, 'error' => 'admin.notifications.errorNotFound'];
}

function insight_notifications_delete_preview(int $id): array
{
    $state = insight_notifications_preview_read();
    $channels = array_values(array_filter($state['channels'], static fn(array $channel): bool => (int)($channel['id'] ?? 0) !== $id));
    if (count($channels) === count($state['channels'])) {
        return ['ok' => false, 'status_code' => 404, 'error' => 'admin.notifications.errorNotFound'];
    }
    $state['channels'] = $channels;
    insight_notifications_preview_write($state);
    return ['ok' => true, 'status_code' => 200, 'deleted_id' => $id, 'mode' => 'preview'];
}

function insight_notifications_delete(array $config, int $id): array
{
    if ($id < 1) {
        return ['ok' => false, 'status_code' => 404, 'error' => 'admin.notifications.errorNotFound'];
    }
    if (insight_auth_dev_bypass_enabled() && $id >= 900000) {
        return insight_notifications_delete_preview($id);
    }
    try {
        return insight_notifications_delete_database($config, $id);
    } catch (Throwable) {
        return ['ok' => false, 'status_code' => 503, 'error' => 'admin.notifications.errorDatabase'];
    }
}

function insight_notifications_record_preview_delivery(int $channelId, string $channelName, bool $ok, string $title, string $error): void
{
    $state = insight_notifications_preview_read();
    array_unshift($state['deliveries'], [
        'id' => (int)(microtime(true) * 1000),
        'channel_id' => $channelId,
        'channel_name' => $channelName,
        'event_key' => 'test',
        'status' => $ok ? 'sent' : 'failed',
        'title_rendered' => $title,
        'error_message' => $error !== '' ? $error : null,
        'attempted_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
    ]);
    $state['deliveries'] = array_slice($state['deliveries'], 0, 40);
    foreach ($state['channels'] as $index => $channel) {
        if ((int)($channel['id'] ?? 0) === $channelId) {
            $state['channels'][$index]['last_test_at'] = (new DateTimeImmutable())->format('Y-m-d H:i:s');
            $state['channels'][$index]['last_status'] = $ok ? 'success' : 'error';
            $state['channels'][$index]['last_error'] = $error !== '' ? $error : null;
            break;
        }
    }
    insight_notifications_preview_write($state);
}

function insight_notifications_record_database_delivery(array $config, int $channelId, bool $ok, string $title, string $error): void
{
    $database = insight_notifications_database($config);
    $status = $ok ? 'sent' : 'failed';
    $statement = $database->prepare(
        'INSERT INTO notification_deliveries (channel_id, event_key, status, title_rendered, error_message) VALUES (?, "test", ?, ?, NULLIF(?, ""))'
    );
    if ($statement instanceof mysqli_stmt) {
        $statement->bind_param('isss', $channelId, $status, $title, $error);
        $statement->execute();
        $statement->close();
    }
    $lastStatus = $ok ? 'success' : 'error';
    $update = $database->prepare(
        'UPDATE notification_channels SET last_test_at = NOW(), last_status = ?, last_error = NULLIF(?, "") WHERE id = ?'
    );
    if ($update instanceof mysqli_stmt) {
        $update->bind_param('ssi', $lastStatus, $error, $channelId);
        $update->execute();
        $update->close();
    }
    $database->close();
}

function insight_notifications_test(array $config, int $id): array
{
    if ($id < 1) {
        return ['ok' => false, 'status_code' => 404, 'error' => 'admin.notifications.errorNotFound'];
    }
    try {
        $stored = insight_notifications_find($config, $id);
        $channelConfig = insight_notifications_decrypt((string)$stored['config_ciphertext']);
        $result = insight_notifications_python('send', [
            'event' => 'test',
            'channel' => [
                'name' => (string)$stored['name'],
                'provider' => (string)$stored['provider'],
                'config' => $channelConfig,
            ],
            'context' => insight_notifications_template_context($config),
            'templates' => insight_notifications_templates()['test'],
        ]);
        $ok = (bool)($result['ok'] ?? false);
        $title = insight_notifications_string($result['title'] ?? '', 500);
        $error = $ok ? '' : insight_notifications_string($result['error'] ?? 'admin.notifications.errorTest', 255);
        if (insight_auth_dev_bypass_enabled() && $id >= 900000) {
            insight_notifications_record_preview_delivery($id, (string)$stored['name'], $ok, $title, $error);
        } else {
            insight_notifications_record_database_delivery($config, $id, $ok, $title, $error);
        }
        return [
            'ok' => $ok,
            'status_code' => $ok ? 200 : 502,
            'error' => $ok ? null : $error,
            'details' => $result['details'] ?? null,
        ];
    } catch (OutOfBoundsException $exception) {
        return ['ok' => false, 'status_code' => 404, 'error' => $exception->getMessage()];
    } catch (Throwable $exception) {
        return ['ok' => false, 'status_code' => 500, 'error' => $exception->getMessage()];
    }
}

function insight_notifications_update_template_database(array $config, string $event, string $title, string $body): array
{
    $database = insight_notifications_database($config);
    $statement = $database->prepare(
        'INSERT INTO notification_templates (event_key, title_template, body_template) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE title_template = VALUES(title_template), body_template = VALUES(body_template)'
    );
    if (!$statement instanceof mysqli_stmt) {
        $database->close();
        throw new RuntimeException('database_prepare_failed');
    }
    $statement->bind_param('sss', $event, $title, $body);
    $statement->execute();
    $statement->close();
    $database->close();
    return ['ok' => true, 'status_code' => 200, 'event' => $event, 'mode' => 'database'];
}

function insight_notifications_update_template_preview(string $event, string $title, string $body): array
{
    $state = insight_notifications_preview_read();
    $state['templates'][$event] = [
        'title' => $title,
        'body' => $body,
        'updated_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
    ];
    insight_notifications_preview_write($state);
    return ['ok' => true, 'status_code' => 200, 'event' => $event, 'mode' => 'preview'];
}

function insight_notifications_update_template(array $config, array $input): array
{
    $event = insight_notifications_string($input['event'] ?? '', 40);
    $title = insight_notifications_string($input['title'] ?? '', 500);
    $body = insight_notifications_string($input['body'] ?? '', 10000);
    $validation = insight_notifications_validate_template($config, $event, $title, $body);
    if (!($validation['ok'] ?? false)) {
        return ['ok' => false, 'status_code' => 422, 'error' => $validation['error'], 'details' => $validation['details'] ?? null];
    }
    try {
        return insight_notifications_update_template_database($config, $event, $title, $body);
    } catch (Throwable) {
        if (insight_auth_dev_bypass_enabled()) {
            return insight_notifications_update_template_preview($event, $title, $body);
        }
        return ['ok' => false, 'status_code' => 503, 'error' => 'admin.notifications.errorDatabase'];
    }
}
