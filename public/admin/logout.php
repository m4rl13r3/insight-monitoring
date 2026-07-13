<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

if (insight_auth_dev_bypass_enabled()) {
    insight_auth_destroy_session();
    insight_auth_redirect('/admin/');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo 'Method not allowed.';
    exit;
}

if (!insight_auth_csrf_valid($_POST['csrf_token'] ?? null)) {
    http_response_code(400);
    echo 'Session expired.';
    exit;
}

$user = insight_auth_current_user();
if ($user !== null) {
    insight_auth_audit('logout', insight_auth_local_user_id($user));
}
insight_auth_destroy_session();
insight_auth_redirect('/admin/login.php?logged_out=1');
