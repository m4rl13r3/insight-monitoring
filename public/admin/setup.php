<?php

declare(strict_types=1);

require_once __DIR__ . '/_layout.php';

if (insight_auth_is_configured()) {
    insight_auth_redirect(insight_auth_current_user() !== null ? '/admin/' : '/admin/login.php');
}

$errorKey = null;
$username = '';
$remember = true;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $remember = isset($_POST['remember']);
    if (!insight_auth_csrf_valid($_POST['csrf_token'] ?? null)) {
        $errorKey = 'admin.auth.errorCsrf';
    } else {
        $result = insight_auth_create_first_admin(
            $username,
            (string)($_POST['password'] ?? ''),
            (string)($_POST['password_confirmation'] ?? ''),
            $remember
        );
        if ($result['ok']) {
            insight_auth_redirect('/admin/');
        }
        $errorKey = (string)($result['error'] ?? 'admin.auth.errorSetup');
    }
}

insight_admin_page_start('admin.meta.setupTitle', 'admin.meta.description', 'admin-auth-page');
insight_admin_auth_topbar();
?>
  <main class="admin-auth-main">
    <section class="admin-auth-panel" aria-labelledby="admin-setup-title">
      <div class="admin-auth-heading">
        <span class="admin-auth-icon" aria-hidden="true"><i class="fa-solid fa-user-shield"></i></span>
        <div>
          <p class="admin-eyebrow" data-i18n="admin.setup.eyebrow">Configuration locale</p>
          <h1 id="admin-setup-title" data-i18n="admin.setup.title">Créer l’administrateur</h1>
        </div>
      </div>
      <p class="admin-auth-intro" data-i18n="admin.setup.description">Ce compte reste sur cette instance et protège uniquement le dashboard Insight.</p>
      <?php if ($errorKey !== null): ?>
        <div class="admin-auth-error" role="alert" data-auth-error="<?= insight_admin_escape($errorKey) ?>"><?= insight_admin_escape(insight_auth_error_message($errorKey)) ?></div>
      <?php endif; ?>
      <form class="admin-auth-form" method="post" action="/admin/setup.php">
        <input type="hidden" name="csrf_token" value="<?= insight_admin_escape(insight_auth_csrf_token()) ?>">
        <label class="admin-field">
          <span data-i18n="admin.auth.username">Identifiant</span>
          <span class="admin-input-wrap">
            <i class="fa-regular fa-user" aria-hidden="true"></i>
            <input name="username" type="text" value="<?= insight_admin_escape($username) ?>" autocomplete="username" minlength="3" maxlength="64" pattern="[A-Za-z0-9._-]+" required autofocus data-i18n-placeholder="admin.auth.usernamePlaceholder" placeholder="admin">
          </span>
        </label>
        <label class="admin-field">
          <span data-i18n="admin.auth.password">Mot de passe</span>
          <span class="admin-input-wrap">
            <i class="fa-solid fa-key" aria-hidden="true"></i>
            <input name="password" type="password" autocomplete="new-password" minlength="12" maxlength="1024" required data-i18n-placeholder="admin.auth.passwordPlaceholder" placeholder="12 caractères minimum">
            <button class="admin-password-toggle" type="button" aria-label="Afficher le mot de passe" title="Afficher le mot de passe" data-i18n-aria-label="admin.auth.showPassword" data-i18n-title="admin.auth.showPassword"><i class="fa-regular fa-eye" aria-hidden="true"></i></button>
          </span>
        </label>
        <label class="admin-field">
          <span data-i18n="admin.auth.passwordConfirmation">Confirmer le mot de passe</span>
          <span class="admin-input-wrap">
            <i class="fa-solid fa-key" aria-hidden="true"></i>
            <input name="password_confirmation" type="password" autocomplete="new-password" minlength="12" maxlength="1024" required data-i18n-placeholder="admin.auth.passwordConfirmationPlaceholder" placeholder="Répétez le mot de passe">
            <button class="admin-password-toggle" type="button" aria-label="Afficher le mot de passe" title="Afficher le mot de passe" data-i18n-aria-label="admin.auth.showPassword" data-i18n-title="admin.auth.showPassword"><i class="fa-regular fa-eye" aria-hidden="true"></i></button>
          </span>
        </label>
        <p class="admin-field-hint"><i class="fa-solid fa-lock" aria-hidden="true"></i><span data-i18n="admin.setup.passwordHint">Utilisez au moins 12 caractères. Le mot de passe est haché localement.</span></p>
        <label class="admin-checkbox">
          <input name="remember" type="checkbox" value="1"<?= $remember ? ' checked' : '' ?>>
          <span data-i18n="admin.auth.remember">Garder la session ouverte sur cet appareil</span>
        </label>
        <button class="admin-primary-button" type="submit"><i class="fa-solid fa-arrow-right" aria-hidden="true"></i><span data-i18n="admin.setup.submit">Créer le compte et continuer</span></button>
      </form>
      <p class="admin-auth-footnote"><i class="fa-solid fa-database" aria-hidden="true"></i><span data-i18n="admin.setup.storage">Les identifiants sont stockés dans la base locale privée de cette instance.</span></p>
    </section>
  </main>
<?php insight_admin_page_end(); ?>
