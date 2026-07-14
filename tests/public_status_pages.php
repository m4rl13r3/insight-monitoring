<?php

declare(strict_types=1);

$_SERVER['SCRIPT_FILENAME'] = __FILE__;
putenv('INSIGHT_STATUS_PAGE_COOKIE_SECRET=' . str_repeat('a', 64));

require dirname(__DIR__) . '/public/_status_page.php';

$page = [
    'id' => 42,
    'visibility' => 'private',
    'access_policy' => 'password',
    'password_hash' => password_hash('correct horse battery staple', PASSWORD_DEFAULT),
    'updated_at' => '2026-07-14 12:00:00',
];
$expiresAt = time() + 3600;
$cookieName = insight_status_page_cookie_name(42);
$_COOKIE[$cookieName] = insight_status_page_cookie_value(42, insight_status_page_access_fingerprint($page), $expiresAt);

$expectations = [
    insight_status_page_authorized($page),
    !insight_status_page_authorized([...$page, 'password_hash' => password_hash('different password', PASSWORD_DEFAULT)]),
    insight_status_page_authorized(['id' => 43, 'visibility' => 'public']),
    insight_status_page_ip_matches('192.0.2.42', '192.0.2.0/24'),
    !insight_status_page_ip_matches('198.51.100.42', '192.0.2.0/24'),
    insight_status_page_ip_matches('2001:db8::42', '2001:db8::/32'),
];

$_SERVER['REMOTE_ADDR'] = '192.0.2.42';
$expectations[] = insight_status_page_authorized(['id' => 44, 'access_policy' => 'ip_allowlist', 'ip_allowlist' => "192.0.2.0/24\n2001:db8::/32"]);
$_SERVER['REMOTE_ADDR'] = '198.51.100.42';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '192.0.2.42';
$expectations[] = !insight_status_page_authorized(['id' => 44, 'access_policy' => 'ip_allowlist', 'ip_allowlist' => '192.0.2.0/24']);

$_COOKIE[$cookieName] = ($expiresAt - 7200) . '.invalid';
$expectations[] = !insight_status_page_authorized($page);

if (in_array(false, $expectations, true)) {
    fwrite(STDERR, "Private status page validation failed.\n");
    exit(1);
}

echo "Private status pages validated.\n";
