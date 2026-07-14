<?php

declare(strict_types=1);

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/_bootstrap.php';

function insight_admin_supported_locales(): array
{
    $locales = array_values(array_filter(
        array_map('trim', explode(',', insight_admin_env('INSIGHT_SUPPORTED_LOCALES', 'en,fr'))),
        static fn(string $locale): bool => preg_match('/^[a-z]{2}$/i', $locale) === 1
    ));
    return $locales === [] ? ['en', 'fr'] : array_map('strtolower', $locales);
}

function insight_admin_page_start(
    string $titleKey,
    string $descriptionKey,
    string $bodyClass = ''
): void {
    global $insightAdminConfig;
    $appName = (string)($insightAdminConfig['app_name'] ?? 'Insight');
    $nonce = insight_admin_nonce();
    $config = [
        'appName' => $appName,
        'publicUrl' => (string)($insightAdminConfig['public_url'] ?? ''),
        'contactEmail' => (string)($insightAdminConfig['contact_email'] ?? ''),
        'timezone' => (string)($insightAdminConfig['timezone'] ?? 'Europe/Paris'),
        'apiBaseUrl' => '',
        'defaultLocale' => strtolower(insight_admin_env('INSIGHT_DEFAULT_LOCALE', 'auto')),
        'supportedLocales' => insight_admin_supported_locales(),
        'localeVersion' => 'insight-i18n-7',
        'csrfToken' => insight_auth_csrf_token(),
    ];
    $configJson = json_encode(
        $config,
        JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );
    $classes = trim('admin-page ' . $bodyClass);
    ?>
<!DOCTYPE html>
<html lang="fr" data-insight-theme="system" data-insight-title-key="<?= insight_admin_escape($titleKey) ?>" data-insight-description-key="<?= insight_admin_escape($descriptionKey) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#ffffff">
  <meta name="description" content="Administration locale Insight">
  <script nonce="<?= insight_admin_escape($nonce) ?>">(function(){var t="system";try{var s=localStorage.getItem("insight-ui-theme");if(s==="light"||s==="dark"||s==="system"){t=s}}catch(e){}var r=t==="system"?(matchMedia("(prefers-color-scheme: dark)").matches?"dark":"light"):t;document.documentElement.classList.remove("light","dark");document.documentElement.classList.add(r);document.documentElement.dataset.insightTheme=t;document.documentElement.style.colorScheme=r;var m=document.querySelector("meta[name=theme-color]");if(m){m.content=r==="dark"?"#09090b":"#ffffff"}})();</script>
  <title><?= insight_admin_escape($appName) ?> · Administration</title>
  <link rel="icon" href="/favicons/favicon.svg" type="image/svg+xml">
  <link rel="stylesheet" href="/assets/shadcn.css?v=insight-013">
  <link rel="stylesheet" href="/admin/admin.css?v=insight-013">
  <script nonce="<?= insight_admin_escape($nonce) ?>">window.INSIGHT_CONFIG=<?= $configJson ?>;</script>
</head>
<body class="<?= insight_admin_escape($classes) ?>">
<?php
}

function insight_admin_auth_topbar(): void
{
    global $insightAdminConfig;
    $appName = (string)($insightAdminConfig['app_name'] ?? 'Insight');
    ?>
  <header class="admin-auth-topbar">
    <a class="admin-brand" href="/" aria-label="Insight home" data-i18n-aria-label="common.home">
      <img src="/favicons/favicon.svg" alt="" width="30" height="30">
      <span><?= insight_admin_escape($appName) ?></span>
    </a>
    <div class="admin-auth-actions">
      <a class="admin-icon-button" href="/" aria-label="Public status page" title="Public status page" data-i18n-aria-label="admin.publicStatus" data-i18n-title="admin.publicStatus">
        <i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i>
      </a>
      <div id="insight-controls-root"></div>
    </div>
  </header>
<?php
}

function insight_admin_page_end(): void
{
    ?>
  <script src="/js/i18n.js?v=insight-013"></script>
  <script type="module" src="/assets/shadcn-theme.js?v=insight-013"></script>
  <script src="/admin/admin.js?v=insight-013" defer></script>
</body>
</html>
<?php
}
