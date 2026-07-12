<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$scriptPath = isset($argv[1]) ? trim((string)$argv[1]) : '';
$queryBlob = isset($argv[2]) ? trim((string)$argv[2]) : '';

if ($scriptPath === '' || !is_file($scriptPath)) {
    fwrite(STDERR, "Script introuvable.\n");
    exit(1);
}

$query = [];
if ($queryBlob !== '') {
    $decodedBlob = base64_decode($queryBlob, true);
    if (is_string($decodedBlob) && $decodedBlob !== '') {
        $parsed = json_decode($decodedBlob, true);
        if (is_array($parsed)) {
            $query = $parsed;
        }
    }
}

$_GET = $query;
$_POST = [];
$_REQUEST = $query;
$_SERVER['REQUEST_METHOD'] = 'GET';

include $scriptPath;
