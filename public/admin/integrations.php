<?php

declare(strict_types=1);

require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/_oidc.php';

insight_auth_require_user();
$access = insight_access_state();
$sso = insight_oidc_public_state();
$issuer = (string)$access['issuer'];
$apiBase = (string)$access['api_base_url'];
$discovery = (string)$access['discovery_url'];

insight_admin_page_start('admin.access.guideTitle', 'admin.access.guideDescription', 'admin-guide-page');
insight_admin_auth_topbar();
?>
  <main class="admin-guide-main">
    <header class="admin-guide-heading">
      <a class="admin-icon-button" href="/admin/#account" aria-label="Back to access" title="Back to access" data-i18n-aria-label="admin.access.back" data-i18n-title="admin.access.back"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i></a>
      <div><p class="admin-eyebrow">API · OAuth 2.0 · OpenID Connect</p><h1 data-i18n="admin.access.guideTitle">Integrate Insight</h1><p data-i18n="admin.access.guideDescription">Endpoints and settings for your automation and dashboards.</p></div>
    </header>

    <section class="admin-guide-section" aria-labelledby="guide-api-title">
      <div class="admin-guide-section-heading"><span><i class="fa-solid fa-code" aria-hidden="true"></i></span><div><h2 id="guide-api-title">API headless</h2><p><span data-i18n="admin.access.guideApiBefore">Enable the API and create a token from</span> <a href="/admin/#account" data-i18n="admin.access.title">Access</a><span data-i18n="admin.access.guideApiAfter">, using only the required permissions.</span></p></div></div>
      <pre><code>curl -H "Authorization: Bearer API_TOKEN" \
  <?= insight_admin_escape($apiBase) ?>/status.php</code></pre>
      <div class="admin-guide-table" role="table" aria-label="Headless API routes" data-i18n-aria-label="admin.access.guideRoutesLabel">
        <div role="row"><strong role="cell">GET</strong><code role="cell">/status.php</code><span role="cell">status:read</span></div>
        <div role="row"><strong role="cell">GET</strong><code role="cell">/monitors.php</code><span role="cell">monitors:read</span></div>
        <div role="row"><strong role="cell">POST · PATCH · DELETE</strong><code role="cell">/monitors.php</code><span role="cell">monitors:write</span></div>
        <div role="row"><strong role="cell">GET</strong><code role="cell">/incidents.php</code><span role="cell">incidents:read</span></div>
        <div role="row"><strong role="cell">GET · POST · PATCH · DELETE</strong><code role="cell">/notifications.php</code><span role="cell">notifications:read / write</span></div>
      </div>
      <p class="admin-guide-note"><i class="fa-solid fa-file-code" aria-hidden="true"></i><span><span data-i18n="admin.access.guideOpenApi">OpenAPI schema available once the API is enabled:</span> <code><?= insight_admin_escape($apiBase) ?>/openapi.php</code></span></p>
    </section>

    <section class="admin-guide-section" aria-labelledby="guide-provider-title">
      <div class="admin-guide-section-heading"><span><i class="fa-solid fa-link" aria-hidden="true"></i></span><div><h2 id="guide-provider-title" data-i18n="admin.access.guideProviderTitle">Authenticate another dashboard</h2><p data-i18n="admin.access.guideProviderDescription">Register its exact redirect URI, then use OpenID Connect discovery.</p></div></div>
      <dl class="admin-guide-values"><div><dt>Issuer</dt><dd><code><?= insight_admin_escape($issuer) ?></code></dd></div><div><dt>Discovery</dt><dd><code><?= insight_admin_escape($discovery) ?></code></dd></div><div><dt>Flow</dt><dd><code>Authorization Code + PKCE S256</code></dd></div></dl>
      <pre><code>{
  "issuer": "<?= insight_admin_escape($issuer) ?>",
  "client_id": "CLIENT_ID",
  "client_secret": "CLIENT_SECRET",
  "redirect_uri": "https://dashboard.example.com/auth/callback",
  "scope": "openid profile status:read"
}</code></pre>
      <p class="admin-guide-note"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i><span><span data-i18n="admin.access.guideClientVerifyBefore">The client must verify</span> <code>state</code>, <code>nonce</code><span data-i18n="admin.access.guideClientVerifyAfter">, the issuer, audience, and RS256 signature. Keep the secret on its server only.</span></span></p>
    </section>

    <section class="admin-guide-section" aria-labelledby="guide-sso-title">
      <div class="admin-guide-section-heading"><span><i class="fa-solid fa-building-shield" aria-hidden="true"></i></span><div><h2 id="guide-sso-title" data-i18n="admin.access.guideSsoTitle">Delegate Insight sign-in</h2><p data-i18n="admin.access.guideSsoDescription">Create a confidential OIDC client with this exact callback URL at your identity provider.</p></div></div>
      <div class="admin-guide-copy-line"><code><?= insight_admin_escape((string)$sso['callback_url']) ?></code></div>
      <pre><code>INSIGHT_SSO_ENABLED=1
INSIGHT_SSO_PROVIDER_NAME=Organization
INSIGHT_SSO_ISSUER_URL=https://id.example.com
INSIGHT_SSO_ALLOWED_ENDPOINT_HOSTS=
INSIGHT_SSO_CLIENT_ID=insight
INSIGHT_SSO_CLIENT_SECRET=OIDC_CLIENT_SECRET
INSIGHT_SSO_ALLOWED_GROUPS=ops,status-admins
INSIGHT_SSO_ADMIN_GROUPS=status-admins</code></pre>
      <p class="admin-guide-note"><i class="fa-solid fa-key" aria-hidden="true"></i><span><span data-i18n="admin.access.guideSsoPolicyBefore">An email list, group list, or</span> <code>INSIGHT_SSO_ALLOW_ALL=1</code> <span data-i18n="admin.access.guideSsoPolicyAfter">is required. The local account remains available at</span> <code>/admin/login.php?local=1</code>.</span></p>
    </section>
  </main>
<?php insight_admin_page_end(); ?>
