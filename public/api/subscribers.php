<?php

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

$config = require dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/_status_page.php';

function insight_subscribers_response(array $payload, int $status): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function insight_subscribers_enabled(): bool
{
    return in_array(strtolower((string)(getenv('INSIGHT_STATUS_SUBSCRIPTIONS_ENABLED') ?: '0')), ['1', 'true', 'yes', 'on'], true);
}

function insight_subscribers_secret(): string
{
    return trim((string)(getenv('INSIGHT_STATUS_SUBSCRIBER_SECRET') ?: getenv('INSIGHT_NOTIFICATION_ENCRYPTION_KEY') ?: ''));
}

function insight_subscribers_signature(int $subscriberId, string $email): string
{
    $secret = insight_subscribers_secret();
    if (strlen($secret) < 32) {
        return '';
    }
    return hash_hmac('sha256', 'unsubscribe:' . $subscriberId . ':' . strtolower($email), $secret);
}

function insight_subscribers_catalog(string $locale): array
{
    $normalized = preg_match('/^[a-z]{2}$/', strtolower($locale)) === 1 ? strtolower($locale) : 'en';
    $path = dirname(__DIR__) . '/locales/' . $normalized . '.json';
    $catalog = is_readable($path) ? json_decode((string)file_get_contents($path), true) : [];
    return is_array($catalog) ? $catalog : [];
}

function insight_subscribers_text(array $catalog, string $key, string $fallback, array $values = []): string
{
    $text = (string)($catalog[$key] ?? $fallback);
    foreach ($values as $name => $value) {
        $text = str_replace('{' . $name . '}', (string)$value, $text);
    }
    return str_replace('\\n', "\n", $text);
}

function insight_subscribers_page(string $status, string $locale, string $returnUrl): never
{
    $french = str_starts_with(strtolower($locale), 'fr');
    $confirmed = $status === 'confirmed';
    $catalog = insight_subscribers_catalog($locale);
    $title = insight_subscribers_text($catalog, $confirmed ? 'subscriptions.confirmedTitle' : 'subscriptions.unsubscribedTitle', $confirmed ? 'Subscription confirmed' : 'Unsubscribed');
    $description = insight_subscribers_text($catalog, $confirmed ? 'subscriptions.confirmedDescription' : 'subscriptions.unsubscribedDescription', $confirmed ? 'You will receive future updates from this page.' : 'You will no longer receive updates from this page.');
    $back = insight_subscribers_text($catalog, 'subscriptions.backToStatus', 'Return to the status page');
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");
    $safeTitle = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeDescription = htmlspecialchars($description, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeBack = htmlspecialchars($back, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeUrl = htmlspecialchars($returnUrl !== '' ? $returnUrl : '/', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    echo '<!doctype html><html lang="' . ($french ? 'fr' : 'en') . '"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $safeTitle . '</title><style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#09090b;color:#fafafa;font:16px/1.5 system-ui,sans-serif}.panel{width:min(420px,calc(100% - 48px));display:grid;gap:12px}.mark{width:40px;height:40px;display:grid;place-items:center;border:1px solid #3f3f46;border-radius:8px;color:#4ade80}h1,p{margin:0}p{color:#a1a1aa}a{width:max-content;margin-top:10px;padding:10px 14px;border-radius:6px;background:#fafafa;color:#09090b;text-decoration:none;font-weight:700}</style><main class="panel"><span class="mark">✓</span><h1>' . $safeTitle . '</h1><p>' . $safeDescription . '</p><a href="' . $safeUrl . '">' . $safeBack . '</a></main></html>';
    exit;
}

function insight_subscribers_delivery_ready(mysqli $database): bool
{
    if (in_array(strtolower((string)(getenv('INSIGHT_DISABLE_NOTIFICATIONS') ?: '1')), ['1', 'true', 'yes', 'on'], true)) {
        return false;
    }
    if (trim((string)(getenv('INSIGHT_EMAIL_SMTP_HOST') ?: '')) !== '' && trim((string)(getenv('INSIGHT_EMAIL_SMTP_USERNAME') ?: '')) !== '') {
        return true;
    }
    $channelId = filter_var(getenv('INSIGHT_STATUS_SUBSCRIBER_SMTP_CHANNEL_ID') ?: null, FILTER_VALIDATE_INT);
    $selector = $channelId !== false && (int)$channelId > 0 ? ' AND id=' . (int)$channelId : '';
    $result = $database->query("SELECT id FROM notification_channels WHERE enabled=1 AND provider='smtp' AND last_status='success' AND last_test_at IS NOT NULL{$selector} LIMIT 1");
    return $result instanceof mysqli_result && $result->num_rows === 1;
}

function insight_subscribers_send(string $email, string $title, string $body): bool
{
    if (!function_exists('proc_open')) {
        return false;
    }
    $root = dirname(__DIR__, 2);
    $python = trim((string)(getenv('PYTHON_BIN') ?: 'python3'));
    $script = $root . '/monitoring/python_monitoring/notification_cli.py';
    $payload = [
        'event' => 'test',
        'email' => $email,
        'templates' => ['title' => $title, 'body' => $body],
        'context' => [],
    ];
    $process = proc_open([$python, $script, 'subscriber'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
    if (!is_resource($process)) {
        return false;
    }
    fwrite($pipes[0], json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $result = json_decode(is_string($output) ? $output : '', true);
    return $exit === 0 && is_array($result) && ($result['ok'] ?? false) === true;
}

$database = insight_status_page_database($config);
if (!$database instanceof mysqli) {
    insight_subscribers_response(['ok' => false, 'error' => 'database_unavailable'], 503);
}
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$getAction = $method === 'GET' ? strtolower(trim((string)($_GET['action'] ?? 'confirm'))) : '';
if (!insight_subscribers_enabled() && !($method === 'GET' && $getAction === 'unsubscribe')) {
    $database->close();
    insight_subscribers_response(['ok' => false, 'error' => 'subscriptions_disabled'], 404);
}
if ($method === 'GET') {
    $action = $getAction;
    $token = trim((string)($_GET['token'] ?? ''));
    $hash = strlen($token) >= 32 ? hash('sha256', $token) : '';
    $subscriberId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
    $signature = trim((string)($_GET['signature'] ?? ''));
    if (!in_array($action, ['confirm', 'unsubscribe'], true)) {
        $database->close();
        insight_subscribers_response(['ok' => false, 'error' => 'invalid_token'], 400);
    }
    $subscriber = null;
    if ($hash !== '') {
        $lookup = $database->prepare('SELECT subscriber.id,subscriber.email,subscriber.locale,p.slug,p.custom_domain FROM status_page_subscribers subscriber INNER JOIN status_pages p ON p.id=subscriber.status_page_id WHERE subscriber.token_hash=? LIMIT 1');
        $lookup->bind_param('s', $hash);
        $lookup->execute();
        $subscriber = $lookup->get_result()->fetch_assoc();
        $lookup->close();
    } elseif ($action === 'unsubscribe' && $subscriberId !== false && (int)$subscriberId > 0 && strlen($signature) === 64) {
        $lookup = $database->prepare('SELECT subscriber.id,subscriber.email,subscriber.locale,p.slug,p.custom_domain FROM status_page_subscribers subscriber INNER JOIN status_pages p ON p.id=subscriber.status_page_id WHERE subscriber.id=? LIMIT 1');
        $id = (int)$subscriberId;
        $lookup->bind_param('i', $id);
        $lookup->execute();
        $candidate = $lookup->get_result()->fetch_assoc();
        $lookup->close();
        if (is_array($candidate)) {
            $expected = insight_subscribers_signature((int)$candidate['id'], (string)$candidate['email']);
            if ($expected !== '' && hash_equals($expected, $signature)) {
                $subscriber = $candidate;
            }
        }
    }
    if (!is_array($subscriber)) {
        $database->close();
        insight_subscribers_response(['ok' => false, 'error' => 'invalid_token'], 400);
    }
    $id = (int)$subscriber['id'];
    $statement = $database->prepare($action === 'confirm'
        ? 'UPDATE status_page_subscribers SET confirmed_at=COALESCE(confirmed_at,NOW()), unsubscribed_at=NULL WHERE id=?'
        : 'UPDATE status_page_subscribers SET unsubscribed_at=NOW() WHERE id=?');
    $statement->bind_param('i', $id);
    $statement->execute();
    $statement->close();
    $database->close();
    $customDomain = trim((string)($subscriber['custom_domain'] ?? ''));
    $slug = trim((string)($subscriber['slug'] ?? 'default'));
    $returnUrl = $customDomain !== '' ? 'https://' . $customDomain : rtrim((string)($config['public_url'] ?? ''), '/');
    if ($customDomain === '' && $slug !== 'default' && $returnUrl !== '') {
        $returnUrl .= '?page=' . rawurlencode($slug);
    }
    insight_subscribers_page($action === 'confirm' ? 'confirmed' : 'unsubscribed', (string)($subscriber['locale'] ?? 'en'), $returnUrl);
}
if ($method !== 'POST') {
    $database->close();
    header('Allow: GET, POST');
    insight_subscribers_response(['ok' => false, 'error' => 'method_not_allowed'], 405);
}
if (!insight_subscribers_delivery_ready($database)) {
    $database->close();
    insight_subscribers_response(['ok' => false, 'error' => 'subscriptions_not_configured'], 503);
}
$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input)) {
    $database->close();
    insight_subscribers_response(['ok' => false, 'error' => 'invalid_payload'], 400);
}
$email = strtolower(trim((string)($input['email'] ?? '')));
$slug = strtolower(trim((string)($input['page'] ?? 'default')));
$locale = strtolower(trim((string)($input['locale'] ?? 'en')));
$requestedSiteIds = [];
foreach ((array)($input['site_ids'] ?? []) as $value) {
    $siteId = filter_var($value, FILTER_VALIDATE_INT);
    if ($siteId !== false && (int)$siteId > 0) {
        $requestedSiteIds[(int)$siteId] = (int)$siteId;
    }
}
$requestedSiteIds = array_values($requestedSiteIds);
if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 320 || preg_match('/^[a-z0-9-]{1,120}$/', $slug) !== 1) {
    $database->close();
    insight_subscribers_response(['ok' => false, 'error' => 'invalid_subscription'], 422);
}
if (preg_match('/^[a-z]{2}$/', $locale) !== 1) {
    $locale = 'en';
}
$secret = insight_subscribers_secret();
if (strlen($secret) < 32) {
    $database->close();
    insight_subscribers_response(['ok' => false, 'error' => 'subscriptions_not_configured'], 503);
}
$database->query("CREATE TABLE IF NOT EXISTS status_page_subscription_attempts (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,identity_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(id),KEY idx_status_page_subscription_attempts(identity_hash,attempted_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$identity = hash_hmac('sha256', strtolower(trim((string)($_SERVER['REMOTE_ADDR'] ?? 'local'))) . '|' . $email, $secret);
$limit = $database->prepare('SELECT COUNT(*) AS total FROM status_page_subscription_attempts WHERE identity_hash=? AND attempted_at>=DATE_SUB(NOW(),INTERVAL 1 HOUR)');
$limit->bind_param('s', $identity);
$limit->execute();
$attempts = (int)($limit->get_result()->fetch_assoc()['total'] ?? 0);
$limit->close();
if ($attempts >= 5) {
    $database->close();
    header('Retry-After: 3600');
    insight_subscribers_response(['ok' => false, 'error' => 'rate_limited'], 429);
}
$attempt = $database->prepare('INSERT INTO status_page_subscription_attempts (identity_hash) VALUES (?)');
$attempt->bind_param('s', $identity);
$attempt->execute();
$attempt->close();
$database->query('DELETE FROM status_page_subscription_attempts WHERE attempted_at<DATE_SUB(NOW(),INTERVAL 1 DAY)');
$statement = $database->prepare("SELECT * FROM status_pages WHERE slug=? AND enabled=1 LIMIT 1");
$statement->bind_param('s', $slug);
$statement->execute();
$page = $statement->get_result()->fetch_assoc();
$statement->close();
if (!is_array($page) || !insight_status_page_authorized($page)) {
    $database->close();
    insight_subscribers_response(['ok' => false, 'error' => 'page_not_found'], 404);
}
$token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
$hash = hash('sha256', $token);
$autoConfirm = in_array(strtolower((string)(getenv('INSIGHT_SUBSCRIBER_AUTO_CONFIRM') ?: '0')), ['1', 'true', 'yes', 'on'], true);
$pageId = (int)$page['id'];
if ($requestedSiteIds !== []) {
    $idsSql = implode(',', array_map('intval', $requestedSiteIds));
    $allowedSiteIds = [];
    $selectionResult = $database->query("SELECT site_id FROM status_page_monitors WHERE status_page_id={$pageId} AND visible=1 AND site_id IN ({$idsSql})");
    while ($selectionResult instanceof mysqli_result && ($row = $selectionResult->fetch_assoc())) {
        $allowedSiteIds[] = (int)$row['site_id'];
    }
    sort($allowedSiteIds);
    $compared = $requestedSiteIds;
    sort($compared);
    if ($allowedSiteIds !== $compared) {
        $database->close();
        insight_subscribers_response(['ok' => false, 'error' => 'invalid_subscription_scope'], 422);
    }
}
$statement = $database->prepare(
    'INSERT INTO status_page_subscribers (status_page_id,email,locale,token_hash,confirmed_at,unsubscribed_at) VALUES (?,?,?,?,IF(?=1,NOW(),NULL),NULL) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id),locale=VALUES(locale),token_hash=VALUES(token_hash),confirmed_at=IF(?=1,NOW(),confirmed_at),unsubscribed_at=NULL'
);
$auto = $autoConfirm ? 1 : 0;
$statement->bind_param('isssii', $pageId, $email, $locale, $hash, $auto, $auto);
$statement->execute();
$subscriberId = (int)$statement->insert_id;
$statement->close();
$database->begin_transaction();
$clearScope = $database->prepare('DELETE FROM status_page_subscriber_sites WHERE subscriber_id=?');
$clearScope->bind_param('i', $subscriberId);
$clearScope->execute();
$clearScope->close();
if ($requestedSiteIds !== []) {
    $scope = $database->prepare('INSERT INTO status_page_subscriber_sites (subscriber_id,site_id) VALUES (?,?)');
    foreach ($requestedSiteIds as $siteId) {
        $scope->bind_param('ii', $subscriberId, $siteId);
        $scope->execute();
    }
    $scope->close();
}
$database->commit();
$database->close();
if ($autoConfirm) {
    insight_subscribers_response(['ok' => true, 'status' => 'confirmed'], 201);
}
$base = rtrim((string)($config['public_url'] ?? ''), '/');
$confirm = $base . '/api/subscribers.php?action=confirm&token=' . rawurlencode($token);
$unsubscribe = $base . '/api/subscribers.php?action=unsubscribe&token=' . rawurlencode($token);
$mailCatalog = insight_subscribers_catalog($locale);
$sent = insight_subscribers_send(
    $email,
    '[' . (string)$page['name'] . '] ' . insight_subscribers_text($mailCatalog, 'subscriptions.confirmEmailSubject', 'Confirm your subscription'),
    insight_subscribers_text($mailCatalog, 'subscriptions.confirmEmailBody', "Confirm your subscription: {confirm_url}\n\nYou can unsubscribe at any time: {unsubscribe_url}", ['confirm_url' => $confirm, 'unsubscribe_url' => $unsubscribe])
);
if (!$sent) {
    insight_subscribers_response(['ok' => false, 'error' => 'confirmation_delivery_failed'], 503);
}
insight_subscribers_response(['ok' => true, 'status' => 'confirmation_sent'], 201);
