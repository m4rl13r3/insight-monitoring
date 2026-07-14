<?php

declare(strict_types=1);

putenv('INSIGHT_APP_ENV=development');
putenv('INSIGHT_DEV_AUTH_BYPASS=1');
$testDirectory = sys_get_temp_dir() . '/insight-incidents-' . bin2hex(random_bytes(6));
putenv('INSIGHT_AUTH_DB_PATH=' . $testDirectory . '/auth.sqlite');

require dirname(__DIR__) . '/public/admin/_incidents.php';

$validated = insight_incidents_validate_postmortem([
    'postmortem' => "Service restored.\r\nFollow-up scheduled.",
]);
if (!($validated['ok'] ?? false) || ($validated['postmortem'] ?? '') !== "Service restored.\nFollow-up scheduled.") {
    fwrite(STDERR, "Postmortem validation failed.\n");
    exit(1);
}

$tooLong = insight_incidents_validate_postmortem(['postmortem' => str_repeat('a', 20001)]);
if ($tooLong['ok'] ?? false) {
    fwrite(STDERR, "An oversized postmortem was accepted.\n");
    exit(1);
}

$updated = insight_incidents_update_preview(101, (string)$validated['postmortem']);
if (!($updated['ok'] ?? false) || ($updated['incident']['has_postmortem'] ?? false) !== true) {
    fwrite(STDERR, "Local postmortem update failed.\n");
    exit(1);
}

$applied = insight_incidents_apply_preview([
    ['id' => 101, 'postmortem' => 'Original report'],
]);
if (($applied[0]['postmortem'] ?? '') !== "Service restored.\nFollow-up scheduled.") {
    fwrite(STDERR, "Local postmortem persistence failed.\n");
    exit(1);
}

$cleared = insight_incidents_update_preview(101, '');
if (!($cleared['ok'] ?? false) || ($cleared['incident']['has_postmortem'] ?? true) !== false) {
    fwrite(STDERR, "Local postmortem clearing failed.\n");
    exit(1);
}

if (insight_incidents_update_preview(999, 'Missing')['ok'] ?? false) {
    fwrite(STDERR, "An unknown preview incident was updated.\n");
    exit(1);
}

@unlink(insight_incidents_preview_path());
@rmdir($testDirectory . '/sessions');
@rmdir($testDirectory);

echo "Incident postmortem validation passed.\n";
