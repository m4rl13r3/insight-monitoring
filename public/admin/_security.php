<?php

declare(strict_types=1);

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/_bootstrap.php';

function insight_security_users(): array
{
    $statement = insight_auth_db()->query('SELECT id,username,role,active,totp_enabled,created_at,last_login_at FROM auth_users ORDER BY active DESC,username COLLATE NOCASE');
    return array_map(static fn(array $row): array => [
        'id' => (int)$row['id'],
        'username' => (string)$row['username'],
        'role' => insight_auth_role($row),
        'active' => (int)$row['active'] === 1,
        'totp_enabled' => (int)$row['totp_enabled'] === 1,
        'created_at' => $row['created_at'],
        'last_login_at' => $row['last_login_at'],
    ], $statement->fetchAll());
}

function insight_security_state(array $user): array
{
    $localId = insight_auth_local_user_id($user);
    $totpEnabled = false;
    if ($localId !== null) {
        $statement = insight_auth_db()->prepare('SELECT totp_enabled FROM auth_users WHERE id=:id LIMIT 1');
        $statement->execute(['id' => $localId]);
        $totpEnabled = (int)$statement->fetchColumn() === 1;
    }
    return [
        'ok' => true,
        'status_code' => 200,
        'local_account' => $localId !== null,
        'totp_enabled' => $totpEnabled,
        'role' => insight_auth_role($user),
        'users' => insight_auth_can($user, 'users:write') ? insight_security_users() : [],
    ];
}

function insight_security_begin_totp(array $user): array
{
    $userId = insight_auth_local_user_id($user);
    if ($userId === null) {
        return ['ok' => false, 'status_code' => 422, 'error' => 'admin.security.errorLocalOnly'];
    }
    insight_auth_encryption_key();
    $secret = insight_auth_base32_encode(random_bytes(20));
    $_SESSION['totp_setup'] = ['user_id' => $userId, 'secret' => $secret, 'expires_at' => time() + 600];
    $issuer = (string)($GLOBALS['insightAdminConfig']['app_name'] ?? 'Insight');
    $label = $issuer . ':' . (string)$user['username'];
    $uri = 'otpauth://totp/' . rawurlencode($label)
        . '?secret=' . rawurlencode($secret)
        . '&issuer=' . rawurlencode($issuer)
        . '&algorithm=SHA1&digits=6&period=30';
    return ['ok' => true, 'status_code' => 200, 'secret' => $secret, 'otpauth_uri' => $uri];
}

function insight_security_confirm_totp(array $user, string $code): array
{
    $userId = insight_auth_local_user_id($user);
    $setup = $_SESSION['totp_setup'] ?? null;
    if ($userId === null || !is_array($setup) || (int)($setup['user_id'] ?? 0) !== $userId || (int)($setup['expires_at'] ?? 0) < time()) {
        unset($_SESSION['totp_setup']);
        return ['ok' => false, 'status_code' => 422, 'error' => 'admin.security.errorTotpExpired'];
    }
    $secret = (string)($setup['secret'] ?? '');
    $counter = insight_auth_totp_counter($secret, $code, 0);
    if ($counter === null) {
        return ['ok' => false, 'status_code' => 422, 'error' => 'admin.security.errorTotpCode'];
    }
    $recovery = insight_auth_recovery_codes();
    $statement = insight_auth_db()->prepare(
        'UPDATE auth_users SET totp_secret_ciphertext=:secret,totp_enabled=1,totp_last_counter=:counter,recovery_codes_json=:codes,updated_at=CURRENT_TIMESTAMP WHERE id=:id'
    );
    $statement->execute([
        'secret' => insight_auth_encrypt($secret),
        'counter' => $counter,
        'codes' => json_encode($recovery['hashes'], JSON_THROW_ON_ERROR),
        'id' => $userId,
    ]);
    unset($_SESSION['totp_setup']);
    insight_auth_audit('totp_enabled', $userId);
    return ['ok' => true, 'status_code' => 200, 'recovery_codes' => $recovery['plain']];
}

function insight_security_local_user(array $user): ?array
{
    $userId = insight_auth_local_user_id($user);
    if ($userId === null) {
        return null;
    }
    $statement = insight_auth_db()->prepare('SELECT id,username,password_hash,role,active,totp_enabled,totp_secret_ciphertext,totp_last_counter,recovery_codes_json FROM auth_users WHERE id=:id LIMIT 1');
    $statement->execute(['id' => $userId]);
    $row = $statement->fetch();
    return is_array($row) ? $row : null;
}

function insight_security_disable_totp(array $user, string $code): array
{
    $local = insight_security_local_user($user);
    if (!is_array($local)) {
        return ['ok' => false, 'status_code' => 422, 'error' => 'admin.security.errorLocalOnly'];
    }
    if ((int)$local['totp_enabled'] !== 1 || !insight_auth_verify_second_factor($local, $code)) {
        return ['ok' => false, 'status_code' => 422, 'error' => 'admin.security.errorTotpCode'];
    }
    $statement = insight_auth_db()->prepare("UPDATE auth_users SET totp_secret_ciphertext=NULL,totp_enabled=0,totp_last_counter=0,recovery_codes_json='[]',updated_at=CURRENT_TIMESTAMP WHERE id=:id");
    $statement->execute(['id' => (int)$local['id']]);
    insight_auth_audit('totp_disabled', (int)$local['id']);
    return ['ok' => true, 'status_code' => 200];
}

function insight_security_regenerate_recovery(array $user, string $code): array
{
    $local = insight_security_local_user($user);
    if (!is_array($local) || (int)$local['totp_enabled'] !== 1 || !insight_auth_verify_second_factor($local, $code)) {
        return ['ok' => false, 'status_code' => 422, 'error' => 'admin.security.errorTotpCode'];
    }
    $recovery = insight_auth_recovery_codes();
    $statement = insight_auth_db()->prepare('UPDATE auth_users SET recovery_codes_json=:codes,updated_at=CURRENT_TIMESTAMP WHERE id=:id');
    $statement->execute(['codes' => json_encode($recovery['hashes'], JSON_THROW_ON_ERROR), 'id' => (int)$local['id']]);
    insight_auth_audit('recovery_codes_regenerated', (int)$local['id']);
    return ['ok' => true, 'status_code' => 200, 'recovery_codes' => $recovery['plain']];
}

function insight_security_change_password(array $user, array $input): array
{
    $local = insight_security_local_user($user);
    if (!is_array($local)) {
        return ['ok' => false, 'status_code' => 422, 'error' => 'admin.security.errorLocalOnly'];
    }
    if (!password_verify((string)($input['current_password'] ?? ''), (string)$local['password_hash'])) {
        return ['ok' => false, 'status_code' => 422, 'error' => 'admin.security.errorCurrentPassword'];
    }
    $password = (string)($input['password'] ?? '');
    $error = insight_auth_validate_credentials((string)$local['username'], $password, (string)($input['password_confirmation'] ?? ''));
    if ($error !== null) {
        return ['ok' => false, 'status_code' => 422, 'error' => $error];
    }
    $statement = insight_auth_db()->prepare('UPDATE auth_users SET password_hash=:hash,updated_at=CURRENT_TIMESTAMP WHERE id=:id');
    $statement->execute(['hash' => insight_auth_password_hash($password), 'id' => (int)$local['id']]);
    insight_auth_audit('password_changed', (int)$local['id']);
    return ['ok' => true, 'status_code' => 200];
}

function insight_security_role(mixed $value): ?string
{
    $role = strtolower(trim((string)$value));
    return in_array($role, ['admin', 'operator', 'viewer'], true) ? $role : null;
}

function insight_security_create_user(array $input, array $actor): array
{
    if (!insight_auth_can($actor, 'users:write')) {
        return ['ok' => false, 'status_code' => 403, 'error' => 'admin.auth.errorForbidden'];
    }
    $username = trim((string)($input['username'] ?? ''));
    $password = (string)($input['password'] ?? '');
    $role = insight_security_role($input['role'] ?? 'viewer');
    $error = insight_auth_validate_credentials($username, $password, (string)($input['password_confirmation'] ?? ''));
    if ($error !== null || $role === null) {
        return ['ok' => false, 'status_code' => 422, 'error' => $error ?? 'admin.security.errorRole'];
    }
    try {
        $statement = insight_auth_db()->prepare('INSERT INTO auth_users (username,password_hash,role) VALUES (:username,:hash,:role)');
        $statement->execute(['username' => $username, 'hash' => insight_auth_password_hash($password), 'role' => $role]);
    } catch (PDOException $exception) {
        return ['ok' => false, 'status_code' => 409, 'error' => 'admin.security.errorUserExists'];
    }
    insight_auth_audit('user_created', insight_auth_local_user_id($actor));
    return ['ok' => true, 'status_code' => 201];
}

function insight_security_other_admins(int $userId): int
{
    $statement = insight_auth_db()->prepare("SELECT COUNT(*) FROM auth_users WHERE id<>:id AND active=1 AND role='admin'");
    $statement->execute(['id' => $userId]);
    return (int)$statement->fetchColumn();
}

function insight_security_update_user(array $input, array $actor): array
{
    if (!insight_auth_can($actor, 'users:write')) {
        return ['ok' => false, 'status_code' => 403, 'error' => 'admin.auth.errorForbidden'];
    }
    $userId = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
    $role = insight_security_role($input['role'] ?? '');
    $active = filter_var($input['active'] ?? true, FILTER_VALIDATE_BOOLEAN);
    if ($userId === false || (int)$userId < 1 || $role === null) {
        return ['ok' => false, 'status_code' => 422, 'error' => 'admin.security.errorUser'];
    }
    $statement = insight_auth_db()->prepare('SELECT id,role,active FROM auth_users WHERE id=:id LIMIT 1');
    $statement->execute(['id' => (int)$userId]);
    $target = $statement->fetch();
    if (!is_array($target)) {
        return ['ok' => false, 'status_code' => 404, 'error' => 'admin.security.errorUser'];
    }
    if ((string)$target['role'] === 'admin' && (!$active || $role !== 'admin') && insight_security_other_admins((int)$userId) === 0) {
        return ['ok' => false, 'status_code' => 422, 'error' => 'admin.security.errorLastAdmin'];
    }
    $update = insight_auth_db()->prepare('UPDATE auth_users SET role=:role,active=:active,updated_at=CURRENT_TIMESTAMP WHERE id=:id');
    $update->execute(['role' => $role, 'active' => $active ? 1 : 0, 'id' => (int)$userId]);
    insight_auth_audit('user_updated', insight_auth_local_user_id($actor));
    return ['ok' => true, 'status_code' => 200];
}

function insight_security_delete_user(int $userId, array $actor): array
{
    if (!insight_auth_can($actor, 'users:write')) {
        return ['ok' => false, 'status_code' => 403, 'error' => 'admin.auth.errorForbidden'];
    }
    if ($userId < 1 || $userId === insight_auth_local_user_id($actor)) {
        return ['ok' => false, 'status_code' => 422, 'error' => 'admin.security.errorDeleteSelf'];
    }
    $statement = insight_auth_db()->prepare('SELECT role FROM auth_users WHERE id=:id LIMIT 1');
    $statement->execute(['id' => $userId]);
    $role = $statement->fetchColumn();
    if ($role === false) {
        return ['ok' => false, 'status_code' => 404, 'error' => 'admin.security.errorUser'];
    }
    if ($role === 'admin' && insight_security_other_admins($userId) === 0) {
        return ['ok' => false, 'status_code' => 422, 'error' => 'admin.security.errorLastAdmin'];
    }
    $delete = insight_auth_db()->prepare('DELETE FROM auth_users WHERE id=:id');
    $delete->execute(['id' => $userId]);
    insight_auth_audit('user_deleted', insight_auth_local_user_id($actor));
    return ['ok' => true, 'status_code' => 200];
}
