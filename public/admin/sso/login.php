<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_oidc.php';

if (!insight_auth_is_configured()) {
    insight_auth_redirect('/admin/setup.php');
}
if (insight_auth_current_user() !== null) {
    insight_auth_redirect(insight_auth_safe_next($_GET['next'] ?? '/admin/'));
}
try {
    insight_oidc_start(insight_auth_safe_next($_GET['next'] ?? '/admin/'));
} catch (Throwable $exception) {
    $key = insight_oidc_error_key($exception);
    insight_auth_redirect('/admin/login.php?sso_error=' . rawurlencode($key));
}
