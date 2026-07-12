<?php

declare(strict_types=1);

$publicRoot = dirname(__DIR__) . '/public';
$requestPath = rawurldecode((string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/'));
$target = realpath($publicRoot . $requestPath);
$isPublicTarget = $target !== false
    && ($target === $publicRoot || str_starts_with($target, $publicRoot . DIRECTORY_SEPARATOR));

if ($isPublicTarget && (is_file($target) || is_dir($target))) {
    return false;
}

if (rtrim($requestPath, '/') === '/metrics') {
    require $publicRoot . '/metrics.php';
    return true;
}

if ($requestPath === '/.well-known/openid-configuration') {
    require $publicRoot . '/api/oauth/openid-configuration.php';
    return true;
}

require $publicRoot . '/index.php';
return true;
