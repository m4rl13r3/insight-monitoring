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

$root = dirname(__DIR__);
insight_load_env($root);

$appName = insight_env('INSIGHT_APP_NAME', 'Insight');
$publicUrl = rtrim(insight_env('INSIGHT_PUBLIC_URL', ''), '/');
$contactEmail = insight_env('INSIGHT_CONTACT_EMAIL', 'contact@example.com');
$timezone = insight_env('INSIGHT_TIMEZONE', 'Europe/Paris');
$defaultLocale = strtolower(insight_env('INSIGHT_DEFAULT_LOCALE', 'auto'));
$supportedLocales = array_values(array_filter(
    array_map('trim', explode(',', insight_env('INSIGHT_SUPPORTED_LOCALES', 'fr,en'))),
    static fn(string $locale): bool => preg_match('/^[a-z]{2}$/i', $locale) === 1
));
if ($supportedLocales === []) {
    $supportedLocales = ['fr', 'en'];
}
$title = 'État des systèmes | ' . $appName;
$description = 'Disponibilité, incidents et maintenances des services surveillés avec Insight.';
$canonical = $publicUrl !== '' ? $publicUrl : '';

$head = '<meta charset="utf-8">' . PHP_EOL
    . '  <meta name="viewport" content="width=device-width, initial-scale=1">' . PHP_EOL
    . '  <meta name="theme-color" content="#f7f7f5">' . PHP_EOL
    . '  <script>(function(){var t="system";try{var s=localStorage.getItem("insight-ui-theme");if(s==="light"||s==="dark"||s==="system"){t=s}}catch(e){}var r=t==="system"?(matchMedia("(prefers-color-scheme: dark)").matches?"dark":"light"):t;document.documentElement.classList.remove("light","dark");document.documentElement.classList.add(r);document.documentElement.dataset.insightTheme=t;document.documentElement.style.colorScheme=r;var m=document.querySelector("meta[name=theme-color]");if(m){m.content=r==="dark"?"#09090b":"#ffffff"}})();</script>' . PHP_EOL
    . '  <title>' . insight_escape($title) . '</title>' . PHP_EOL
    . '  <meta name="description" content="' . insight_escape($description) . '">' . PHP_EOL
    . ($canonical !== '' ? '  <link rel="canonical" href="' . insight_escape($canonical) . '">' . PHP_EOL : '')
    . '  <link rel="icon" href="/favicons/favicon.svg" type="image/svg+xml">' . PHP_EOL
    . '  <link rel="manifest" href="/favicons/site.webmanifest">' . PHP_EOL
    . '  <link rel="stylesheet" href="/assets/shadcn.css?v=insight-shadcn-3">' . PHP_EOL
    . '  <script>window.INSIGHT_CONFIG=' . json_encode([
        'appName' => $appName,
        'publicUrl' => $publicUrl,
        'contactEmail' => $contactEmail,
        'timezone' => $timezone,
        'apiBaseUrl' => '',
        'defaultLocale' => $defaultLocale,
        'supportedLocales' => array_map('strtolower', $supportedLocales),
        'localeVersion' => 'insight-i18n-6',
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';</script>';

$template = file_get_contents(__DIR__ . '/index.html');
if (!is_string($template)) {
    http_response_code(500);
    echo 'Template Insight introuvable.';
    exit;
}

echo strtr($template, [
    '{{INSIGHT_HEAD}}' => $head,
    '{{INSIGHT_APP_NAME}}' => insight_escape($appName),
    '{{INSIGHT_CONTACT_EMAIL}}' => insight_escape($contactEmail),
    '{{INSIGHT_CONTACT_MAILTO}}' => 'mailto:' . insight_escape($contactEmail),
]);
