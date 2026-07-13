<?php

function alert_updates_to_bool($raw, $default = false) {
    if (is_bool($raw)) {
        return $raw;
    }
    if (is_int($raw)) {
        return $raw === 1;
    }
    $value = strtolower(trim((string)$raw));
    if ($value === '') {
        return (bool)$default;
    }
    if (in_array($value, ['1', 'true', 'yes', 'on'], true)) {
        return true;
    }
    if (in_array($value, ['0', 'false', 'no', 'off'], true)) {
        return false;
    }
    return (bool)$default;
}

function alert_sms_is_disabled() {
    $checks = [
        getenv('INSIGHT_DISABLE_NOTIFICATIONS'),
        getenv('INSIGHT_SMS_DISABLED'),
        getenv('MONITORING_SMS_DISABLED'),
        getenv('SMS_DISABLED'),
    ];

    foreach ($checks as $check) {
        if ($check !== false && alert_updates_to_bool($check, false)) {
            return true;
        }
    }
    return false;
}

function alert_updates_build_targets($conn, $fallbackSmsUser, $fallbackSmsPassword) {
    $targets = [
        'sms' => [],
        'emails' => [],
    ];

    $fallbackSmsUser = trim((string)$fallbackSmsUser);
    $fallbackSmsPassword = trim((string)$fallbackSmsPassword);
    $emailSeen = [];
    $notificationEmails = trim((string)getenv('INSIGHT_NOTIFICATION_EMAILS'));
    if ($notificationEmails !== '') {
        foreach (preg_split('/[,;]+/', $notificationEmails) ?: [] as $rawEmail) {
            $email = trim((string)$rawEmail);
            $emailKey = strtolower($email);
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) && !isset($emailSeen[$emailKey])) {
                $targets['emails'][] = [
                    'email' => $email,
                    'name' => 'Insight Team',
                ];
                $emailSeen[$emailKey] = true;
            }
        }
    }

    if ($fallbackSmsUser !== '' && $fallbackSmsPassword !== '') {
        $targets['sms'][] = [
            'user' => $fallbackSmsUser,
            'password' => $fallbackSmsPassword,
        ];
    }

    return $targets;
}

function alert_updates_email_service() {
    return null;
}

function alert_batch_send_grouped_email($targets, $subject, $message) {
    if (!is_array($targets) || empty($targets['emails']) || !is_array($targets['emails'])) {
        return;
    }

    $service = alert_updates_email_service();
    if ($service === null) {
        return;
    }

    $safeSubject = trim((string)$subject);
    if ($safeSubject === '') {
        $safeSubject = 'Updates monitoring';
    }

    $safeText = trim((string)preg_replace('/\s+/', ' ', str_replace(["\r", "\n", "\t"], ' ', (string)$message)));
    if ($safeText === '') {
        return;
    }
    if (function_exists('mb_strlen')) {
        if (mb_strlen($safeText, 'UTF-8') > 900) {
            $safeText = mb_substr($safeText, 0, 897, 'UTF-8') . '...';
        }
    } elseif (strlen($safeText) > 900) {
        $safeText = substr($safeText, 0, 897) . '...';
    }

    foreach ($targets['emails'] as $recipient) {
        if (!is_array($recipient)) {
            continue;
        }
        $email = trim((string)($recipient['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            continue;
        }
        $name = trim((string)($recipient['name'] ?? 'Team member'));
        if ($name === '') {
            $name = 'Team member';
        }
        $htmlBody = function_exists('auth_get_email_template')
            ? auth_get_email_template(
                $safeSubject,
                $name,
                '<p>' . htmlspecialchars($safeText, ENT_QUOTES, 'UTF-8') . '</p>'
            )
            : '<p>' . htmlspecialchars($safeText, ENT_QUOTES, 'UTF-8') . '</p>';
        $send = $service->sendEmail($email, $name, $safeSubject, $htmlBody, $safeText);
        if ($send !== true) {
            error_log('Grouped email notification failed for ' . $email . ': ' . (string)$send);
        }
    }
}

function alert_batch_dispatch_modern($event, $context) {
    if (!function_exists('proc_open')) {
        return ['ok' => false, 'configured' => 0, 'error' => 'proc_open indisponible'];
    }
    $monitoringRoot = dirname(__DIR__);
    require_once $monitoringRoot . '/python_bridge.php';
    $python = function_exists('resolve_python_bin') ? resolve_python_bin() : 'python3';
    $script = $monitoringRoot . '/python_monitoring/notification_cli.py';
    if (!is_file($script)) {
        return ['ok' => false, 'configured' => 0, 'error' => 'Notification engine not found'];
    }
    $command = [$python, $script, 'dispatch', '--root', $monitoringRoot];
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = @proc_open($command, $descriptors, $pipes, $monitoringRoot);
    if (!is_resource($process)) {
        return ['ok' => false, 'configured' => 0, 'error' => 'Notification engine unavailable'];
    }
    $payload = json_encode(
        ['event' => (string)$event, 'context' => is_array($context) ? $context : []],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    fwrite($pipes[0], is_string($payload) ? $payload : '{}');
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $stdout = '';
    $stderr = '';
    $deadline = microtime(true) + 35;
    $timedOut = false;
    while (true) {
        $stdout .= (string)stream_get_contents($pipes[1]);
        $stderr .= (string)stream_get_contents($pipes[2]);
        $status = proc_get_status($process);
        if (!($status['running'] ?? false)) {
            break;
        }
        if (microtime(true) >= $deadline) {
            $timedOut = true;
            proc_terminate($process);
            usleep(100000);
            $status = proc_get_status($process);
            if ($status['running'] ?? false) {
                proc_terminate($process, 9);
            }
            break;
        }
        usleep(20000);
    }
    $stdout .= (string)stream_get_contents($pipes[1]);
    $stderr .= (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    if ($timedOut) {
        return ['ok' => false, 'configured' => 0, 'error' => 'Notification timed out'];
    }
    $lines = preg_split('/\R+/', trim((string)$stdout)) ?: [];
    foreach (array_reverse($lines) as $line) {
        $decoded = json_decode(trim((string)$line), true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    $error = trim((string)$stderr);
    $error = function_exists('mb_substr') ? mb_substr($error, 0, 255, 'UTF-8') : substr($error, 0, 255);
    return ['ok' => false, 'configured' => 0, 'error' => $error];
}

function alert_batch_dispatch_grouped_updates($targets, $event, $subject, $message, $context) {
    $modern = alert_batch_dispatch_modern((string)$event, is_array($context) ? $context : []);
    if ((int)($modern['configured'] ?? 0) > 0) {
        if ((int)($modern['failed'] ?? 0) > 0) {
            error_log((int)$modern['failed'] . ' channel(s) failed for ' . (string)$event);
        }
        return;
    }
    if (is_array($targets) && !empty($targets['sms']) && is_array($targets['sms'])) {
        $seen = [];
        foreach ($targets['sms'] as $smsTarget) {
            if (!is_array($smsTarget)) {
                continue;
            }
            $user = trim((string)($smsTarget['user'] ?? ''));
            $password = trim((string)($smsTarget['password'] ?? ''));
            if ($user === '' || $password === '') {
                continue;
            }
            $key = $user . '|' . $password;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            alert_batch_send_grouped_sms($user, $password, (string)$message);
        }
    }

    alert_batch_send_grouped_email($targets, (string)$subject, (string)$message);
}

/**
 * Send SMS through the Free Mobile API
 *
 * @param string $user Free Mobile identifier
 * @param string $password Free Mobile password
 * @param string $message Message to send
 * @return bool True when delivery succeeds, false otherwise
 */
function send_sms($user, $password, $message) {
    if (alert_sms_is_disabled()) {
        return false;
    }

    $sms_url = "https://smsapi.free-mobile.fr/sendmsg?user=" . urlencode($user) . "&pass=" . urlencode($password) . "&msg=" . urlencode($message);
    $response = @file_get_contents($sms_url);

    // Validate the HTTP response code
    if (isset($http_response_header[0]) && strpos($http_response_header[0], "200") !== false) {
        return true;
    } else {
        return false;
    }
}

function sanitize_postmortem_text($raw) {
    $text = trim(str_replace(["\r", "\n", "\t"], ' ', (string)$raw));
    $text = preg_replace('/\s+/', ' ', $text);
    $text = trim((string)$text);
    if ($text === '') {
        return '';
    }

    $lower = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
    if (strpos($lower, 'content-type:') !== false || strpos($lower, 'http error:') !== false || strpos($lower, 'curl error:') !== false || strpos($lower, 'debug:') !== false) {
        return '';
    }

    $text = trim($text, " \t\n\r\0\x0B\"'`");
    if ($text === '') {
        return '';
    }

    if (function_exists('mb_strlen')) {
        if (mb_strlen($text, 'UTF-8') < 20) {
            return '';
        }
        if (!preg_match('/[[:alpha:]]/u', $text)) {
            return '';
        }
        return mb_substr($text, 0, 280, 'UTF-8');
    }

    if (strlen($text) < 20) {
        return '';
    }
    if (!preg_match('/[A-Za-z]/', $text)) {
        return '';
    }
    return substr($text, 0, 280);
}

function send_incident_alert($user, $password, $site_url, $state, $http_code = null, $pmText = '', $timeout = false) {
    if ($state === 'open') {
        $message = "Incident detected. $site_url did not respond after more than 3 attempts.";
    } elseif ($state === 'close') {
        if ($timeout) {
            $message = "Incident resolved. $site_url is available again. See the public Insight page for details.";
        } else {
            $safePm = sanitize_postmortem_text($pmText);
            if ($safePm === '') {
                $message = "Incident resolved. $site_url is available again. See the public Insight page for details.";
            } else {
                $message = "Incident resolved. $site_url is available again. Summary: $safePm";
            }
        }
    } else {
        return false;
    }
    return send_sms($user, $password, $message);
}

function alert_group_extract_host($site_url) {
    $raw = trim((string)$site_url);
    if ($raw === '') {
        return '';
    }

    $host = parse_url($raw, PHP_URL_HOST);
    if (is_string($host) && $host !== '') {
        return strtolower($host);
    }

    $host = parse_url('https://' . ltrim($raw, '/'), PHP_URL_HOST);
    if (is_string($host) && $host !== '') {
        return strtolower($host);
    }

    return strtolower($raw);
}

function alert_group_root_domain($host) {
    $value = trim(strtolower((string)$host), ". \t\n\r\0\x0B");
    if (strpos($value, 'www.') === 0) {
        $value = substr($value, 4);
    }
    if ($value === '') {
        return '';
    }

    $parts = array_values(array_filter(explode('.', $value), static function ($p) {
        return $p !== '';
    }));
    $count = count($parts);
    if ($count < 2) {
        return $value;
    }

    if ($count >= 3) {
        $last = $parts[$count - 1];
        $beforeLast = $parts[$count - 2];
        if (strlen($last) === 2 && in_array($beforeLast, ['co', 'com', 'net', 'org', 'gov', 'edu', 'ac'], true)) {
            return implode('.', array_slice($parts, -3));
        }
    }

    return implode('.', array_slice($parts, -2));
}

function alert_group_domain_from_url($site_url) {
    $host = alert_group_extract_host($site_url);
    $root = alert_group_root_domain($host);
    return $root !== '' ? $root : ($host !== '' ? $host : (string)$site_url);
}

function alert_group_format_sites_for_sms($sites, $maxHosts = 2) {
    if (!is_array($sites) || empty($sites)) {
        return 'unknown site';
    }

    $hosts = [];
    foreach ($sites as $siteUrl => $_) {
        $label = alert_group_extract_host((string)$siteUrl);
        if ($label === '') {
            $label = (string)$siteUrl;
        }
        if ($label !== '') {
            $hosts[$label] = true;
        }
    }
    $labels = array_keys($hosts);
    sort($labels, SORT_STRING);

    if (count($labels) <= $maxHosts) {
        return implode(', ', $labels);
    }

    $shown = array_slice($labels, 0, $maxHosts);
    return implode(', ', $shown) . ' +' . (count($labels) - $maxHosts);
}

function alert_batch_push_site(&$bucket, $site_url) {
    if (!is_array($bucket)) {
        $bucket = [];
    }
    $url = trim((string)$site_url);
    if ($url !== '') {
        $bucket[$url] = true;
    }
}

function alert_batch_queue_incident(&$batch, $site_url, $state, $pmText = '', $timeout = false) {
    if (!is_array($batch)) {
        $batch = [];
    }
    if (!isset($batch['incident_open']) || !is_array($batch['incident_open'])) {
        $batch['incident_open'] = [];
    }
    if (!isset($batch['incident_close']) || !is_array($batch['incident_close'])) {
        $batch['incident_close'] = [];
    }

    $domain = alert_group_domain_from_url($site_url);
    if ($state === 'open') {
        if (!isset($batch['incident_open'][$domain]) || !is_array($batch['incident_open'][$domain])) {
            $batch['incident_open'][$domain] = [];
        }
        alert_batch_push_site($batch['incident_open'][$domain], $site_url);
        return;
    }

    if ($state === 'close') {
        $safePm = sanitize_postmortem_text((string)$pmText);
        $timedOut = (bool)$timeout || $safePm === '';
        $pmKey = $timedOut ? '__no_pm__' : strtolower($safePm);
        $key = $domain . '|' . md5($pmKey);
        if (!isset($batch['incident_close'][$key]) || !is_array($batch['incident_close'][$key])) {
            $batch['incident_close'][$key] = [
                'domain' => $domain,
                'pm_text' => $timedOut ? '' : $safePm,
                'timeout' => $timedOut,
                'sites' => [],
            ];
        }
        alert_batch_push_site($batch['incident_close'][$key]['sites'], $site_url);
    }
}

function alert_batch_queue_status(&$batch, $site_url, $state) {
    if (!is_array($batch)) {
        $batch = [];
    }
    if (!isset($batch['status_offline']) || !is_array($batch['status_offline'])) {
        $batch['status_offline'] = [];
    }
    if (!isset($batch['status_online']) || !is_array($batch['status_online'])) {
        $batch['status_online'] = [];
    }

    $domain = alert_group_domain_from_url($site_url);
    if ($state === 'offline') {
        if (!isset($batch['status_offline'][$domain]) || !is_array($batch['status_offline'][$domain])) {
            $batch['status_offline'][$domain] = [];
        }
        alert_batch_push_site($batch['status_offline'][$domain], $site_url);
        return;
    }

    if ($state === 'online') {
        if (!isset($batch['status_online'][$domain]) || !is_array($batch['status_online'][$domain])) {
            $batch['status_online'][$domain] = [];
        }
        alert_batch_push_site($batch['status_online'][$domain], $site_url);
    }
}

function alert_batch_send_grouped_sms($user, $password, $message) {
    $msg = trim((string)preg_replace('/\s+/', ' ', str_replace(["\r", "\n", "\t"], ' ', (string)$message)));
    if ($msg === '') {
        return;
    }
    if (function_exists('mb_strlen')) {
        if (mb_strlen($msg, 'UTF-8') > 480) {
            $msg = mb_substr($msg, 0, 477, 'UTF-8') . '...';
        }
    } elseif (strlen($msg) > 480) {
        $msg = substr($msg, 0, 477) . '...';
    }
    if (!send_sms($user, $password, $msg)) {
        error_log('Grouped SMS notification failed: ' . $msg);
    }
}

function alert_batch_flush(&$batch, $user, $password, $conn = null) {
    if (!is_array($batch) || empty($batch)) {
        return;
    }
    $targets = alert_updates_build_targets($conn, (string)$user, (string)$password);
    $appName = trim((string)getenv('INSIGHT_APP_NAME')) ?: 'Insight';
    $publicUrl = trim((string)getenv('INSIGHT_PUBLIC_URL'));

    $incidentOpen = isset($batch['incident_open']) && is_array($batch['incident_open']) ? $batch['incident_open'] : [];
    ksort($incidentOpen, SORT_STRING);
    foreach ($incidentOpen as $domain => $sites) {
        $count = is_array($sites) ? count($sites) : 0;
        if ($count <= 0) {
            continue;
        }
        $message = "Incident opened ($domain): $count unavailable site" . ($count > 1 ? 's' : '') .
            " (" . alert_group_format_sites_for_sms($sites) . ").";
        $siteValues = array_values(array_map('strval', $sites));
        alert_batch_dispatch_grouped_updates($targets, 'incident_open', "Incident opened - $domain", $message, [
            'app_name' => $appName,
            'public_url' => $publicUrl,
            'domain' => (string)$domain,
            'sites' => implode(', ', $siteValues),
            'site_url' => (string)($siteValues[0] ?? ''),
            'count' => $count,
            'status' => 'offline',
            'message' => 'Detection confirmed by the monitoring engine.',
        ]);
    }

    $incidentClose = isset($batch['incident_close']) && is_array($batch['incident_close']) ? $batch['incident_close'] : [];
    ksort($incidentClose, SORT_STRING);
    foreach ($incidentClose as $payload) {
        if (!is_array($payload)) {
            continue;
        }
        $domain = (string)($payload['domain'] ?? 'group');
        $sites = is_array($payload['sites'] ?? null) ? $payload['sites'] : [];
        $count = count($sites);
        if ($count <= 0) {
            continue;
        }
        $base = "Incident resolved ($domain): $count restored site" . ($count > 1 ? 's' : '') .
            " (" . alert_group_format_sites_for_sms($sites) . "). ";
        if (!empty($payload['timeout'])) {
            $message = $base . "Report unavailable; see the public Insight page.";
        } else {
            $pm = (string)($payload['pm_text'] ?? '');
            $message = $base . "Probable cause: " . $pm;
        }
        $siteValues = array_values(array_map('strval', $sites));
        $resolutionMessage = !empty($payload['timeout'])
            ? 'The service is responding again, but the resolution report is unavailable.'
            : 'Probable cause: ' . (string)($payload['pm_text'] ?? '');
        alert_batch_dispatch_grouped_updates($targets, 'incident_resolved', "Incident resolved - $domain", $message, [
            'app_name' => $appName,
            'public_url' => $publicUrl,
            'domain' => $domain,
            'sites' => implode(', ', $siteValues),
            'site_url' => (string)($siteValues[0] ?? ''),
            'count' => $count,
            'status' => 'online',
            'message' => $resolutionMessage,
        ]);
    }

    $statusOffline = isset($batch['status_offline']) && is_array($batch['status_offline']) ? $batch['status_offline'] : [];
    ksort($statusOffline, SORT_STRING);
    foreach ($statusOffline as $domain => $sites) {
        $count = is_array($sites) ? count($sites) : 0;
        if ($count <= 0) {
            continue;
        }
        $message = "Alert ($domain): $count offline site" . ($count > 1 ? 's' : '') .
            " (" . alert_group_format_sites_for_sms($sites) . ").";
        $siteValues = array_values(array_map('strval', $sites));
        alert_batch_dispatch_grouped_updates($targets, 'monitor_down', "Offline alert - $domain", $message, [
            'app_name' => $appName,
            'public_url' => $publicUrl,
            'domain' => (string)$domain,
            'sites' => implode(', ', $siteValues),
            'site_url' => (string)($siteValues[0] ?? ''),
            'count' => $count,
            'status' => 'offline',
            'message' => 'The latest check received no valid response.',
        ]);
    }

    $statusOnline = isset($batch['status_online']) && is_array($batch['status_online']) ? $batch['status_online'] : [];
    ksort($statusOnline, SORT_STRING);
    foreach ($statusOnline as $domain => $sites) {
        $count = is_array($sites) ? count($sites) : 0;
        if ($count <= 0) {
            continue;
        }
        $message = "Alert ($domain): $count site" . ($count > 1 ? 's are' : ' is') .
            " back online (" . alert_group_format_sites_for_sms($sites) . ").";
        $siteValues = array_values(array_map('strval', $sites));
        alert_batch_dispatch_grouped_updates($targets, 'monitor_up', "Service restored - $domain", $message, [
            'app_name' => $appName,
            'public_url' => $publicUrl,
            'domain' => (string)$domain,
            'sites' => implode(', ', $siteValues),
            'site_url' => (string)($siteValues[0] ?? ''),
            'count' => $count,
            'status' => 'online',
            'message' => 'The recovery check succeeded.',
        ]);
    }
}

/**
 * Main function for handling alerts for one site
 *
 * @param mysqli $conn MySQLi connection
 * @param string $user Free Mobile identifier
 * @param string $password Free Mobile password
 * @param int $site_id Site identifier
 * @param string $site_url Site URL
 * @param string $status Current site status ("online" or "offline")
 */
function alertSite($conn, $user, $password, $site_id, $site_url, $status, $suppressRecoveryAlert = false, &$notificationBatch = null) {
    // Retrieve the previous site state
    $stmt = $conn->prepare("SELECT status, alert_sent FROM alert WHERE id = ?");
    $stmt->bind_param("i", $site_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $previous_data = $res->fetch_assoc();
    $stmt->close();

    $alert_sent_previously = false;
    $previous_status = null;

    if ($previous_data) {
        $previous_status = $previous_data['status'];
        $alert_sent_previously = (bool)$previous_data['alert_sent'];
    }

    $alert_sent = $alert_sent_previously; // Preserve the current value by default

    $stmt_recent = $conn->prepare("SELECT status FROM probes WHERE site_id = ? ORDER BY checked_at DESC LIMIT 2");
    $stmt_recent->bind_param("i", $site_id);
    $stmt_recent->execute();
    $recent = $stmt_recent->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_recent->close();

    $consecutive_offline = count($recent) === 2;
    if ($consecutive_offline) {
        foreach ($recent as $row) {
            if ($row['status'] !== 'offline') {
                $consecutive_offline = false;
                break;
            }
        }
    }

    if ($status !== 'online') {
        if ($consecutive_offline && !$alert_sent_previously) {
            if (is_array($notificationBatch)) {
                alert_batch_queue_status($notificationBatch, $site_url, 'offline');
            } else {
                $alert_message = "Alert: $site_url is offline";
                if (send_sms($user, $password, $alert_message)) {
                    error_log("Alert sent for: $site_url");
                } else {
                    error_log("Failed to send SMS alert for $site_url");
                }
            }
            $alert_sent = true;
        }
    } else {
        if ($alert_sent_previously) {
            if (!$suppressRecoveryAlert) {
                if (is_array($notificationBatch)) {
                    alert_batch_queue_status($notificationBatch, $site_url, 'online');
                } else {
                    $back_online_message = "Alert: $site_url is back online";
                    if (send_sms($user, $password, $back_online_message)) {
                        error_log("$site_url is back online; message sent.");
                    } else {
                        error_log("Failed to send recovery SMS for $site_url");
                    }
                }
            }
            $alert_sent = false;
        }
    }

    $timestamp = date('Y-m-d H:i:s');

    // Update or insert the state in the database
    if ($previous_data) {
        // Update
        $stmt = $conn->prepare("UPDATE alert SET site_url = ?, status = ?, alert_sent = ?, timestamp = ? WHERE id = ?");
        $alert_sent_int = $alert_sent ? 1 : 0;
        $stmt->bind_param("ssisi", $site_url, $status, $alert_sent_int, $timestamp, $site_id);
    } else {
        // Insert
        $stmt = $conn->prepare("INSERT INTO alert (id, site_url, status, alert_sent, timestamp) VALUES (?, ?, ?, ?, ?)");
        $alert_sent_int = $alert_sent ? 1 : 0;
        $stmt->bind_param("issis", $site_id, $site_url, $status, $alert_sent_int, $timestamp);
    }

    if (!$stmt->execute()) {
        error_log("Failed to update the alert table: " . $stmt->error);
    }
    $stmt->close();
}
?>
