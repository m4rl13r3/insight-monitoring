<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once dirname(__DIR__) . '/_status_page.php';

insight_auth_require_user();
$slug = strtolower(trim((string)($_GET['page'] ?? 'default')));
if (preg_match('/^[a-z0-9-]{1,120}$/', $slug) !== 1) {
    http_response_code(404);
    exit;
}
$database = insight_status_page_database($insightAdminConfig);
if (!$database instanceof mysqli) {
    http_response_code(503);
    exit;
}
try {
    $statement = $database->prepare("SELECT * FROM status_pages WHERE slug=? AND access_policy='sso' AND enabled=1 LIMIT 1");
    $statement->bind_param('s', $slug);
    $statement->execute();
    $page = $statement->get_result()->fetch_assoc();
    $statement->close();
} finally {
    $database->close();
}
if (!is_array($page)) {
    http_response_code(404);
    exit;
}
$expiresAt = time() + 43200;
$value = insight_status_page_cookie_value((int)$page['id'], insight_status_page_access_fingerprint($page), $expiresAt);
if ($value === '') {
    http_response_code(503);
    exit;
}
setcookie(insight_status_page_cookie_name((int)$page['id']), $value, [
    'expires' => $expiresAt,
    'path' => '/',
    'secure' => insight_admin_secure_cookie(),
    'httponly' => true,
    'samesite' => 'Lax',
]);
header('Location: /?page=' . rawurlencode($slug), true, 303);
exit;
