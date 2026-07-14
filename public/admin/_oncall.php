<?php

declare(strict_types=1);

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/_probes.php';

function insight_oncall_validate(array $input): array
{
    $name = trim((string)($input['name'] ?? ''));
    if ($name === '' || mb_strlen($name, 'UTF-8') > 160) {
        return ['ok' => false, 'error' => 'admin.oncall.errorName'];
    }
    $timezone = trim((string)($input['timezone'] ?? 'UTC'));
    if (!in_array($timezone, timezone_identifiers_list(), true)) {
        return ['ok' => false, 'error' => 'admin.oncall.errorTimezone'];
    }
    $minimumSeverity = strtolower(trim((string)($input['minimum_severity'] ?? 'major')));
    if (!in_array($minimumSeverity, ['info', 'minor', 'major', 'critical'], true)) {
        return ['ok' => false, 'error' => 'admin.oncall.errorSeverity'];
    }
    $members = [];
    $zone = new DateTimeZone($timezone);
    foreach ((array)($input['members'] ?? []) as $index => $rawMember) {
        if (!is_array($rawMember)) {
            continue;
        }
        $memberName = trim((string)($rawMember['name'] ?? ''));
        $channelId = filter_var($rawMember['channel_id'] ?? null, FILTER_VALIDATE_INT);
        $recurrence = strtolower(trim((string)($rawMember['recurrence'] ?? 'weekly')));
        if ($memberName === '' || mb_strlen($memberName, 'UTF-8') > 140 || $channelId === false || (int)$channelId < 1) {
            return ['ok' => false, 'error' => 'admin.oncall.errorMember'];
        }
        if (!in_array($recurrence, ['none', 'daily', 'weekly'], true)) {
            return ['ok' => false, 'error' => 'admin.oncall.errorRecurrence'];
        }
        try {
            $startsAt = new DateTimeImmutable((string)($rawMember['starts_at'] ?? ''), $zone);
            $endsAt = new DateTimeImmutable((string)($rawMember['ends_at'] ?? ''), $zone);
        } catch (Throwable) {
            return ['ok' => false, 'error' => 'admin.oncall.errorDates'];
        }
        if ($endsAt <= $startsAt) {
            return ['ok' => false, 'error' => 'admin.oncall.errorDates'];
        }
        $members[] = [
            'id' => max(0, (int)(filter_var($rawMember['id'] ?? 0, FILTER_VALIDATE_INT) ?: 0)),
            'name' => $memberName,
            'channel_id' => (int)$channelId,
            'starts_at' => $startsAt->format('Y-m-d H:i:s'),
            'ends_at' => $endsAt->format('Y-m-d H:i:s'),
            'recurrence' => $recurrence,
            'sort_order' => $index,
        ];
    }
    if ($members === [] || count($members) > 50) {
        return ['ok' => false, 'error' => 'admin.oncall.errorMember'];
    }
    $siteIds = [];
    foreach ((array)($input['site_ids'] ?? []) as $value) {
        $siteId = filter_var($value, FILTER_VALIDATE_INT);
        if ($siteId !== false && (int)$siteId > 0) {
            $siteIds[(int)$siteId] = (int)$siteId;
        }
    }
    return [
        'ok' => true,
        'name' => $name,
        'timezone' => $timezone,
        'enabled' => insight_probes_bool($input['enabled'] ?? null, true),
        'escalation_delay_minutes' => insight_probes_bounded_int($input['escalation_delay_minutes'] ?? null, 5, 0, 10080),
        'repeat_interval_minutes' => insight_probes_bounded_int($input['repeat_interval_minutes'] ?? null, 15, 1, 10080),
        'maximum_repeats' => insight_probes_bounded_int($input['maximum_repeats'] ?? null, 3, 1, 100),
        'minimum_severity' => $minimumSeverity,
        'site_ids' => array_values($siteIds),
        'members' => $members,
    ];
}

function insight_oncall_state(array $config): array
{
    try {
        $database = insight_probes_database($config);
        $scheduleResult = $database->query(
            "SELECT schedule.*, GROUP_CONCAT(DISTINCT routed.site_id ORDER BY routed.site_id) AS site_ids_csv
             FROM oncall_schedules schedule
             LEFT JOIN oncall_schedule_sites routed ON routed.schedule_id = schedule.id
             GROUP BY schedule.id
             ORDER BY schedule.name, schedule.id"
        );
        $memberResult = $database->query(
            "SELECT member.id, member.schedule_id, member.name, member.channel_id, member.sort_order, member.active,
                    shift.id AS shift_id, shift.starts_at, shift.ends_at, shift.recurrence,
                    channel.name AS channel_name, channel.enabled AS channel_enabled
             FROM oncall_members member
             LEFT JOIN oncall_shifts shift ON shift.member_id = member.id
             LEFT JOIN notification_channels channel ON channel.id = member.channel_id
             ORDER BY member.schedule_id, member.sort_order, member.id, shift.starts_at"
        );
        $eventResult = $database->query(
            "SELECT event.id, event.incident_id, event.sequence_no, event.status, event.attempts, event.last_error,
                    event.last_attempt_at, event.delivered_at, schedule.name AS schedule_name, member.name AS member_name
             FROM oncall_escalation_events event
             INNER JOIN oncall_schedules schedule ON schedule.id = event.schedule_id
             INNER JOIN oncall_members member ON member.id = event.member_id
             ORDER BY event.last_attempt_at DESC, event.id DESC LIMIT 50"
        );
        if (!$scheduleResult instanceof mysqli_result || !$memberResult instanceof mysqli_result || !$eventResult instanceof mysqli_result) {
            throw new RuntimeException('database_read_failed');
        }
        $schedules = [];
        foreach ($scheduleResult->fetch_all(MYSQLI_ASSOC) as $row) {
            $row['id'] = (int)$row['id'];
            $row['enabled'] = (int)$row['enabled'] === 1;
            $row['site_ids'] = array_values(array_filter(array_map('intval', explode(',', (string)($row['site_ids_csv'] ?? '')))));
            $row['members'] = [];
            unset($row['site_ids_csv']);
            $schedules[(int)$row['id']] = $row;
        }
        foreach ($memberResult->fetch_all(MYSQLI_ASSOC) as $row) {
            $scheduleId = (int)$row['schedule_id'];
            if (!isset($schedules[$scheduleId])) {
                continue;
            }
            $schedules[$scheduleId]['members'][] = [
                'id' => (int)$row['id'],
                'name' => (string)$row['name'],
                'channel_id' => (int)$row['channel_id'],
                'channel_name' => (string)($row['channel_name'] ?? ''),
                'channel_enabled' => (int)($row['channel_enabled'] ?? 0) === 1,
                'starts_at' => (string)($row['starts_at'] ?? ''),
                'ends_at' => (string)($row['ends_at'] ?? ''),
                'recurrence' => (string)($row['recurrence'] ?? 'weekly'),
            ];
        }
        $events = $eventResult->fetch_all(MYSQLI_ASSOC);
        $scheduleResult->free();
        $memberResult->free();
        $eventResult->free();
        $database->close();
        return ['ok' => true, 'status_code' => 200, 'schedules' => array_values($schedules), 'events' => $events];
    } catch (Throwable) {
        return ['ok' => false, 'status_code' => 503, 'error' => 'admin.oncall.errorDatabase', 'schedules' => [], 'events' => []];
    }
}

function insight_oncall_store_sites(mysqli $database, int $scheduleId, array $siteIds): void
{
    $delete = $database->prepare('DELETE FROM oncall_schedule_sites WHERE schedule_id = ?');
    $insert = $database->prepare('INSERT INTO oncall_schedule_sites (schedule_id, site_id) SELECT ?, id FROM sites WHERE id = ?');
    if (!$delete instanceof mysqli_stmt || !$insert instanceof mysqli_stmt) {
        throw new RuntimeException('database_prepare_failed');
    }
    $delete->bind_param('i', $scheduleId);
    $delete->execute();
    $delete->close();
    foreach ($siteIds as $siteId) {
        $insert->bind_param('ii', $scheduleId, $siteId);
        $insert->execute();
        if ($insert->affected_rows !== 1) {
            throw new InvalidArgumentException('admin.oncall.errorSite');
        }
    }
    $insert->close();
}

function insight_oncall_store_members(mysqli $database, int $scheduleId, array $members): void
{
    $existingResult = $database->query('SELECT id FROM oncall_members WHERE schedule_id = ' . $scheduleId);
    if (!$existingResult instanceof mysqli_result) {
        throw new RuntimeException('database_read_failed');
    }
    $existing = [];
    foreach ($existingResult->fetch_all(MYSQLI_ASSOC) as $row) {
        $existing[(int)$row['id']] = true;
    }
    $existingResult->free();
    $memberInsert = $database->prepare('INSERT INTO oncall_members (schedule_id, name, channel_id, sort_order, active) VALUES (?, ?, ?, ?, 1)');
    $memberUpdate = $database->prepare('UPDATE oncall_members SET name=?, channel_id=?, sort_order=?, active=1 WHERE id=? AND schedule_id=?');
    $shiftDelete = $database->prepare('DELETE FROM oncall_shifts WHERE member_id=? AND schedule_id=?');
    $shiftInsert = $database->prepare('INSERT INTO oncall_shifts (schedule_id, member_id, starts_at, ends_at, recurrence) VALUES (?, ?, ?, ?, ?)');
    $memberDelete = $database->prepare('DELETE FROM oncall_members WHERE id=? AND schedule_id=?');
    $channelExists = $database->prepare('SELECT id FROM notification_channels WHERE id = ? LIMIT 1');
    if (!$memberInsert instanceof mysqli_stmt || !$memberUpdate instanceof mysqli_stmt || !$shiftDelete instanceof mysqli_stmt || !$shiftInsert instanceof mysqli_stmt || !$memberDelete instanceof mysqli_stmt || !$channelExists instanceof mysqli_stmt) {
        throw new RuntimeException('database_prepare_failed');
    }
    foreach ($members as $member) {
        $channelId = (int)$member['channel_id'];
        $channelExists->bind_param('i', $channelId);
        $channelExists->execute();
        if (!is_array($channelExists->get_result()->fetch_assoc())) {
            throw new InvalidArgumentException('admin.oncall.errorChannel');
        }
        $name = (string)$member['name'];
        $sortOrder = (int)$member['sort_order'];
        $memberId = (int)($member['id'] ?? 0);
        if ($memberId > 0) {
            if (!isset($existing[$memberId])) {
                throw new InvalidArgumentException('admin.oncall.errorMember');
            }
            $memberUpdate->bind_param('siiii', $name, $channelId, $sortOrder, $memberId, $scheduleId);
            $memberUpdate->execute();
            $shiftDelete->bind_param('ii', $memberId, $scheduleId);
            $shiftDelete->execute();
            unset($existing[$memberId]);
        } else {
            $memberInsert->bind_param('isii', $scheduleId, $name, $channelId, $sortOrder);
            $memberInsert->execute();
            $memberId = (int)$memberInsert->insert_id;
        }
        $startsAt = (string)$member['starts_at'];
        $endsAt = (string)$member['ends_at'];
        $recurrence = (string)$member['recurrence'];
        $shiftInsert->bind_param('iisss', $scheduleId, $memberId, $startsAt, $endsAt, $recurrence);
        $shiftInsert->execute();
    }
    foreach (array_keys($existing) as $memberId) {
        $memberDelete->bind_param('ii', $memberId, $scheduleId);
        $memberDelete->execute();
    }
    $channelExists->close();
    $memberInsert->close();
    $memberUpdate->close();
    $shiftDelete->close();
    $shiftInsert->close();
    $memberDelete->close();
}

function insight_oncall_save(array $config, int $id, array $input): array
{
    $creating = $id < 1;
    $validated = insight_oncall_validate($input);
    if (!($validated['ok'] ?? false)) {
        return ['ok' => false, 'status_code' => 422, 'error' => $validated['error']];
    }
    try {
        $database = insight_probes_database($config);
        $database->begin_transaction();
        $name = (string)$validated['name'];
        $timezone = (string)$validated['timezone'];
        $enabled = $validated['enabled'] ? 1 : 0;
        $delay = (int)$validated['escalation_delay_minutes'];
        $repeat = (int)$validated['repeat_interval_minutes'];
        $maximum = (int)$validated['maximum_repeats'];
        $severity = (string)$validated['minimum_severity'];
        if ($id > 0) {
            $statement = $database->prepare('UPDATE oncall_schedules SET name=?, timezone=?, enabled=?, escalation_delay_minutes=?, repeat_interval_minutes=?, maximum_repeats=?, minimum_severity=? WHERE id=?');
            if (!$statement instanceof mysqli_stmt) {
                throw new RuntimeException('database_prepare_failed');
            }
            $statement->bind_param('ssiiiisi', $name, $timezone, $enabled, $delay, $repeat, $maximum, $severity, $id);
            $statement->execute();
            $statement->close();
            $exists = $database->query('SELECT id FROM oncall_schedules WHERE id = ' . $id);
            if (!$exists instanceof mysqli_result || $exists->num_rows !== 1) {
                throw new OutOfBoundsException('admin.oncall.errorNotFound');
            }
        } else {
            $statement = $database->prepare('INSERT INTO oncall_schedules (name, timezone, enabled, escalation_delay_minutes, repeat_interval_minutes, maximum_repeats, minimum_severity) VALUES (?, ?, ?, ?, ?, ?, ?)');
            if (!$statement instanceof mysqli_stmt) {
                throw new RuntimeException('database_prepare_failed');
            }
            $statement->bind_param('ssiiiis', $name, $timezone, $enabled, $delay, $repeat, $maximum, $severity);
            $statement->execute();
            $id = (int)$statement->insert_id;
            $statement->close();
        }
        insight_oncall_store_sites($database, $id, $validated['site_ids']);
        insight_oncall_store_members($database, $id, $validated['members']);
        $database->commit();
        $database->close();
        return ['ok' => true, 'status_code' => $creating ? 201 : 200, 'id' => $id];
    } catch (OutOfBoundsException $exception) {
        if (isset($database) && $database instanceof mysqli) {
            $database->rollback();
            $database->close();
        }
        return ['ok' => false, 'status_code' => 404, 'error' => $exception->getMessage()];
    } catch (InvalidArgumentException $exception) {
        if (isset($database) && $database instanceof mysqli) {
            $database->rollback();
            $database->close();
        }
        return ['ok' => false, 'status_code' => 422, 'error' => $exception->getMessage()];
    } catch (Throwable) {
        if (isset($database) && $database instanceof mysqli) {
            $database->rollback();
            $database->close();
        }
        return ['ok' => false, 'status_code' => 503, 'error' => 'admin.oncall.errorDatabase'];
    }
}

function insight_oncall_delete(array $config, int $id): array
{
    if ($id < 1) {
        return ['ok' => false, 'status_code' => 404, 'error' => 'admin.oncall.errorNotFound'];
    }
    try {
        $database = insight_probes_database($config);
        $statement = $database->prepare('DELETE FROM oncall_schedules WHERE id = ?');
        if (!$statement instanceof mysqli_stmt) {
            throw new RuntimeException('database_prepare_failed');
        }
        $statement->bind_param('i', $id);
        $statement->execute();
        $deleted = $statement->affected_rows > 0;
        $statement->close();
        $database->close();
        return $deleted
            ? ['ok' => true, 'status_code' => 200, 'deleted_id' => $id]
            : ['ok' => false, 'status_code' => 404, 'error' => 'admin.oncall.errorNotFound'];
    } catch (Throwable) {
        return ['ok' => false, 'status_code' => 503, 'error' => 'admin.oncall.errorDatabase'];
    }
}
