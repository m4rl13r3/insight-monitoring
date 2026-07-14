<?php

declare(strict_types=1);

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}

function insight_status_page_database(array $config): ?mysqli
{
    mysqli_report(MYSQLI_REPORT_OFF);
    $database = @new mysqli((string)$config['servername'], (string)$config['username'], (string)$config['password'], (string)$config['dbname'], (int)$config['port']);
    if ($database->connect_errno) {
        return null;
    }
    $database->set_charset('utf8mb4');
    return $database;
}

function insight_status_page_requested_slug(): string
{
    $slug = strtolower(trim((string)($_GET['page'] ?? $_SERVER['HTTP_X_INSIGHT_STATUS_PAGE'] ?? '')));
    return preg_match('/^[a-z0-9-]{1,120}$/', $slug) === 1 ? $slug : '';
}

function insight_status_page_resolve(array $config, ?mysqli $database = null): array
{
    $ownsDatabase = !$database instanceof mysqli;
    $database = $database instanceof mysqli ? $database : insight_status_page_database($config);
    if (!$database instanceof mysqli) {
        return ['id' => 0, 'slug' => 'default', 'name' => (string)($config['app_name'] ?? 'Insight'), 'visibility' => 'public', 'enabled' => 1];
    }
    try {
        $slug = insight_status_page_requested_slug();
        $host = strtolower(trim(explode(':', (string)($_SERVER['HTTP_HOST'] ?? ''))[0]));
        if ($slug !== '') {
            $statement = $database->prepare('SELECT * FROM status_pages WHERE slug=? AND enabled=1 LIMIT 1');
            $statement->bind_param('s', $slug);
        } elseif ($host !== '' && !in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            $statement = $database->prepare("SELECT * FROM status_pages WHERE (custom_domain=? OR slug='default') AND enabled=1 ORDER BY custom_domain IS NULL ASC LIMIT 1");
            $statement->bind_param('s', $host);
        } else {
            $statement = $database->prepare("SELECT * FROM status_pages WHERE slug='default' AND enabled=1 LIMIT 1");
        }
        if (!$statement instanceof mysqli_stmt) {
            return ['id' => 0, 'slug' => 'default', 'name' => (string)($config['app_name'] ?? 'Insight'), 'visibility' => 'public', 'enabled' => 1];
        }
        $statement->execute();
        $page = $statement->get_result()->fetch_assoc();
        $statement->close();
        return is_array($page) ? $page : ['id' => 0, 'slug' => 'default', 'name' => (string)($config['app_name'] ?? 'Insight'), 'visibility' => 'public', 'enabled' => 1];
    } finally {
        if ($ownsDatabase) {
            $database->close();
        }
    }
}

function insight_status_page_cookie_name(int $pageId): string
{
    return 'insight_status_page_' . max(0, $pageId);
}

function insight_status_page_cookie_value(int $pageId, string $passwordHash, int $expiresAt): string
{
    $secret = (string)(getenv('INSIGHT_STATUS_PAGE_COOKIE_SECRET') ?: getenv('INSIGHT_NOTIFICATION_ENCRYPTION_KEY') ?: '');
    if (strlen($secret) < 32 || $pageId < 1 || $expiresAt <= time()) {
        return '';
    }
    $payload = $pageId . ':' . hash('sha256', $passwordHash) . ':' . $expiresAt;
    return $expiresAt . '.' . hash_hmac('sha256', $payload, $secret);
}

function insight_status_page_access_policy(array $page): string
{
    $policy = strtolower(trim((string)($page['access_policy'] ?? '')));
    if (in_array($policy, ['public', 'password', 'sso', 'ip_allowlist'], true)) {
        return $policy;
    }
    return ($page['visibility'] ?? 'public') === 'private' ? 'password' : 'public';
}

function insight_status_page_access_fingerprint(array $page): string
{
    return insight_status_page_access_policy($page) . ':' . (string)($page['password_hash'] ?? '') . ':' . (string)($page['updated_at'] ?? '');
}

function insight_status_page_ip_matches(string $address, string $rule): bool
{
    $parts = explode('/', trim($rule), 2);
    $addressPacked = @inet_pton($address);
    $networkPacked = @inet_pton($parts[0]);
    if ($addressPacked === false || $networkPacked === false || strlen($addressPacked) !== strlen($networkPacked)) {
        return false;
    }
    $prefix = isset($parts[1]) && ctype_digit($parts[1]) ? (int)$parts[1] : strlen($networkPacked) * 8;
    if ($prefix < 0 || $prefix > strlen($networkPacked) * 8) {
        return false;
    }
    $bytes = intdiv($prefix, 8);
    $bits = $prefix % 8;
    if ($bytes > 0 && substr($addressPacked, 0, $bytes) !== substr($networkPacked, 0, $bytes)) {
        return false;
    }
    if ($bits === 0) {
        return true;
    }
    $mask = (0xff << (8 - $bits)) & 0xff;
    return (ord($addressPacked[$bytes]) & $mask) === (ord($networkPacked[$bytes]) & $mask);
}

function insight_status_page_ip_authorized(array $page): bool
{
    $address = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    if ($address === '') {
        return false;
    }
    $rules = array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', (string)($page['ip_allowlist'] ?? '')) ?: [])));
    return array_filter($rules, static fn(string $rule): bool => insight_status_page_ip_matches($address, $rule)) !== [];
}

function insight_status_page_authorized(array $page): bool
{
    $policy = insight_status_page_access_policy($page);
    if ($policy === 'public') {
        return true;
    }
    if ($policy === 'ip_allowlist') {
        return insight_status_page_ip_authorized($page);
    }
    $received = (string)($_COOKIE[insight_status_page_cookie_name((int)($page['id'] ?? 0))] ?? '');
    $parts = explode('.', $received, 2);
    $expiresAt = isset($parts[0]) && ctype_digit($parts[0]) ? (int)$parts[0] : 0;
    if ($expiresAt <= time() || $expiresAt > time() + 86400 || !isset($parts[1])) {
        return false;
    }
    $expected = insight_status_page_cookie_value((int)($page['id'] ?? 0), insight_status_page_access_fingerprint($page), $expiresAt);
    return $expected !== '' && hash_equals($expected, $received);
}

function insight_status_page_site_urls(mysqli $database, array $page): array
{
    $pageId = (int)($page['id'] ?? 0);
    if ($pageId <= 0) {
        $result = $database->query('SELECT url FROM sites WHERE active=1 AND public_visible=1 ORDER BY id');
    } else {
        $countResult = $database->query('SELECT COUNT(*) AS total FROM status_page_monitors WHERE status_page_id=' . $pageId);
        $count = $countResult instanceof mysqli_result ? $countResult->fetch_assoc() : null;
        if ((int)($count['total'] ?? 0) > 0) {
            $result = $database->query("SELECT s.url FROM status_page_monitors m INNER JOIN sites s ON s.id=m.site_id WHERE m.status_page_id={$pageId} AND m.visible=1 AND s.active=1 AND s.public_visible=1 ORDER BY m.sort_order,m.site_id");
        } elseif (($page['slug'] ?? '') === 'default') {
            $result = $database->query('SELECT url FROM sites WHERE active=1 AND public_visible=1 ORDER BY id');
        } else {
            return [];
        }
    }
    return $result instanceof mysqli_result ? array_values(array_map(static fn(array $row): string => (string)$row['url'], $result->fetch_all(MYSQLI_ASSOC))) : [];
}

function insight_status_page_layout(array $config, array $page, ?mysqli $database = null): array
{
    $pageId = (int)($page['id'] ?? 0);
    if ($pageId <= 0) {
        return ['groups' => [], 'ungrouped' => []];
    }
    $ownsDatabase = !$database instanceof mysqli;
    $database = $database instanceof mysqli ? $database : insight_status_page_database($config);
    if (!$database instanceof mysqli) {
        return ['groups' => [], 'ungrouped' => []];
    }
    try {
        $groupsResult = $database->query("SELECT id,name,collapsed FROM status_page_groups WHERE status_page_id={$pageId} ORDER BY sort_order,id");
        $monitorsResult = $database->query("SELECT m.site_id,m.group_id,COALESCE(NULLIF(m.display_name,''),NULLIF(s.name,''),s.url) AS label,s.url FROM status_page_monitors m INNER JOIN sites s ON s.id=m.site_id WHERE m.status_page_id={$pageId} AND m.visible=1 AND s.active=1 AND s.public_visible=1 ORDER BY m.sort_order,m.site_id");
        $groups = $groupsResult instanceof mysqli_result ? $groupsResult->fetch_all(MYSQLI_ASSOC) : [];
        $monitors = $monitorsResult instanceof mysqli_result ? $monitorsResult->fetch_all(MYSQLI_ASSOC) : [];
        $byGroup = [];
        $sitesByGroup = [];
        $ungrouped = [];
        $ungroupedSites = [];
        foreach ($monitors as $monitor) {
            $url = (string)($monitor['url'] ?? '');
            $site = ['id' => (int)($monitor['site_id'] ?? 0), 'label' => (string)($monitor['label'] ?? $url), 'url' => $url];
            $groupId = $monitor['group_id'] === null ? 0 : (int)$monitor['group_id'];
            if ($groupId > 0) {
                $byGroup[$groupId][] = $url;
                $sitesByGroup[$groupId][] = $site;
            } else {
                $ungrouped[] = $url;
                $ungroupedSites[] = $site;
            }
        }
        return [
            'groups' => array_values(array_map(static fn(array $group): array => [
                'name' => (string)$group['name'],
                'collapsed' => (int)$group['collapsed'] === 1,
                'site_urls' => array_values($byGroup[(int)$group['id']] ?? []),
                'sites' => array_values($sitesByGroup[(int)$group['id']] ?? []),
            ], $groups)),
            'ungrouped' => array_values($ungrouped),
            'ungrouped_sites' => array_values($ungroupedSites),
        ];
    } finally {
        if ($ownsDatabase) {
            $database->close();
        }
    }
}
