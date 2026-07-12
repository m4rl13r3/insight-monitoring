<?php

declare(strict_types=1);

putenv('INSIGHT_APP_ENV=development');
putenv('INSIGHT_DEV_AUTH_BYPASS=1');
$testDirectory = sys_get_temp_dir() . '/insight-probes-' . bin2hex(random_bytes(6));
putenv('INSIGHT_AUTH_DB_PATH=' . $testDirectory . '/auth.sqlite');

require dirname(__DIR__) . '/public/admin/_probes.php';

$cases = [
    [['probe_type' => 'http', 'target' => 'example.net', 'interval_sec' => 60], true, 'https://example.net'],
    [['probe_type' => 'icmp', 'target' => '192.0.2.1', 'interval_sec' => 120], true, '192.0.2.1'],
    [['probe_type' => 'tcp', 'target' => 'server.example.net:443', 'interval_sec' => 300], true, 'server.example.net:443'],
    [['probe_type' => 'tcp', 'target' => '[::1]:443', 'interval_sec' => 60], true, '[::1]:443'],
    [['probe_type' => 'tcp', 'target' => 'server.example.net', 'interval_sec' => 60], false, null],
    [['probe_type' => 'icmp', 'target' => 'https://example.net', 'interval_sec' => 60], false, null],
];

foreach ($cases as [$input, $expectedOk, $expectedTarget]) {
    $result = insight_probes_validate($input);
    if (($result['ok'] ?? false) !== $expectedOk) {
        fwrite(STDERR, 'Validation inattendue pour ' . json_encode($input) . PHP_EOL);
        exit(1);
    }
    if ($expectedOk && ($result['target'] ?? null) !== $expectedTarget) {
        fwrite(STDERR, 'Normalisation inattendue pour ' . json_encode($input) . PHP_EOL);
        exit(1);
    }
}

$created = insight_probes_create_preview([
    'probe_type' => 'http',
    'target' => 'https://create.example.net',
    'interval_sec' => 60,
]);
if (!($created['ok'] ?? false)) {
    fwrite(STDERR, "Création locale impossible.\n");
    exit(1);
}
$probeId = (int)($created['probe']['id'] ?? 0);
$updated = insight_probes_update_preview($probeId, [
    'probe_type' => 'tcp',
    'target' => 'server.example.net:443',
    'interval_sec' => 120,
]);
if (!($updated['ok'] ?? false) || ($updated['probe']['probe_type'] ?? '') !== 'tcp') {
    fwrite(STDERR, "Modification locale impossible.\n");
    exit(1);
}
$deleted = insight_probes_delete_preview($probeId);
if (!($deleted['ok'] ?? false) || insight_probes_preview_rows() !== []) {
    fwrite(STDERR, "Suppression locale impossible.\n");
    exit(1);
}
@unlink(insight_probes_preview_path());
@rmdir($testDirectory . '/sessions');
@rmdir($testDirectory);

echo "Validation des sondes réussie.\n";
