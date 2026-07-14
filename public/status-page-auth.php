<?php

declare(strict_types=1);

$config = require __DIR__ . '/config/config.php';
require_once __DIR__ . '/_status_page.php';

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit;
}
$database = insight_status_page_database($config);
if (!$database instanceof mysqli) {
    http_response_code(503);
    exit;
}
$slug = strtolower(trim((string)($_POST['page'] ?? 'default')));
$statement = $database->prepare("SELECT * FROM status_pages WHERE slug=? AND access_policy='password' AND enabled=1 LIMIT 1");
$statement->bind_param('s', $slug);
$statement->execute();
$page = $statement->get_result()->fetch_assoc();
$statement->close();
$secret = (string)(getenv('INSIGHT_STATUS_PAGE_COOKIE_SECRET') ?: getenv('INSIGHT_NOTIFICATION_ENCRYPTION_KEY') ?: '');
if (!is_array($page) || strlen($secret) < 32) {
    $database->close();
    http_response_code(503);
    exit;
}
$maximumAttempts = max(1, min(100, (int)(getenv('INSIGHT_STATUS_PAGE_AUTH_MAX_ATTEMPTS') ?: 5)));
$windowSeconds = max(60, min(86400, (int)(getenv('INSIGHT_STATUS_PAGE_AUTH_WINDOW_SEC') ?: 900)));
$identity = hash_hmac('sha256', strtolower(trim((string)($_SERVER['REMOTE_ADDR'] ?? 'local'))) . '|' . (int)$page['id'], $secret);
$database->query('DELETE FROM status_page_auth_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 1 DAY)');
$cutoff = date('Y-m-d H:i:s', time() - $windowSeconds);
$limit = $database->prepare('SELECT COUNT(*) AS total FROM status_page_auth_attempts WHERE identity_hash=? AND attempted_at>=?');
$limit->bind_param('ss', $identity, $cutoff);
$limit->execute();
$attempts = (int)($limit->get_result()->fetch_assoc()['total'] ?? 0);
$limit->close();
if ($attempts >= $maximumAttempts) {
    $database->close();
    header('Retry-After: ' . $windowSeconds);
    header('Location: /?page=' . rawurlencode($slug) . '&auth=limited', true, 303);
    exit;
}
$attempt = $database->prepare('INSERT INTO status_page_auth_attempts (identity_hash) VALUES (?)');
$attempt->bind_param('s', $identity);
$attempt->execute();
$attempt->close();
if (!password_verify((string)($_POST['password'] ?? ''), (string)($page['password_hash'] ?? ''))) {
    $database->close();
    header('Location: /?page=' . rawurlencode($slug) . '&auth=failed', true, 303);
    exit;
}
$clear = $database->prepare('DELETE FROM status_page_auth_attempts WHERE identity_hash=?');
$clear->bind_param('s', $identity);
$clear->execute();
$clear->close();
$database->close();
$expiresAt = time() + 86400;
$value = insight_status_page_cookie_value((int)$page['id'], insight_status_page_access_fingerprint($page), $expiresAt);
if ($value === '') {
    http_response_code(503);
    exit;
}
setcookie(insight_status_page_cookie_name((int)$page['id']), $value, [
    'expires' => $expiresAt,
    'path' => '/',
    'secure' => str_starts_with(strtolower((string)($config['public_url'] ?? '')), 'https://') || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https' || (strtolower((string)($_SERVER['HTTPS'] ?? '')) !== 'off' && (string)($_SERVER['HTTPS'] ?? '') !== ''),
    'httponly' => true,
    'samesite' => 'Lax',
]);
header('Location: /?page=' . rawurlencode($slug), true, 303);
exit;
