<?php
session_start();
date_default_timezone_set('Asia/Manila');

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    die(json_encode(['error' => 'Unauthorized']));
}

require_once '../connection.php';

function shiftStartTimestamp($shiftText, $dateStr) {
    if (!$shiftText || !$dateStr) return false;
    $parts = explode('-', $shiftText, 2);
    $startPart = trim($parts[0]);
    if ($startPart === '') return false;
    $ts = strtotime($dateStr . ' ' . $startPart);
    return $ts ?: false;
}

$now = time();
$today = date('Y-m-d');
$events = [];

// --- ABSENT: 1hr/30min before shift start, and 30min/1hr after shift start, coverage pending ---
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

    $diffMins = round(($shiftStart - $now) / 60); // positive = before shift, negative = after

    $milestones = [
        60  => ['key' => '1HR-BEFORE',   'label' => 'Shift starts in 1 hour — coverage not yet confirmed.'],
        30  => ['key' => '30MIN-BEFORE', 'label' => 'Shift starts in 30 minutes — coverage still needed.'],
        -30 => ['key' => '30MIN-AFTER',  'label' => 'Shift started 30 minutes ago — still no coverage.'],
        -60 => ['key' => '1HR-AFTER',    'label' => 'Shift started 1 hour ago — still no coverage.'],
    ];

    foreach ($milestones as $target => $info) {
        if (abs($diffMins - $target) <= 5) {
            $events[] = [
                'key' => 'ABSENT-' . $info['key'] . '-' . $row['id'],
                'type' => 'absent',
                'name' => $row['full_name'],
                'message' => $info['label'],
                'shift' => $row['shift'],
            ];
        }
    }
}

// --- LATE: pop every 15 min for today's tardiness records that still need action ---
// NOTE: tardiness does not always require coverage, so this reminder is no longer gated
// on coverage_1 status — instead it only fires while the record still has a pending email
// or an untriggered fire status. minutes_late of 0 or -1 means the record hasn't actually
// been updated yet / the employee hasn't arrived, so those are excluded.
$lateBucket = floor($now / 900);

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
    $events[] = [
        'key' => 'LATE-' . $row['id'] . '-' . $lateBucket,
        'type' => 'late',
        'name' => $row['full_name'],
        'message' => 'Still marked late (' . $row['minutes_late'] . ' min).',
        'shift' => $row['shift'],
    ];
}

header('Content-Type: application/json');
echo json_encode(['success' => true, 'events' => $events]);