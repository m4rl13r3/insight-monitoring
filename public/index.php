<?php

declare(strict_types=1);

function insight_load_env(string $root): void
{
    $path = $root . '/.env';
    if (!is_file($path) || !is_readable($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return;
    }

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#') || !str_contains($trimmed, '=')) {
            continue;
        }

        [$name, $value] = explode('=', $trimmed, 2);
        $name = trim($name);
        $value = trim($value);
        if ($name === '' || getenv($name) !== false) {
            continue;
        }

        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
            (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}

function insight_env(string $name, string $default = ''): string
{
    $value = getenv($name);
    if ($value === false || trim((string)$value) === '') {
        return $default;
    }
    return trim((string)$value);
}

function insight_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function insight_public_page_url(string $value, string $fallback = ''): string
{
    $url = trim($value);
    if (preg_match('#^/(?!/)#', $url) === 1 || (filter_var($url, FILTER_VALIDATE_URL) !== false && str_starts_with(strtolower($url), 'https://'))) {
        return $url;
    }
    return $fallback;
}

$root = dirname(__DIR__);
insight_load_env($root);

$appName = insight_env('INSIGHT_APP_NAME', 'Insight');
$publicUrl = rtrim(insight_env('INSIGHT_PUBLIC_URL', ''), '/');
$contactEmail = insight_env('INSIGHT_CONTACT_EMAIL', 'contact@example.com');
$timezone = insight_env('INSIGHT_TIMEZONE', 'Europe/Paris');
$publicConfig = require __DIR__ . '/config/config.php';
require_once __DIR__ . '/_status_page.php';
$statusDatabase = insight_status_page_database($publicConfig);
$statusPage = insight_status_page_resolve($publicConfig, $statusDatabase);
$configuredLocale = strtolower((string)($statusPage['locale'] ?? 'auto'));
$privateLocale = $configuredLocale;
if (!in_array($privateLocale, ['en', 'fr'], true)) {
    $configuredDefaultLocale = strtolower(insight_env('INSIGHT_DEFAULT_LOCALE', 'auto'));
    $privateLocale = in_array($configuredDefaultLocale, ['en', 'fr'], true)
        ? $configuredDefaultLocale
        : (str_starts_with(strtolower((string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')), 'fr') ? 'fr' : 'en');
}
$statusPagePolicy = insight_status_page_access_policy($statusPage);
if (!insight_status_page_authorized($statusPage)) {
    if ($statusDatabase instanceof mysqli) {
        $statusDatabase->close();
    }
    $failed = isset($_GET['auth']) && $_GET['auth'] === 'failed';
    $limited = isset($_GET['auth']) && $_GET['auth'] === 'limited';
    $pageSlug = (string)($statusPage['slug'] ?? 'default');
    $pageName = (string)($statusPage['name'] ?? $appName);
    $privateCatalogPath = __DIR__ . '/locales/' . $privateLocale . '.json';
    $privateCatalog = is_readable($privateCatalogPath) ? json_decode((string)file_get_contents($privateCatalogPath), true) : [];
    $privateText = [
        'description' => (string)($privateCatalog['statusPage.privateDescription'] ?? 'This status page is private.'),
        'password' => (string)($privateCatalog['statusPage.password'] ?? 'Password'),
        'invalid' => (string)($privateCatalog['statusPage.invalidPassword'] ?? 'Invalid password.'),
        'limited' => (string)($privateCatalog['statusPage.tooManyAttempts'] ?? 'Too many attempts. Try again later.'),
        'submit' => (string)($privateCatalog['statusPage.open'] ?? 'Open status page'),
    ];
    $ssoText = (string)($privateCatalog['statusPage.signInDashboard'] ?? 'Sign in with the administration account');
    $ipText = (string)($privateCatalog['statusPage.ipDenied'] ?? 'Your network is not allowed to open this page.');
    http_response_code($statusPagePolicy === 'ip_allowlist' ? 403 : ($limited ? 429 : ($failed ? 401 : 200)));
    $control = match ($statusPagePolicy) {
        'sso' => '<a class="status-private-submit" href="/admin/status-page-sso.php?page=' . rawurlencode($pageSlug) . '">' . insight_escape($ssoText) . '</a>',
        'ip_allowlist' => '<p class="status-private-error">' . insight_escape($ipText) . '</p>',
        default => '<form class="status-private-form" method="post" action="/status-page-auth.php"><input type="hidden" name="page" value="' . insight_escape($pageSlug) . '"><label><span>' . insight_escape($privateText['password']) . '</span><input type="password" name="password" required autocomplete="current-password" autofocus></label>' . ($failed || $limited ? '<p class="status-private-error">' . insight_escape($limited ? $privateText['limited'] : $privateText['invalid']) . '</p>' : '') . '<button type="submit">' . insight_escape($privateText['submit']) . '</button></form>',
    };
    echo '<!doctype html><html lang="' . insight_escape($privateLocale) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . insight_escape($pageName) . '</title><link rel="stylesheet" href="/assets/shadcn.css?v=insight-014"></head><body><main class="status-private-page"><section class="status-private-form"><span class="status-private-icon"><i class="fa-solid fa-lock" aria-hidden="true"></i></span><h1>' . insight_escape($pageName) . '</h1><p>' . insight_escape($privateText['description']) . '</p>' . $control . '</section></main></body></html>';
    exit;
}
$statusPageLayout = insight_status_page_layout($publicConfig, $statusPage, $statusDatabase);
$subscriptionsRequested = in_array(strtolower(insight_env('INSIGHT_STATUS_SUBSCRIPTIONS_ENABLED', '0')), ['1', 'true', 'yes', 'on'], true);
$subscriberSecret = insight_env('INSIGHT_STATUS_SUBSCRIBER_SECRET', insight_env('INSIGHT_NOTIFICATION_ENCRYPTION_KEY', ''));
$subscriberDeliveryReady = insight_env('INSIGHT_EMAIL_SMTP_HOST', '') !== '' && insight_env('INSIGHT_EMAIL_SMTP_USERNAME', '') !== '';
if (!$subscriberDeliveryReady && $statusDatabase instanceof mysqli) {
    $subscriberChannelId = filter_var(getenv('INSIGHT_STATUS_SUBSCRIBER_SMTP_CHANNEL_ID') ?: null, FILTER_VALIDATE_INT);
    $subscriberChannelSelector = $subscriberChannelId !== false && (int)$subscriberChannelId > 0 ? ' AND id=' . (int)$subscriberChannelId : '';
    $subscriberChannelResult = $statusDatabase->query("SELECT id FROM notification_channels WHERE enabled=1 AND provider='smtp' AND last_status='success' AND last_test_at IS NOT NULL{$subscriberChannelSelector} LIMIT 1");
    $subscriberDeliveryReady = $subscriberChannelResult instanceof mysqli_result && $subscriberChannelResult->num_rows === 1;
    if ($subscriberChannelResult instanceof mysqli_result) {
        $subscriberChannelResult->free();
    }
}
$notificationsEnabled = !in_array(strtolower(insight_env('INSIGHT_DISABLE_NOTIFICATIONS', '1')), ['1', 'true', 'yes', 'on'], true);
$subscriptionsEnabled = $subscriptionsRequested && $notificationsEnabled && strlen($subscriberSecret) >= 32 && $subscriberDeliveryReady;
if ($statusDatabase instanceof mysqli) {
    $statusDatabase->close();
}
$pageName = trim((string)($statusPage['name'] ?? ''));
if ($pageName !== '') {
    $appName = $pageName;
}
$defaultLocale = strtolower(insight_env('INSIGHT_DEFAULT_LOCALE', 'auto'));
if (($statusPage['locale'] ?? 'auto') !== 'auto') {
    $defaultLocale = strtolower((string)$statusPage['locale']);
}
$supportedLocales = array_values(array_filter(
    array_map('trim', explode(',', insight_env('INSIGHT_SUPPORTED_LOCALES', 'en,fr'))),
    static fn(string $locale): bool => preg_match('/^[a-z]{2}$/i', $locale) === 1
));
if ($supportedLocales === []) {
    $supportedLocales = ['en', 'fr'];
}
$pageSlug = (string)($statusPage['slug'] ?? 'default');
$pageDescription = trim((string)($statusPage['description'] ?? ''));
$title = 'System status | ' . $appName;
$description = $pageDescription !== '' ? $pageDescription : 'Availability, incidents, and maintenance for services monitored with Insight.';
$customDomain = strtolower(trim((string)($statusPage['custom_domain'] ?? '')));
$canonical = $customDomain !== ''
    ? 'https://' . $customDomain
    : ($publicUrl !== '' ? $publicUrl . ($pageSlug !== 'default' ? '/?page=' . rawurlencode($pageSlug) : '') : '');
$pageUrl = $pageSlug !== 'default' ? '/?page=' . rawurlencode($pageSlug) : '/';
$rssUrl = '/hourly_stats_report.php?contract=v2&amp;mode=incidents&amp;format=rss&amp;page=' . rawurlencode($pageSlug);
$pageTheme = in_array((string)($statusPage['theme'] ?? 'system'), ['light', 'dark', 'system'], true) ? (string)$statusPage['theme'] : 'system';
$pageAccent = preg_match('/^#[0-9a-f]{6}$/i', (string)($statusPage['accent_color'] ?? '')) === 1 ? strtolower((string)$statusPage['accent_color']) : '#16a34a';
$themeStorageKey = 'insight-status-page-theme-' . preg_replace('/[^a-z0-9-]/', '', strtolower($pageSlug));
$pageLogoUrl = insight_public_page_url((string)($statusPage['logo_url'] ?? ''), '/favicons/favicon.svg');
$pageFaviconUrl = insight_public_page_url((string)($statusPage['favicon_url'] ?? ''), '/favicons/favicon.svg');
$announcement = trim((string)($statusPage['announcement'] ?? ''));
$announcementUrl = insight_public_page_url((string)($statusPage['announcement_url'] ?? ''));
$announcementMarkup = $announcement === '' ? '' : '<aside class="status-announcement"><i class="fa-solid fa-bullhorn" aria-hidden="true"></i><span>' . insight_escape($announcement) . '</span>' . ($announcementUrl !== '' ? '<a href="' . insight_escape($announcementUrl) . '"><i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i></a>' : '') . '</aside>';
$navigation = json_decode((string)($statusPage['navigation_links_json'] ?? '[]'), true);
$navigationMarkup = '';
foreach (is_array($navigation) ? array_slice($navigation, 0, 8) : [] as $link) {
    if (!is_array($link)) {
        continue;
    }
    $linkLabel = mb_substr(trim((string)($link['label'] ?? '')), 0, 80, 'UTF-8');
    $linkUrl = insight_public_page_url((string)($link['url'] ?? ''));
    if ($linkLabel !== '' && $linkUrl !== '') {
        $navigationMarkup .= '<a class="insight-navigation-link" href="' . insight_escape($linkUrl) . '">' . insight_escape($linkLabel) . '</a>';
    }
}
$customCss = trim((string)($statusPage['custom_css'] ?? ''));
if (strlen($customCss) > 20000 || preg_match('#</?style|@import|url\s*\(|expression\s*\(|javascript:#i', $customCss) === 1) {
    $customCss = '';
}
$historyDays = max(1, min(365, (int)($statusPage['history_days'] ?? 90)));
$hideFromSearch = (int)($statusPage['hide_from_search_engines'] ?? 0) === 1 || $statusPagePolicy !== 'public';

$head = '<meta charset="utf-8">' . PHP_EOL
    . '  <meta name="viewport" content="width=device-width, initial-scale=1">' . PHP_EOL
    . '  <meta name="theme-color" content="#f7f7f5">' . PHP_EOL
    . '  <script>(function(){var t=' . json_encode($pageTheme) . ',k=' . json_encode($themeStorageKey) . ';try{var s=localStorage.getItem(k);if(s==="light"||s==="dark"||s==="system"){t=s}}catch(e){}var r=t==="system"?(matchMedia("(prefers-color-scheme: dark)").matches?"dark":"light"):t;document.documentElement.classList.remove("light","dark");document.documentElement.classList.add(r);document.documentElement.dataset.insightTheme=t;document.documentElement.style.colorScheme=r;var m=document.querySelector("meta[name=theme-color]");if(m){m.content=r==="dark"?"#09090b":"#ffffff"}})();</script>' . PHP_EOL
    . '  <title>' . insight_escape($title) . '</title>' . PHP_EOL
    . '  <meta name="description" content="' . insight_escape($description) . '">' . PHP_EOL
    . ($hideFromSearch ? '  <meta name="robots" content="noindex,nofollow,noarchive">' . PHP_EOL : '')
    . ($canonical !== '' ? '  <link rel="canonical" href="' . insight_escape($canonical) . '">' . PHP_EOL : '')
    . '  <link rel="icon" href="' . insight_escape($pageFaviconUrl) . '">' . PHP_EOL
    . '  <link rel="manifest" href="/favicons/site.webmanifest">' . PHP_EOL
    . '  <style>:root{--status-page-accent:' . insight_escape($pageAccent) . '}</style>' . PHP_EOL
    . '  <link rel="stylesheet" href="/assets/shadcn.css?v=insight-014">' . PHP_EOL
    . ($customCss !== '' ? '  <style id="insight-status-page-custom">' . $customCss . '</style>' . PHP_EOL : '')
    . '  <script>window.INSIGHT_CONFIG=' . json_encode([
        'appName' => $appName,
        'publicUrl' => $publicUrl,
        'contactEmail' => $contactEmail,
        'timezone' => $timezone,
        'apiBaseUrl' => '',
        'defaultLocale' => $defaultLocale,
        'supportedLocales' => array_map('strtolower', $supportedLocales),
        'localeVersion' => 'insight-i18n-8',
        'statusPageSlug' => $pageSlug,
        'statusPageId' => (int)($statusPage['id'] ?? 0),
        'statusPageDescription' => (string)($statusPage['description'] ?? ''),
        'statusPageTheme' => $pageTheme,
        'statusPageThemeStorageKey' => $themeStorageKey,
        'statusPageAccent' => $pageAccent,
        'statusPageLayout' => $statusPageLayout,
        'statusPageHistoryDays' => $historyDays,
        'subscriptionsEnabled' => $subscriptionsEnabled,
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';</script>';

$template = file_get_contents(__DIR__ . '/index.html');
if (!is_string($template)) {
    http_response_code(500);
    echo 'Insight template not found.';
    exit;
}

echo strtr($template, [
    '{{INSIGHT_HEAD}}' => $head,
    '{{INSIGHT_APP_NAME}}' => insight_escape($appName),
    '{{INSIGHT_CONTACT_EMAIL}}' => insight_escape($contactEmail),
    '{{INSIGHT_CONTACT_MAILTO}}' => 'mailto:' . insight_escape($contactEmail),
    '{{INSIGHT_LOCALE}}' => insight_escape(in_array($defaultLocale, $supportedLocales, true) ? $defaultLocale : 'en'),
    '{{INSIGHT_PAGE_DESCRIPTION}}' => insight_escape($pageDescription),
    '{{INSIGHT_PAGE_URL}}' => insight_escape($pageUrl),
    '{{INSIGHT_RSS_URL}}' => $rssUrl,
    '{{INSIGHT_LOGO_URL}}' => insight_escape($pageLogoUrl),
    '{{INSIGHT_ANNOUNCEMENT}}' => $announcementMarkup,
    '{{INSIGHT_NAVIGATION_LINKS}}' => $navigationMarkup,
]);
