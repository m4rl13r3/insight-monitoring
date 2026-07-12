<?php

declare(strict_types=1);

function insight_test_auth_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

function insight_test_auth_remove_directory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $entry) {
        if ($entry->isDir()) {
            rmdir($entry->getPathname());
        } else {
            unlink($entry->getPathname());
        }
    }
    rmdir($directory);
}

$temporaryDirectory = sys_get_temp_dir() . '/insight-auth-' . bin2hex(random_bytes(8));
$databasePath = $temporaryDirectory . '/auth.sqlite';

putenv('INSIGHT_AUTH_DB_PATH=' . $databasePath);
putenv('INSIGHT_APP_ENV=test-suite');
putenv('INSIGHT_DEV_AUTH_BYPASS=0');
putenv('INSIGHT_AUTH_MAX_ATTEMPTS=5');
putenv('INSIGHT_AUTH_WINDOW_SEC=900');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['SCRIPT_FILENAME'] = __FILE__;

require dirname(__DIR__) . '/public/admin/_bootstrap.php';

try {
    insight_test_auth_assert(!insight_auth_is_configured(), 'L’authentification devrait être vierge.');

    $invalid = insight_auth_create_first_admin('admin', 'trop-court', 'trop-court', false);
    insight_test_auth_assert(!($invalid['ok'] ?? false), 'Un mot de passe court a été accepté.');

    $password = 'Insight-test-2026!';
    $created = insight_auth_create_first_admin('admin', $password, $password, false);
    insight_test_auth_assert(($created['ok'] ?? false) === true, 'La création du compte a échoué.');
    insight_test_auth_assert(insight_auth_user_count() === 1, 'Le compte administrateur est absent.');

    $storedHash = (string)insight_auth_db()->query('SELECT password_hash FROM auth_users LIMIT 1')->fetchColumn();
    insight_test_auth_assert($storedHash !== $password, 'Le mot de passe a été stocké en clair.');
    insight_test_auth_assert(password_verify($password, $storedHash), 'Le hachage du mot de passe est invalide.');

    $csrfToken = insight_auth_csrf_token();
    insight_test_auth_assert(insight_auth_csrf_valid($csrfToken), 'Le jeton CSRF valide a été refusé.');
    insight_test_auth_assert(!insight_auth_csrf_valid('jeton-invalide'), 'Un jeton CSRF invalide a été accepté.');

    $duplicate = insight_auth_create_first_admin('second-admin', $password, $password, false);
    insight_test_auth_assert(!($duplicate['ok'] ?? false), 'Un second premier administrateur a été créé.');

    insight_auth_destroy_session();
    insight_admin_start_session($insightAuthSessionsPath);

    $failedLogin = insight_auth_login('admin', 'mot-de-passe-invalide', false);
    insight_test_auth_assert(!($failedLogin['ok'] ?? false), 'Une connexion invalide a réussi.');

    $login = insight_auth_login('ADMIN', $password, false);
    insight_test_auth_assert(($login['ok'] ?? false) === true, 'La connexion valide a échoué.');
    insight_test_auth_assert((insight_auth_current_user()['username'] ?? '') === 'admin', 'La session ne contient pas le bon compte.');

    for ($attempt = 0; $attempt < 5; $attempt++) {
        insight_auth_record_attempt('compte-inconnu', false);
    }
    $rateLimit = insight_auth_rate_limit('compte-inconnu');
    insight_test_auth_assert(($rateLimit['blocked'] ?? false) === true, 'La limitation des tentatives ne s’active pas.');

    $auditCount = (int)insight_auth_db()->query('SELECT COUNT(*) FROM auth_audit_log')->fetchColumn();
    insight_test_auth_assert($auditCount >= 3, 'Le journal d’audit ne contient pas les événements attendus.');

    insight_auth_destroy_session();
    echo "Authentification locale validée.\n";
} finally {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    insight_test_auth_remove_directory($temporaryDirectory);
}
