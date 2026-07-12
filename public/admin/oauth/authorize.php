<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_layout.php';
require_once dirname(__DIR__) . '/_oauth.php';

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    http_response_code(405);
    exit;
}

$request = insight_oauth_authorization_request($method === 'POST' ? $_POST : $_GET);
if (!($request['ok'] ?? false)) {
    $redirectUri = (string)($request['redirect_uri'] ?? '');
    $state = (string)($request['state'] ?? '');
    if ($redirectUri !== '' && $state !== '') {
        insight_auth_redirect(insight_oauth_append_query($redirectUri, [
            'error' => (string)($request['error'] ?? 'invalid_request'),
            'state' => $state,
            'iss' => insight_access_public_url(),
        ]));
    }
    http_response_code((int)($request['status_code'] ?? 400));
}

$user = ($request['ok'] ?? false) ? insight_auth_require_user() : null;

if (($request['ok'] ?? false) && $method === 'POST') {
    if (!insight_auth_csrf_valid($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        $request = ['ok' => false, 'error' => 'session_expired'];
    } elseif ((string)($_POST['decision'] ?? '') !== 'approve') {
        insight_auth_redirect(insight_oauth_append_query((string)$request['redirect_uri'], [
            'error' => 'access_denied',
            'state' => (string)$request['state'],
            'iss' => insight_access_public_url(),
        ]));
    } else {
        $code = insight_oauth_create_code($request, (array)$user);
        insight_auth_audit('oauth_authorization_granted', insight_access_local_owner_id($user));
        insight_auth_redirect(insight_oauth_append_query((string)$request['redirect_uri'], [
            'code' => $code,
            'state' => (string)$request['state'],
            'iss' => insight_access_public_url(),
        ]));
    }
}

insight_admin_page_start('admin.oauth.consentTitle', 'admin.oauth.consentDescription', 'admin-auth-page');
insight_admin_auth_topbar();
?>
  <main class="admin-auth-main">
    <section class="admin-auth-panel" aria-labelledby="oauth-consent-title">
      <?php if (!($request['ok'] ?? false)): ?>
        <div class="admin-auth-heading">
          <span class="admin-auth-icon" aria-hidden="true"><i class="fa-solid fa-triangle-exclamation"></i></span>
          <div><p class="admin-eyebrow">OpenID Connect</p><h1 id="oauth-consent-title" data-i18n="admin.oauth.invalidTitle">Demande invalide</h1></div>
        </div>
        <p class="admin-auth-intro" data-i18n="admin.oauth.invalidDescription">Le dashboard appelant n’a pas fourni une demande OAuth valide.</p>
      <?php else: ?>
        <div class="admin-auth-heading">
          <span class="admin-auth-icon" aria-hidden="true"><i class="fa-solid fa-link"></i></span>
          <div><p class="admin-eyebrow">OpenID Connect</p><h1 id="oauth-consent-title" data-i18n="admin.oauth.consentTitle">Autoriser ce dashboard</h1></div>
        </div>
        <p class="admin-auth-intro"><strong><?= insight_admin_escape((string)$request['client']['name']) ?></strong> <span data-i18n="admin.oauth.consentDescription">souhaite utiliser votre identité Insight.</span></p>
        <ul class="admin-oauth-scope-list">
          <?php foreach ($request['scopes'] as $scope): ?>
            <li><i class="fa-solid fa-check" aria-hidden="true"></i><span data-i18n="<?= insight_admin_escape(insight_access_scope_i18n_key((string)$scope)) ?>"><?= insight_admin_escape((string)(insight_access_oauth_scope_catalog()[$scope] ?? $scope)) ?></span></li>
          <?php endforeach; ?>
        </ul>
        <form class="admin-oauth-consent-actions" method="post" action="/admin/oauth/authorize.php">
          <input type="hidden" name="csrf_token" value="<?= insight_admin_escape(insight_auth_csrf_token()) ?>">
          <?php foreach (['client_id', 'redirect_uri', 'scope', 'state', 'nonce', 'code_challenge', 'code_challenge_method'] as $field): ?>
            <input type="hidden" name="<?= insight_admin_escape($field) ?>" value="<?= insight_admin_escape((string)$request[$field]) ?>">
          <?php endforeach; ?>
          <input type="hidden" name="response_type" value="code">
          <button class="admin-secondary-button" type="submit" name="decision" value="deny" data-i18n="admin.oauth.deny">Refuser</button>
          <button class="admin-primary-button" type="submit" name="decision" value="approve"><i class="fa-solid fa-check" aria-hidden="true"></i><span data-i18n="admin.oauth.approve">Autoriser</span></button>
        </form>
      <?php endif; ?>
    </section>
  </main>
<?php insight_admin_page_end(); ?>
