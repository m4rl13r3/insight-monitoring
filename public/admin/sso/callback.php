<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_oidc.php';

try {
    $result = insight_oidc_callback($_GET);
    insight_auth_redirect((string)$result['next']);
} catch (Throwable $exception) {
    insight_auth_audit('sso_login_failed');
    $key = insight_oidc_error_key($exception);
    insight_auth_redirect('/admin/login.php?sso_error=' . rawurlencode($key));
}
