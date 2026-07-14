<?php

declare(strict_types=1);

require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/_oidc.php';

if (!insight_auth_is_configured()) {
    insight_auth_redirect('/admin/setup.php');
}

$next = insight_auth_safe_next($_POST['next'] ?? $_GET['next'] ?? '/admin/');
$currentUser = insight_auth_current_user();
if ($currentUser !== null) {
    insight_auth_redirect($next);
}
if (session_status() !== PHP_SESSION_ACTIVE) {
    insight_admin_start_session($insightAuthSessionsPath);
}

$sso = insight_oidc_config();
$localRequested = isset($_GET['local']) || ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
if (isset($_GET['cancel_totp'])) {
    insight_auth_cancel_pending();
}
$ssoErrors = [
    'admin.sso.errorConfiguration',
    'admin.sso.errorDenied',
    'admin.sso.errorSession',
    'admin.sso.errorGeneric',
];
$ssoError = (string)($_GET['sso_error'] ?? '');
$errorKey = in_array($ssoError, $ssoErrors, true) ? $ssoError : null;
$username = '';
$remember = false;
$requiresTotp = insight_auth_totp_pending();

if (
    ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'
    && ($sso['enabled'] ?? false)
    && ($sso['valid'] ?? false)
    && ($sso['auto_login'] ?? false)
    && !$localRequested
    && $errorKey === null
) {
    insight_auth_redirect('/admin/sso/login.php?next=' . rawurlencode($next));
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!insight_auth_csrf_valid($_POST['csrf_token'] ?? null)) {
        $errorKey = 'admin.auth.errorCsrf';
    } elseif (isset($_POST['totp_code']) && insight_auth_totp_pending()) {
        $result = insight_auth_complete_totp((string)$_POST['totp_code']);
        if ($result['ok']) {
            insight_auth_redirect($next);
        }
        $errorKey = (string)($result['error'] ?? 'admin.auth.errorTotp');
        $requiresTotp = insight_auth_totp_pending();
    } else {
        $username = trim((string)($_POST['username'] ?? ''));
        $remember = isset($_POST['remember']);
        $result = insight_auth_login($username, (string)($_POST['password'] ?? ''), $remember);
        if ($result['ok']) {
            insight_auth_redirect($next);
        }
        $requiresTotp = (bool)($result['requires_totp'] ?? false);
        if (!$requiresTotp) {
            $errorKey = (string)($result['error'] ?? 'admin.auth.errorInvalid');
        }
    }
}

insight_admin_page_start('admin.meta.loginTitle', 'admin.meta.description', 'admin-auth-page');
insight_admin_auth_topbar();
?>
  <main class="admin-auth-main">
    <section class="admin-auth-panel" aria-labelledby="admin-login-title">
      <div class="admin-auth-heading">
        <span class="admin-auth-icon" aria-hidden="true"><i class="fa-solid fa-shield-halved"></i></span>
        <div>
          <p class="admin-eyebrow" data-i18n="admin.login.eyebrow">Administration</p>
          <h1 id="admin-login-title" data-i18n="admin.login.title">Sign in to Insight</h1>
        </div>
      </div>
      <p class="admin-auth-intro" data-i18n="<?= $requiresTotp ? 'admin.login.totpDescription' : 'admin.login.description' ?>"><?= $requiresTotp ? 'Enter the code from your authenticator app or a recovery code.' : 'Access monitors, incidents, and settings for this instance.' ?></p>
      <?php if (isset($_GET['logged_out'])): ?>
        <div class="admin-auth-success" role="status"><i class="fa-solid fa-check" aria-hidden="true"></i><span data-i18n="admin.login.loggedOut">The session has been closed.</span></div>
      <?php endif; ?>
      <?php if ($errorKey !== null): ?>
        <div class="admin-auth-error" role="alert" data-auth-error="<?= insight_admin_escape($errorKey) ?>"><?= insight_admin_escape(insight_auth_error_message($errorKey)) ?></div>
      <?php endif; ?>
      <?php if (!$requiresTotp && ($sso['enabled'] ?? false) && ($sso['valid'] ?? false)): ?>
        <a class="admin-primary-button admin-sso-button" href="/admin/sso/login.php?next=<?= rawurlencode($next) ?>"><i class="fa-solid fa-building-shield" aria-hidden="true"></i><span><span data-i18n="admin.sso.continueWith">Continue with</span> <?= insight_admin_escape((string)$sso['provider_name']) ?></span></a>
      <?php elseif (!$requiresTotp && ($sso['enabled'] ?? false)): ?>
        <div class="admin-auth-error" role="alert" data-i18n="admin.sso.errorConfiguration">The SSO configuration is incomplete or insecure.</div>
      <?php endif; ?>
      <?php if (!$requiresTotp && ($sso['enabled'] ?? false) && ($sso['valid'] ?? false) && !($sso['hide_local_login'] ?? false)): ?>
        <div class="admin-auth-divider"><span data-i18n="admin.sso.orLocal">or use the local account</span></div>
      <?php endif; ?>
      <?php if ($requiresTotp): ?>
      <form class="admin-auth-form" method="post" action="/admin/login.php">
        <input type="hidden" name="csrf_token" value="<?= insight_admin_escape(insight_auth_csrf_token()) ?>">
        <input type="hidden" name="next" value="<?= insight_admin_escape($next) ?>">
        <label class="admin-field">
          <span data-i18n="admin.auth.totpCode">Verification code</span>
          <span class="admin-input-wrap">
            <i class="fa-solid fa-shield" aria-hidden="true"></i>
            <input name="totp_code" type="text" inputmode="numeric" autocomplete="one-time-code" minlength="6" maxlength="16" required autofocus data-i18n-placeholder="admin.auth.totpPlaceholder" placeholder="000000">
          </span>
        </label>
        <button class="admin-primary-button" type="submit"><i class="fa-solid fa-check" aria-hidden="true"></i><span data-i18n="admin.login.verify">Verify and sign in</span></button>
        <a class="admin-public-link" href="/admin/login.php?cancel_totp=1&amp;next=<?= rawurlencode($next) ?>"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i><span data-i18n="admin.login.backCredentials">Use another account</span></a>
      </form>
      <?php elseif (!($sso['hide_local_login'] ?? false) || $localRequested): ?>
      <form class="admin-auth-form" method="post" action="/admin/login.php">
        <input type="hidden" name="csrf_token" value="<?= insight_admin_escape(insight_auth_csrf_token()) ?>">
        <input type="hidden" name="next" value="<?= insight_admin_escape($next) ?>">
        <label class="admin-field">
          <span data-i18n="admin.auth.username">Username</span>
          <span class="admin-input-wrap">
            <i class="fa-regular fa-user" aria-hidden="true"></i>
            <input name="username" type="text" value="<?= insight_admin_escape($username) ?>" autocomplete="username" maxlength="64" required autofocus data-i18n-placeholder="admin.auth.usernamePlaceholder" placeholder="admin">
          </span>
        </label>
        <label class="admin-field">
          <span data-i18n="admin.auth.password">Password</span>
          <span class="admin-input-wrap">
            <i class="fa-solid fa-key" aria-hidden="true"></i>
            <input name="password" type="password" autocomplete="current-password" maxlength="1024" required data-i18n-placeholder="admin.auth.passwordLoginPlaceholder" placeholder="Your password">
            <button class="admin-password-toggle" type="button" aria-label="Show password" title="Show password" data-i18n-aria-label="admin.auth.showPassword" data-i18n-title="admin.auth.showPassword"><i class="fa-regular fa-eye" aria-hidden="true"></i></button>
          </span>
        </label>
        <label class="admin-checkbox">
          <input name="remember" type="checkbox" value="1"<?= $remember ? ' checked' : '' ?>>
          <span data-i18n="admin.auth.remember">Keep this session open on this device</span>
        </label>
        <button class="admin-primary-button" type="submit"><i class="fa-solid fa-right-to-bracket" aria-hidden="true"></i><span data-i18n="admin.login.submit">Sign in</span></button>
      </form>
      <?php elseif ($sso['enabled'] ?? false): ?>
        <a class="admin-public-link" href="/admin/login.php?local=1&amp;next=<?= rawurlencode($next) ?>"><i class="fa-solid fa-key" aria-hidden="true"></i><span data-i18n="admin.sso.localFallback">Use the emergency local account</span></a>
      <?php endif; ?>
      <a class="admin-public-link" href="/"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i><span data-i18n="admin.login.backPublic">Back to the status page</span></a>
    </section>
  </main>
<?php insight_admin_page_end(); ?>
