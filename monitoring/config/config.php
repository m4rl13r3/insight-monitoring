<?php

declare(strict_types=1);

if (!function_exists('insight_monitoring_load_env')) {
    function insight_monitoring_load_env(string $root): void
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

if (!function_exists('insight_monitoring_env')) {
    function insight_monitoring_env(string $name, string $default = ''): string
    {
        $value = getenv($name);
        if ($value === false || trim((string)$value) === '') {
            return $default;
        }
        return trim((string)$value);
    }
}

$root = dirname(__DIR__, 2);
insight_monitoring_load_env($root);

return [
    'app_name' => insight_monitoring_env('INSIGHT_APP_NAME', 'Insight'),
    'public_url' => insight_monitoring_env('INSIGHT_PUBLIC_URL', ''),
    'contact_email' => insight_monitoring_env('INSIGHT_CONTACT_EMAIL', 'contact@example.com'),
    'timezone' => insight_monitoring_env('INSIGHT_TIMEZONE', 'Europe/Paris'),
    'allowed_origins' => insight_monitoring_env('INSIGHT_ALLOWED_ORIGINS', insight_monitoring_env('INSIGHT_PUBLIC_URL', '')),
    'servername' => insight_monitoring_env('INSIGHT_DB_HOST', insight_monitoring_env('MONITORING_DB_HOST', 'db')),
    'port' => insight_monitoring_env('INSIGHT_DB_PORT', insight_monitoring_env('MONITORING_DB_PORT', '3306')),
    'username' => insight_monitoring_env('INSIGHT_DB_USER', insight_monitoring_env('MONITORING_DB_USER', 'insight')),
    'password' => insight_monitoring_env('INSIGHT_DB_PASSWORD', insight_monitoring_env('MONITORING_DB_PASSWORD')),
    'dbname' => insight_monitoring_env('INSIGHT_DB_NAME', insight_monitoring_env('MONITORING_DB_NAME', 'insight')),
    'db_socket' => insight_monitoring_env('INSIGHT_DB_SOCKET', insight_monitoring_env('MONITORING_DB_SOCKET', '')),
    'sms_user' => insight_monitoring_env('INSIGHT_SMS_USER', insight_monitoring_env('MONITORING_SMS_USER', '')),
    'sms_password' => insight_monitoring_env('INSIGHT_SMS_PASSWORD', insight_monitoring_env('MONITORING_SMS_PASSWORD', '')),
    'redis_socket' => insight_monitoring_env('INSIGHT_REDIS_SOCKET', ''),
    'redis_auth' => insight_monitoring_env('INSIGHT_REDIS_AUTH', ''),
    'monitoring_escalation_max_age_minutes' => insight_monitoring_env('INSIGHT_ESCALATION_MAX_AGE_MINUTES', '360'),
    'monitoring_escalation_max_notifications_per_run' => insight_monitoring_env('INSIGHT_ESCALATION_MAX_NOTIFICATIONS_PER_RUN', '20'),
    'http_interval_sec' => insight_monitoring_env('INSIGHT_MONITOR_INTERVAL_SEC', insight_monitoring_env('MONITORING_HTTP_INTERVAL_SEC', '60')),
    'disable_notifications' => insight_monitoring_env('INSIGHT_DISABLE_NOTIFICATIONS', '1'),
    'email_smtp_host' => insight_monitoring_env('INSIGHT_EMAIL_SMTP_HOST', ''),
    'email_smtp_port' => insight_monitoring_env('INSIGHT_EMAIL_SMTP_PORT', '465'),
    'email_smtp_username' => insight_monitoring_env('INSIGHT_EMAIL_SMTP_USERNAME', ''),
    'email_smtp_password' => insight_monitoring_env('INSIGHT_EMAIL_SMTP_PASSWORD', ''),
    'email_smtp_encryption' => insight_monitoring_env('INSIGHT_EMAIL_SMTP_ENCRYPTION', 'ssl'),
    'email_from_name' => insight_monitoring_env('INSIGHT_EMAIL_FROM_NAME', insight_monitoring_env('INSIGHT_APP_NAME', 'Insight')),
    'notification_emails' => insight_monitoring_env('INSIGHT_NOTIFICATION_EMAILS', ''),
];
