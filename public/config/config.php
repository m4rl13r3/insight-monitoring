<?php

declare(strict_types=1);

if (!function_exists('insight_public_load_env')) {
    function insight_public_load_env(string $root): void
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
}

if (!function_exists('insight_public_env')) {
    function insight_public_env(string $name, string $default = ''): string
    {
        $value = getenv($name);
        if ($value === false || trim((string)$value) === '') {
            return $default;
        }
        return trim((string)$value);
    }
}

$root = dirname(__DIR__, 2);
insight_public_load_env($root);

return [
    'servername' => insight_public_env('INSIGHT_DB_HOST', insight_public_env('MONITORING_DB_HOST', 'db')),
    'port' => insight_public_env('INSIGHT_DB_PORT', insight_public_env('MONITORING_DB_PORT', '3306')),
    'username' => insight_public_env('INSIGHT_DB_USER', insight_public_env('MONITORING_DB_USER', 'insight')),
    'password' => insight_public_env('INSIGHT_DB_PASSWORD', insight_public_env('MONITORING_DB_PASSWORD')),
    'dbname' => insight_public_env('INSIGHT_DB_NAME', insight_public_env('MONITORING_DB_NAME', 'insight')),
    'app_name' => insight_public_env('INSIGHT_APP_NAME', 'Insight'),
    'public_url' => insight_public_env('INSIGHT_PUBLIC_URL', ''),
    'contact_email' => insight_public_env('INSIGHT_CONTACT_EMAIL', 'contact@example.com'),
    'timezone' => insight_public_env('INSIGHT_TIMEZONE', 'Europe/Paris'),
    'allowed_origins' => insight_public_env('INSIGHT_ALLOWED_ORIGINS', insight_public_env('INSIGHT_PUBLIC_URL', '')),
];
