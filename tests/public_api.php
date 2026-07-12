<?php

declare(strict_types=1);

$_SERVER['SCRIPT_FILENAME'] = __FILE__;

require dirname(__DIR__) . '/public/api/hourly_report/helpers.php';

$allowedOrigins = ['https://status.example.com', 'http://localhost:8080'];
$expectations = [
    hourly_normalize_origin('HTTPS://Status.Example.com/') === 'https://status.example.com',
    hourly_normalize_origin('https://status.example.com:443') === 'https://status.example.com',
    hourly_normalize_origin('https://status.example.com/path') === 'https://status.example.com',
    hourly_is_allowed_origin('https://status.example.com', $allowedOrigins),
    hourly_is_allowed_origin('http://localhost:8080', $allowedOrigins),
    !hourly_is_allowed_origin('https://evil-status.example.com', $allowedOrigins),
    !hourly_is_allowed_origin('https://status.example.com.evil.test', $allowedOrigins),
    !hourly_is_allowed_origin('javascript:alert(1)', $allowedOrigins),
];

if (in_array(false, $expectations, true)) {
    fwrite(STDERR, "Validation de l’API publique échouée.\n");
    exit(1);
}

echo "API publique validée.\n";
