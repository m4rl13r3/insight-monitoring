<?php

declare(strict_types=1);

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_probes.php';

function insight_status_pages_url(string $value, bool $allowRelative = true): ?string
{
    $url = trim($value);
    if ($url === '') {
        return '';
    }
    if ($allowRelative && preg_match('#^/(?!/)#', $url) === 1 && !str_contains($url, "\n")) {
        return mb_substr($url, 0, 1000, 'UTF-8');
    }
    if (filter_var($url, FILTER_VALIDATE_URL) === false || !str_starts_with(strtolower($url), 'https://')) {
        return null;
    }
    return mb_substr($url, 0, 1000, 'UTF-8');
}

function insight_status_pages_ip_rule_valid(string $rule): bool
{
    $parts = explode('/', trim($rule), 2);
    $packed = @inet_pton($parts[0]);
    if ($packed === false) {
        return false;
    }
    if (!isset($parts[1])) {
        return true;
    }
    if ($parts[1] === '' || !ctype_digit($parts[1])) {
        return false;
    }
    $maximum = strlen($packed) === 4 ? 32 : 128;
    return (int)$parts[1] >= 0 && (int)$parts[1] <= $maximum;
}

function insight_status_pages_navigation(mixed $input): ?array
{
    if (is_string($input)) {
        $trimmed = trim($input);
        if ($trimmed === '') {
            return [];
        }
        if (str_starts_with($trimmed, '[')) {
            $input = json_decode($trimmed, true);
        } else {
            $input = array_values(array_filter(array_map(static function (string $line): array {
                $parts = array_map('trim', explode('|', $line, 2));
                return ['label' => $parts[0] ?? '', 'url' => $parts[1] ?? ''];
            }, preg_split('/\R/u', $trimmed) ?: []), static fn(array $item): bool => $item['label'] !== '' || $item['url'] !== ''));
        }
    }
    if (!is_array($input) || count($input) > 8) {
        return null;
    }
    $links = [];
    foreach ($input as $item) {
        if (!is_array($item)) {
            return null;
        }
        $label = mb_substr(trim((string)($item['label'] ?? '')), 0, 80, 'UTF-8');
        $url = insight_status_pages_url((string)($item['url'] ?? ''), true);
        if ($label === '' || $url === null || $url === '') {
            return null;
        }
        $links[] = ['label' => $label, 'url' => $url];
    }
    return $links;
}

function insight_status_pages_validate(array $input): array
{
    $name = trim((string)($input['name'] ?? ''));
    $slug = strtolower(trim((string)($input['slug'] ?? '')));
    if ($name === '' || mb_strlen($name, 'UTF-8') > 160) {
        return ['ok' => false, 'error' => 'admin.statusPages.errorName'];
    }
    if (preg_match('/^[a-z0-9](?:[a-z0-9-]{0,118}[a-z0-9])?$/', $slug) !== 1) {
        return ['ok' => false, 'error' => 'admin.statusPages.errorSlug'];
    }
    $description = trim((string)($input['description'] ?? ''));
    if (mb_strlen($description, 'UTF-8') > 20000) {
        return ['ok' => false, 'error' => 'admin.statusPages.errorDescription'];
    }
    $domain = strtolower(trim((string)($input['custom_domain'] ?? '')));
    if ($domain !== '' && filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
        return ['ok' => false, 'error' => 'admin.statusPages.errorDomain'];
    }
    $accessPolicy = strtolower(trim((string)($input['access_policy'] ?? (($input['visibility'] ?? 'public') === 'private' ? 'password' : 'public'))));
    if (!in_array($accessPolicy, ['public', 'password', 'sso', 'ip_allowlist'], true)) {
        return ['ok' => false, 'error' => 'admin.statusPages.errorAccess'];
    }
    $visibility = $accessPolicy === 'public' ? 'public' : 'private';
    $theme = strtolower(trim((string)($input['theme'] ?? 'system')));
    if (!in_array($theme, ['system', 'light', 'dark'], true)) {
        $theme = 'system';
    }
    $accent = strtolower(trim((string)($input['accent_color'] ?? '#16a34a')));
    if (preg_match('/^#[a-f0-9]{6}$/', $accent) !== 1) {
        return ['ok' => false, 'error' => 'admin.statusPages.errorColor'];
    }
    $locale = strtolower(trim((string)($input['locale'] ?? 'auto')));
    if (preg_match('/^(auto|[a-z]{2})$/', $locale) !== 1) {
        $locale = 'auto';
    }
    $logoUrl = insight_status_pages_url((string)($input['logo_url'] ?? ''), true);
    $faviconUrl = insight_status_pages_url((string)($input['favicon_url'] ?? ''), true);
    $announcementUrl = insight_status_pages_url((string)($input['announcement_url'] ?? ''), true);
    if ($logoUrl === null || $faviconUrl === null || $announcementUrl === null) {
        return ['ok' => false, 'error' => 'admin.statusPages.errorUrl'];
    }
    $announcement = mb_substr(trim((string)($input['announcement'] ?? '')), 0, 1000, 'UTF-8');
    $navigation = insight_status_pages_navigation($input['navigation_links'] ?? $input['navigation_links_json'] ?? []);
    if ($navigation === null) {
        return ['ok' => false, 'error' => 'admin.statusPages.errorNavigation'];
    }
    $customCss = trim(str_replace(["\r\n", "\r"], "\n", (string)($input['custom_css'] ?? '')));
    if (strlen($customCss) > 20000 || preg_match('#</?style|@import|url\s*\(|expression\s*\(|javascript:#i', $customCss) === 1) {
        return ['ok' => false, 'error' => 'admin.statusPages.errorCss'];
    }
    $historyDays = filter_var($input['history_days'] ?? 90, FILTER_VALIDATE_INT);
    if ($historyDays === false || (int)$historyDays < 1 || (int)$historyDays > 365) {
        return ['ok' => false, 'error' => 'admin.statusPages.errorHistory'];
    }
    $ipRules = array_values(array_unique(array_filter(array_map('trim', preg_split('/[\s,]+/', (string)($input['ip_allowlist'] ?? '')) ?: []))));
    if ($accessPolicy === 'ip_allowlist' && ($ipRules === [] || count($ipRules) > 200 || array_filter($ipRules, static fn(string $rule): bool => !insight_status_pages_ip_rule_valid($rule)) !== [])) {
        return ['ok' => false, 'error' => 'admin.statusPages.errorIpAllowlist'];
    }
    $groups = [];
    $seenSites = [];
    foreach ((array)($input['groups'] ?? []) as $position => $group) {
        if (!is_array($group)) {
            continue;
        }
        $groupName = trim((string)($group['name'] ?? ''));
        if ($groupName === '' || mb_strlen($groupName, 'UTF-8') > 160) {
            return ['ok' => false, 'error' => 'admin.statusPages.errorGroup'];
        }
        $siteIds = [];
        foreach ((array)($group['site_ids'] ?? []) as $value) {
            $id = filter_var($value, FILTER_VALIDATE_INT);
            if ($id !== false && (int)$id > 0 && !isset($seenSites[(int)$id])) {
                $siteIds[] = (int)$id;
                $seenSites[(int)$id] = true;
            }
        }
        $groups[] = ['name' => $groupName, 'sort_order' => $position, 'collapsed' => insight_probes_bool($group['collapsed'] ?? null), 'site_ids' => $siteIds];
    }
    $ungrouped = [];
    foreach ((array)($input['site_ids'] ?? []) as $value) {
        $id = filter_var($value, FILTER_VALIDATE_INT);
        if ($id !== false && (int)$id > 0 && !isset($seenSites[(int)$id])) {
            $ungrouped[] = (int)$id;
            $seenSites[(int)$id] = true;
        }
    }
    return [
        'ok' => true,
        'name' => $name,
        'slug' => $slug,
        'description' => $description,
        'custom_domain' => $domain,
        'visibility' => $visibility,
        'access_policy' => $accessPolicy,
        'password' => (string)($input['password'] ?? ''),
        'ip_allowlist' => implode("\n", $ipRules),
        'theme' => $theme,
        'accent_color' => $accent,
        'logo_url' => $logoUrl,
        'favicon_url' => $faviconUrl,
        'announcement' => $announcement,
        'announcement_url' => $announcementUrl,
        'navigation_links' => $navigation,
        'navigation_links_json' => json_encode($navigation, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'custom_css' => $customCss,
        'history_days' => (int)$historyDays,
        'hide_from_search_engines' => insight_probes_bool($input['hide_from_search_engines'] ?? null),
        'locale' => $locale,
        'enabled' => insight_probes_bool($input['enabled'] ?? null, true),
        'groups' => $groups,
        'site_ids' => $ungrouped,
    ];
}

function insight_status_pages_store_layout(mysqli $database, int $pageId, array $page): void
{
    $database->query('DELETE FROM status_page_monitors WHERE status_page_id = ' . $pageId);
    $database->query('DELETE FROM status_page_groups WHERE status_page_id = ' . $pageId);
    $exists = $database->prepare('SELECT id FROM sites WHERE id = ? LIMIT 1');
    $insertMonitor = $database->prepare('INSERT INTO status_page_monitors (status_page_id, site_id, group_id, sort_order) VALUES (?, ?, ?, ?)');
    $insertGroup = $database->prepare('INSERT INTO status_page_groups (status_page_id, name, sort_order, collapsed) VALUES (?, ?, ?, ?)');
    if (!$exists instanceof mysqli_stmt || !$insertMonitor instanceof mysqli_stmt || !$insertGroup instanceof mysqli_stmt) {
        throw new RuntimeException('database_prepare_failed');
    }
    $position = 0;
    foreach ($page['site_ids'] as $siteId) {
        $exists->bind_param('i', $siteId);
        $exists->execute();
        if (!is_array($exists->get_result()->fetch_assoc())) {
            throw new InvalidArgumentException('site_not_found');
        }
        $groupId = null;
        $insertMonitor->bind_param('iiii', $pageId, $siteId, $groupId, $position);
        $insertMonitor->execute();
        $position++;
    }
    foreach ($page['groups'] as $group) {
        $collapsed = $group['collapsed'] ? 1 : 0;
        $insertGroup->bind_param('isii', $pageId, $group['name'], $group['sort_order'], $collapsed);
        $insertGroup->execute();
        $groupId = (int)$insertGroup->insert_id;
        foreach ($group['site_ids'] as $siteId) {
            $exists->bind_param('i', $siteId);
            $exists->execute();
            if (!is_array($exists->get_result()->fetch_assoc())) {
                throw new InvalidArgumentException('site_not_found');
            }
            $insertMonitor->bind_param('iiii', $pageId, $siteId, $groupId, $position);
            $insertMonitor->execute();
            $position++;
        }
    }
    $exists->close();
    $insertMonitor->close();
    $insertGroup->close();
}

function insight_status_pages_list(array $config): array
{
    $database = insight_probes_database($config);
    try {
        $result = $database->query(
            'SELECT p.*, (SELECT COUNT(*) FROM status_page_subscribers s WHERE s.status_page_id=p.id AND s.confirmed_at IS NOT NULL AND s.unsubscribed_at IS NULL) AS subscribers_count FROM status_pages p ORDER BY p.id'
        );
        if (!$result instanceof mysqli_result) {
            throw new RuntimeException('database_read_failed');
        }
        $pages = $result->fetch_all(MYSQLI_ASSOC);
        foreach ($pages as $index => $page) {
            $pageId = (int)$page['id'];
            $groupsResult = $database->query("SELECT * FROM status_page_groups WHERE status_page_id={$pageId} ORDER BY sort_order,id");
            $monitorsResult = $database->query("SELECT m.site_id,m.group_id,m.display_name,m.sort_order,m.visible,s.url,s.name FROM status_page_monitors m INNER JOIN sites s ON s.id=m.site_id WHERE m.status_page_id={$pageId} ORDER BY m.sort_order,m.site_id");
            $groups = $groupsResult instanceof mysqli_result ? $groupsResult->fetch_all(MYSQLI_ASSOC) : [];
            $monitors = $monitorsResult instanceof mysqli_result ? $monitorsResult->fetch_all(MYSQLI_ASSOC) : [];
            foreach ($groups as $groupIndex => $group) {
                $groups[$groupIndex]['site_ids'] = array_values(array_map(static fn(array $monitor): int => (int)$monitor['site_id'], array_filter($monitors, static fn(array $monitor): bool => (int)($monitor['group_id'] ?? 0) === (int)$group['id'])));
            }
            $pages[$index]['groups'] = $groups;
            $pages[$index]['site_ids'] = array_values(array_map(static fn(array $monitor): int => (int)$monitor['site_id'], array_filter($monitors, static fn(array $monitor): bool => $monitor['group_id'] === null)));
            $pages[$index]['monitors'] = $monitors;
            $pages[$index]['has_password'] = (string)($page['password_hash'] ?? '') !== '';
            $navigation = json_decode((string)($page['navigation_links_json'] ?? '[]'), true);
            $pages[$index]['navigation_links'] = is_array($navigation) ? $navigation : [];
            unset($pages[$index]['password_hash']);
        }
        return ['ok' => true, 'status_code' => 200, 'pages' => $pages];
    } finally {
        $database->close();
    }
}

function insight_status_pages_create(array $config, array $input): array
{
    $page = insight_status_pages_validate($input);
    if (!($page['ok'] ?? false)) {
        return ['ok' => false, 'status_code' => 422, 'error' => $page['error']];
    }
    if ($page['access_policy'] === 'password' && strlen($page['password']) < 12) {
        return ['ok' => false, 'status_code' => 422, 'error' => 'admin.statusPages.errorPassword'];
    }
    $database = insight_probes_database($config);
    try {
        $database->begin_transaction();
        $statement = $database->prepare('INSERT INTO status_pages (slug,name,description,custom_domain,visibility,access_policy,password_hash,ip_allowlist,theme,accent_color,logo_url,favicon_url,announcement,announcement_url,navigation_links_json,custom_css,history_days,hide_from_search_engines,locale,enabled) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $passwordHash = $page['access_policy'] === 'password' ? insight_auth_password_hash($page['password']) : null;
        $enabled = $page['enabled'] ? 1 : 0;
        $hideFromSearch = $page['hide_from_search_engines'] ? 1 : 0;
        $statement->bind_param('ssssssssssssssssiisi', $page['slug'], $page['name'], $page['description'], $page['custom_domain'], $page['visibility'], $page['access_policy'], $passwordHash, $page['ip_allowlist'], $page['theme'], $page['accent_color'], $page['logo_url'], $page['favicon_url'], $page['announcement'], $page['announcement_url'], $page['navigation_links_json'], $page['custom_css'], $page['history_days'], $hideFromSearch, $page['locale'], $enabled);
        if (!$statement->execute()) {
            $duplicate = $statement->errno === 1062;
            $statement->close();
            $database->rollback();
            return ['ok' => false, 'status_code' => $duplicate ? 409 : 503, 'error' => $duplicate ? 'admin.statusPages.errorDuplicate' : 'admin.statusPages.errorDatabase'];
        }
        $id = (int)$statement->insert_id;
        $statement->close();
        insight_status_pages_store_layout($database, $id, $page);
        $database->commit();
        return ['ok' => true, 'status_code' => 201, 'id' => $id, 'slug' => $page['slug']];
    } catch (mysqli_sql_exception $exception) {
        $database->rollback();
        return ['ok' => false, 'status_code' => 409, 'error' => 'admin.statusPages.errorDuplicate'];
    } catch (InvalidArgumentException) {
        $database->rollback();
        return ['ok' => false, 'status_code' => 422, 'error' => 'admin.statusPages.errorSite'];
    } catch (Throwable $exception) {
        $database->rollback();
        throw $exception;
    } finally {
        $database->close();
    }
}

function insight_status_pages_update(array $config, int $id, array $input): array
{
    if ($id < 1) {
        return ['ok' => false, 'status_code' => 404, 'error' => 'admin.statusPages.errorNotFound'];
    }
    $page = insight_status_pages_validate($input);
    if (!($page['ok'] ?? false)) {
        return ['ok' => false, 'status_code' => 422, 'error' => $page['error']];
    }
    $database = insight_probes_database($config);
    try {
        $currentResult = $database->query('SELECT password_hash FROM status_pages WHERE id=' . $id);
        $current = $currentResult instanceof mysqli_result ? $currentResult->fetch_assoc() : null;
        if (!is_array($current)) {
            return ['ok' => false, 'status_code' => 404, 'error' => 'admin.statusPages.errorNotFound'];
        }
        $passwordHash = $page['access_policy'] === 'password'
            ? ($page['password'] !== '' ? insight_auth_password_hash($page['password']) : (string)($current['password_hash'] ?? ''))
            : null;
        if ($page['access_policy'] === 'password' && $passwordHash === '') {
            return ['ok' => false, 'status_code' => 422, 'error' => 'admin.statusPages.errorPassword'];
        }
        $database->begin_transaction();
        $statement = $database->prepare('UPDATE status_pages SET slug=?,name=?,description=?,custom_domain=?,visibility=?,access_policy=?,password_hash=?,ip_allowlist=?,theme=?,accent_color=?,logo_url=?,favicon_url=?,announcement=?,announcement_url=?,navigation_links_json=?,custom_css=?,history_days=?,hide_from_search_engines=?,locale=?,enabled=? WHERE id=?');
        $enabled = $page['enabled'] ? 1 : 0;
        $hideFromSearch = $page['hide_from_search_engines'] ? 1 : 0;
        $statement->bind_param('ssssssssssssssssiisii', $page['slug'], $page['name'], $page['description'], $page['custom_domain'], $page['visibility'], $page['access_policy'], $passwordHash, $page['ip_allowlist'], $page['theme'], $page['accent_color'], $page['logo_url'], $page['favicon_url'], $page['announcement'], $page['announcement_url'], $page['navigation_links_json'], $page['custom_css'], $page['history_days'], $hideFromSearch, $page['locale'], $enabled, $id);
        if (!$statement->execute()) {
            $duplicate = $statement->errno === 1062;
            $statement->close();
            $database->rollback();
            return ['ok' => false, 'status_code' => $duplicate ? 409 : 503, 'error' => $duplicate ? 'admin.statusPages.errorDuplicate' : 'admin.statusPages.errorDatabase'];
        }
        $statement->close();
        insight_status_pages_store_layout($database, $id, $page);
        $database->commit();
        return ['ok' => true, 'status_code' => 200, 'id' => $id, 'slug' => $page['slug']];
    } catch (mysqli_sql_exception) {
        $database->rollback();
        return ['ok' => false, 'status_code' => 409, 'error' => 'admin.statusPages.errorDuplicate'];
    } catch (InvalidArgumentException) {
        $database->rollback();
        return ['ok' => false, 'status_code' => 422, 'error' => 'admin.statusPages.errorSite'];
    } catch (Throwable $exception) {
        $database->rollback();
        throw $exception;
    } finally {
        $database->close();
    }
}

function insight_status_pages_delete(array $config, int $id): array
{
    $database = insight_probes_database($config);
    try {
        $defaultResult = $database->query("SELECT id FROM status_pages WHERE slug='default' LIMIT 1");
        $default = $defaultResult instanceof mysqli_result ? $defaultResult->fetch_assoc() : null;
        if ($id < 1 || (int)($default['id'] ?? 0) === $id) {
            return ['ok' => false, 'status_code' => 422, 'error' => 'admin.statusPages.errorDefault'];
        }
        $statement = $database->prepare('DELETE FROM status_pages WHERE id=?');
        $statement->bind_param('i', $id);
        $statement->execute();
        $deleted = $statement->affected_rows;
        $statement->close();
        return $deleted > 0 ? ['ok' => true, 'status_code' => 200, 'deleted_id' => $id] : ['ok' => false, 'status_code' => 404, 'error' => 'admin.statusPages.errorNotFound'];
    } finally {
        $database->close();
    }
}
