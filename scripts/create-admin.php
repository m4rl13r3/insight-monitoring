<?php

declare(strict_types=1);

require dirname(__DIR__) . '/public/admin/_bootstrap.php';

$username = trim((string)(getenv('INSIGHT_BOOTSTRAP_ADMIN_USERNAME') ?: ''));
$password = (string)(getenv('INSIGHT_BOOTSTRAP_ADMIN_PASSWORD') ?: '');

if ($username === '' || $password === '') {
    fwrite(STDERR, "Administrator credentials are required.\n");
    exit(1);
}

if (insight_auth_is_configured()) {
    echo "An administrator account already exists.\n";
    exit(10);
}

$result = insight_auth_create_first_admin($username, $password, $password, false);
if (($result['ok'] ?? false) !== true) {
    fwrite(STDERR, "Unable to create the administrator account.\n");
    exit(1);
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

echo "Administrator account created.\n";
