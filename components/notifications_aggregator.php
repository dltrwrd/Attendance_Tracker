<?php
// components/notifications_aggregator.php
//
// Replaces the old fetch_notifications.php as the backend for the top-nav notification bell.
// It no longer relies on any external automation (Make.com) posting rows in directly — it
// generates reminders itself, on every poll, straight from the live tables:
//   - shift_absent / shift_late      -> absenteeism / tardiness, same milestone logic as
//                                        check_shift_notifications.php (toast popups), but
//                                        persisted here so they show in the bell + stay read.
//   - pending_email / pending_ir     -> absenteeism & tardiness rows not yet emailed / IR'd
//   - pending_fire                   -> absenteeism, tardiness & VTO rows never fired/triggered
//   - vto_email                      -> untouched legacy rows inserted by whatever process
//                                        receives inbound VTO emails (kept for compatibility)
//
// Notifications persist in `global_notifications` keyed by a stable `event_key` so that once
// a user marks one read it will NOT be re-notified while the underlying condition is
// unchanged. If a condition resolves (email sent, IR filed, fire triggered, coverage found)
// the row is cleaned up automatically. If more than GROUP_THRESHOLD items are pending in the
// same tab/category, they collapse into a single "X pending" summary row instead of spamming
// the list individually.

session_start();
date_default_timezone_set('Asia/Manila');

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    die(json_encode(['error' => 'Unauthorized']));
}

require_once '../connection.php';

const GROUP_THRESHOLD = 3;   // more than this many pendings in a tab/category -> collapse to a summary
const LOOKBACK_DAYS    = 30; // ignore stale records older than this when generating pending reminders

$today = date('Y-m-d');
$now   = time();

/* ---------------------------------------------------------------------
 * Helpers
 * ------------------------------------------------------------------- */

function upsertNotification($con, $eventKey, $category, $sourceType, $subject, $referenceId = null, $isGroup = 0, $groupCount = null, $linkUrl = null, $actionUrl = null) {
    $stmt = $con->prepare(
        "INSERT INTO global_notifications
            (type, event_key, category, source_type, reference_id, is_group, group_count, subject, link_url, action_url, is_read, created_at, updated_at)
         VALUES ('REMINDER', ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, NOW(), NOW())
         ON DUPLICATE KEY UPDATE
            subject = VALUES(subject),
            group_count = VALUES(group_count),
            link_url = VALUES(link_url),
            action_url = VALUES(action_url),
            updated_at = NOW()"
    );
    $stmt->bind_param('sssiiisss', $eventKey, $category, $sourceType, $referenceId, $isGroup, $groupCount, $subject, $linkUrl, $actionUrl);
    $stmt->execute();
}

// Deletes previously-generated rows for a category/source_type whose event_key is no longer
// current (i.e. the underlying condition resolved), so resolved items stop showing up.
function cleanupStale($con, $category, $sourceType, array $validKeys) {
    if (empty($validKeys)) {
        $stmt = $con->prepare("DELETE FROM global_notifications WHERE category = ? AND source_type = ?");
        $stmt->bind_param('ss', $category, $sourceType);
        $stmt->execute();
        return;
    }
    $placeholders = implode(',', array_fill(0, count($validKeys), '?'));
    $types = 'ss' . str_repeat('s', count($validKeys));
    $sql = "DELETE FROM global_notifications WHERE category = ? AND source_type = ? AND event_key NOT IN ($placeholders)";
    $stmt = $con->prepare($sql);
    $params = array_merge([$types], [$category, $sourceType], $validKeys);
    $stmt->bind_param(...$params);
    $stmt->execute();
}

/* ---------------------------------------------------------------------
 * 1) Shift reminders (absent / late) — mirrors check_shift_notifications.php milestones
 * ------------------------------------------------------------------- */

function shiftStartTimestamp($shiftText, $dateStr) {
    if (!$shiftText || !$dateStr) return false;
    $parts = explode('-', $shiftText, 2);
    $startPart = trim($parts[0]);
    if ($startPart === '') return false;
    $ts = strtotime($dateStr . ' ' . $startPart);
    return $ts ?: false;
}

$absentValidKeys = [];

$stmt = $con->prepare(
    "SELECT id, full_name, shift, date_of_absent FROM absenteeism
     WHERE date_of_absent = ? AND (coverage_1 IS NULL OR coverage_1 IN ('PENDING', 'UNCOVERED') OR coverage_1 = '')"
);
$stmt->bind_param('s', $today);
$stmt->execute();
$rows = $stmt->get_result();

while ($row = $rows->fetch_assoc()) {
    $shiftStart = shiftStartTimestamp($row['shift'], $row['date_of_absent']);
    if ($shiftStart === false) continue;
    $diffMins = round(($shiftStart - $now) / 60);

    $milestones = [
        60  => ['key' => '1HR-BEFORE',  'label' => 'Shift starts in 1 hour — coverage not yet confirmed.'],
        30  => ['key' => '30MIN-BEFORE', 'label' => 'Shift starts in 30 minutes — coverage still needed.'],
        -30 => ['key' => '30MIN-AFTER', 'label' => 'Shift started 30 minutes ago — still no coverage.'],
        -60 => ['key' => '1HR-AFTER',   'label' => 'Shift started 1 hour ago — still no coverage.'],
    ];

    foreach ($milestones as $target => $info) {
        if (abs($diffMins - $target) <= 5) {
            $eventKey = 'SHIFT_ABSENT-' . $info['key'] . '-' . $row['id'];
            $absentValidKeys[] = $eventKey;
            upsertNotification(
                $con, $eventKey, 'shift_absent', 'absenteeism',
                $row['full_name'] . ' — ' . $info['label'],
                $row['id'], 0, null,
                'attendance.php?tab=absenteeism&search=' . urlencode($row['full_name'])
            );
        }
    }
}
cleanupStale($con, 'shift_absent', 'absenteeism', $absentValidKeys);

// Tardiness: only remind if the record still needs action (email not yet sent, or fire not
// yet triggered) — a late record with both already done doesn't need a standing reminder.
// minutes_late of 0 or -1 means the record hasn't actually been updated yet / the employee
// hasn't arrived, so those are excluded — they're not a real "still late" case.
$lateValidKeys = [];
$stmt = $con->prepare(
    "SELECT id, full_name, shift, minutes_late FROM tardiness
     WHERE date_of_incident = ?
       AND minutes_late > 0
       AND ((email_sent = 0 OR email_sent IS NULL) OR (trigger_date IS NULL OR trigger_date = ''))"
);
$stmt->bind_param('s', $today);
$stmt->execute();
$rows = $stmt->get_result();

while ($row = $rows->fetch_assoc()) {
    $eventKey = 'SHIFT_LATE-' . $row['id'];
    $lateValidKeys[] = $eventKey;
    upsertNotification(
        $con, $eventKey, 'shift_late', 'tardiness',
        $row['full_name'] . ' — still marked late (' . $row['minutes_late'] . ' min).',
        $row['id'], 0, null,
        'attendance.php?tab=tardiness&search=' . urlencode($row['full_name'])
    );
}
cleanupStale($con, 'shift_late', 'tardiness', $lateValidKeys);

/* ---------------------------------------------------------------------
 * 2) Pending-action reminders: email / incident report / fire trigger
 * ------------------------------------------------------------------- */

function generatePendingReminders($con, $sourceType, $table, $dateField, $editPage) {
    $cutoff = date('Y-m-d', strtotime('-' . LOOKBACK_DAYS . ' days'));

    $checks = [];
    if ($sourceType !== 'vto') {
        $checks['pending_email'] = [
            'where' => "(email_sent = 0 OR email_sent IS NULL)",
            'label' => 'pending email',
            'action_href' => 'send_email.php?send_email={id}&type=' . $sourceType,
        ];
        $irCondition = $sourceType === 'absenteeism'
            ? "(ir_form IS NULL OR ir_form = '' OR ir_form NOT REGEXP '^(YES|NO NEED)')"
            : "(ir_form IS NULL OR ir_form = '' OR ir_form NOT REGEXP '^(YES|FOR ACCUMULATION|NO NEED|EXPIRED)')";
        $checks['pending_ir'] = [
            'where' => $irCondition,
            'label' => 'pending incident report',
            'action_href' => $editPage . '?id={id}&type=' . $sourceType,
        ];
    }
    $checks['pending_fire'] = [
        'where' => "(trigger_date IS NULL OR trigger_date = '')",
        'label' => 'fire status not triggered',
        'action_href' => 'attendance.php?fire_employee={id}&type=' . $sourceType,
    ];

    foreach ($checks as $category => $check) {
        $sql = "SELECT id, full_name FROM $table WHERE $dateField >= ? AND {$check['where']} ORDER BY $dateField DESC";
        $stmt = $con->prepare($sql);
        $stmt->bind_param('s', $cutoff);
        $stmt->execute();
        $result = $stmt->get_result();
        $records = $result->fetch_all(MYSQLI_ASSOC);
        $count = count($records);

        if ($count === 0) {
            cleanupStale($con, $category, $sourceType, []);
            continue;
        }

        if ($count > GROUP_THRESHOLD) {
            $eventKey = 'GROUP-' . strtoupper($category) . '-' . $sourceType;
            $label = ucfirst(str_replace('_', ' ', $sourceType)) . ': ' . $count . ' ' . $check['label'] . ($count === 1 ? '' : 's') . ' — open ' . ucfirst($sourceType) . ' to review.';
            $filterParam = $category === 'pending_email' ? 'pending_emails' : $category; // matches attendance_table.php's $cardFilter values
            // Group summaries never carry an action link — only "view the tab", never navigate.
            upsertNotification(
                $con, $eventKey, $category, $sourceType, $label,
                null, 1, $count,
                'attendance.php?tab=' . $sourceType . '&filter=' . $filterParam,
                null
            );
            cleanupStale($con, $category, $sourceType, [$eventKey]);
        } else {
            $validKeys = [];
            foreach ($records as $rec) {
                $eventKey = strtoupper($category) . '-' . $sourceType . '-' . $rec['id'];
                $validKeys[] = $eventKey;
                // link_url is what clicking the notification ROW does — always safe, always just
                // opens the tab filtered to this person, never a mutating action.
                $viewUrl = 'attendance.php?tab=' . $sourceType . '&search=' . urlencode($rec['full_name']);
                // action_url is ONLY used by the dedicated Send/Trigger/Edit button, gated behind
                // its own confirmation on the front end (and, for send/fire, a server-side
                // POST-only gate too) — it is never used for row-click navigation.
                $actionUrl = str_replace('{id}', $rec['id'], $check['action_href']);
                $label = $rec['full_name'] . ' — ' . $check['label'] . '.';
                upsertNotification($con, $eventKey, $category, $sourceType, $label, $rec['id'], 0, null, $viewUrl, $actionUrl);
            }
            cleanupStale($con, $category, $sourceType, $validKeys);
        }
    }
}

generatePendingReminders($con, 'absenteeism', 'absenteeism', 'date_of_absent', 'attendance_form.php');
generatePendingReminders($con, 'tardiness', 'tardiness', 'date_of_incident', 'attendance_form.php');
generatePendingReminders($con, 'vto', 'vto_tracker', 'shift_date', 'vto_form.php');

/* ---------------------------------------------------------------------
 * 3) Actions: mark_read / mark_all_read / clear_all / list (default)
 * ------------------------------------------------------------------- */

$action = $_GET['action'] ?? 'list';

header('Content-Type: application/json');

if ($action === 'mark_read' && isset($_GET['id'])) {
    $id = (int) $_GET['id'];
    $stmt = $con->prepare("UPDATE global_notifications SET is_read = 1 WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'mark_all_read') {
    $con->query("UPDATE global_notifications SET is_read = 1 WHERE is_read = 0");
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'clear_all') {
    $con->query("DELETE FROM global_notifications");
    echo json_encode(['success' => true]);
    exit;
}

// Default: list everything, most recent unread first.
$result = $con->query(
    "SELECT id, type, event_key, category, source_type, reference_id, is_group, group_count,
            subject, link_url, action_url, is_read, created_at
     FROM global_notifications
     ORDER BY is_read ASC, created_at DESC
     LIMIT 200"
);
$notifications = $result->fetch_all(MYSQLI_ASSOC);

echo json_encode(['success' => true, 'notifications' => $notifications]);