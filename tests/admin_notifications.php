<?php

declare(strict_types=1);

putenv('INSIGHT_APP_ENV=development');
putenv('INSIGHT_DEV_AUTH_BYPASS=1');
putenv('INSIGHT_NOTIFICATION_ENCRYPTION_KEY=abcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456789');
$testDirectory = sys_get_temp_dir() . '/insight-notifications-' . bin2hex(random_bytes(6));
putenv('INSIGHT_AUTH_DB_PATH=' . $testDirectory . '/auth.sqlite');

require dirname(__DIR__) . '/public/admin/_notifications.php';

$ciphertext = insight_notifications_encrypt(['token' => 'très-secret']);
if (str_contains($ciphertext, 'très-secret') || insight_notifications_decrypt($ciphertext)['token'] !== 'très-secret') {
    fwrite(STDERR, "Le chiffrement des notifications est invalide.\n");
    exit(1);
}

$validated = insight_notifications_validate([
    'name' => 'Webhook de test',
    'provider' => 'webhook',
    'enabled' => true,
    'events' => ['monitor_down', 'monitor_up'],
    'config' => [
        'url' => 'https://hooks.example.test/secret',
        'method' => 'POST',
        'headers' => '{"Authorization":"Bearer secret"}',
        'payload_template' => '{"title":"{{ title }}","message":"{{ body }}"}',
    ],
]);
if (!($validated['ok'] ?? false)) {
    fwrite(STDERR, "La validation du webhook a échoué.\n");
    exit(1);
}
if (!(insight_notifications_validate_webhook_payload($insightAdminConfig, $validated)['ok'] ?? false)) {
    fwrite(STDERR, "Le modèle JSON du webhook a été refusé.\n");
    exit(1);
}
$invalidPayload = $validated;
$invalidPayload['config']['payload_template'] = '{"message":{{ body }}';
if (insight_notifications_validate_webhook_payload($insightAdminConfig, $invalidPayload)['ok'] ?? false) {
    fwrite(STDERR, "Un modèle JSON invalide a été accepté.\n");
    exit(1);
}

$created = insight_notifications_create_preview($validated);
$channelId = (int)($created['id'] ?? 0);
$state = insight_notifications_preview_state();
$channel = $state['channels'][0] ?? [];
if ($channelId < 900000 || ($channel['config']['has_url'] ?? false) !== true) {
    fwrite(STDERR, "La création locale du canal a échoué.\n");
    exit(1);
}
$serialized = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if (!is_string($serialized) || str_contains($serialized, 'https://hooks.example.test/secret') || str_contains($serialized, 'Bearer secret')) {
    fwrite(STDERR, "Un secret est exposé par l’état public.\n");
    exit(1);
}

$stored = insight_notifications_find_preview($channelId);
$previous = insight_notifications_decrypt((string)$stored['config_ciphertext']);
$updated = insight_notifications_validate([
    'name' => 'Webhook modifié',
    'provider' => 'webhook',
    'enabled' => false,
    'events' => ['incident_open'],
    'config' => ['url' => '', 'method' => 'PATCH', 'headers' => '', 'payload_template' => ''],
], $previous, 'webhook');
if (!($updated['ok'] ?? false) || ($updated['config']['url'] ?? '') !== 'https://hooks.example.test/secret') {
    fwrite(STDERR, "La conservation des secrets a échoué.\n");
    exit(1);
}
if (!(insight_notifications_update_preview($channelId, $updated)['ok'] ?? false)) {
    fwrite(STDERR, "La modification locale du canal a échoué.\n");
    exit(1);
}
if (!(insight_notifications_delete_preview($channelId)['ok'] ?? false)) {
    fwrite(STDERR, "La suppression locale du canal a échoué.\n");
    exit(1);
}

@unlink(insight_notifications_preview_path());
@rmdir($testDirectory . '/sessions');
@rmdir($testDirectory);

echo "Validation des notifications réussie.\n";
