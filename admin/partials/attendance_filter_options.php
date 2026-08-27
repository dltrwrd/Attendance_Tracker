<?php
// partials/attendance_filter_options.php
//
// Powers the Google-Sheets-style column filter dropdowns (Department / Shift / Operations
// Manager) on the attendance table. Given a target column and the OTHER filters currently
// active (search, date range, card filter, coverage, IR filter, and the other two column
// filters), it returns only the distinct values that actually appear in that filtered view —
// so opening "Shift" after already filtering Department down to "IT" only shows shifts that
// IT actually has, not every shift in the whole table.

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';   // defines $pdo — was missing, causing "Failed to load." on every column filter dropdown

header('Content-Type: application/json');

$column = $_POST['column'] ?? '';
$allowedColumns = ['department' => 'department', 'shift' => 'shift', 'om' => 'operation_manager'];
if (!isset($allowedColumns[$column])) {
    echo json_encode(['success' => false, 'error' => 'Invalid column']);
    exit;
}
$dbColumn = $allowedColumns[$column];

$type = $_POST['type'] ?? 'absenteeism';
$table = ($type === 'tardiness') ? 'tardiness' : (($type === 'vto') ? 'vto_tracker' : 'absenteeism');

// operation_manager doesn't exist on vto_tracker.
if ($column === 'om' && $type === 'vto') {
    echo json_encode(['success' => true, 'options' => []]);
    exit;
}

function normalizeMultiFilterParam($val) {
    if (is_array($val)) {
        return array_values(array_filter(array_map('trim', $val), function ($v) { return $v !== ''; }));
    }
    if (is_string($val) && $val !== '') {
        return array_values(array_filter(array_map('trim', explode(',', $val)), function ($v) { return $v !== ''; }));
    }
    return [];
}

$search     = trim($_POST['search'] ?? '');
$dateFrom   = $_POST['date_from'] ?? '';
$dateTo     = $_POST['date_to'] ?? '';
$coverage   = $_POST['coverage_1'] ?? '';
$irFilter   = $_POST['ir_filter'] ?? '';
$cardFilter = $_POST['filter'] ?? '';

// The OTHER column filters (never the one currently being opened) — this is what makes the
// dropdown cascade against whatever else is already selected.
$departmentValues = $column === 'department' ? [] : normalizeMultiFilterParam($_POST['department'] ?? '');
$shiftValues      = $column === 'shift'      ? [] : normalizeMultiFilterParam($_POST['shift'] ?? '');
$omValues         = $column === 'om'         ? [] : normalizeMultiFilterParam($_POST['om'] ?? '');

$whereClauses = [];
$params = [];

if (!empty($cardFilter)) {
    switch ($cardFilter) {
        case 'pending_emails':
            $whereClauses[] = "email_sent = 0";
            break;
        case 'pending_ir':
            $whereClauses[] = ($table === 'absenteeism')
                ? "ir_form NOT REGEXP '^(YES|NO NEED)'"
                : "ir_form NOT REGEXP '^(YES|FOR ACCUMULATION|NO NEED|EXPIRED)'";
            break;
        case 'pending_coverage':
            $whereClauses[] = "coverage_1 = 'PENDING'";
            break;
        case 'uncovered_shift':
            $whereClauses[] = "coverage_1 = 'UNCOVERED'";
            break;
        case 'pending_fire':
            $whereClauses[] = "(trigger_date IS NULL OR trigger_date = '')";
            break;
    }
}

if (!empty($search)) {
    $whereClauses[] = "(employee_id LIKE :search OR full_name LIKE :search)";
    $params[':search'] = "%$search%";
}

$dateField = ($type === 'tardiness') ? 'date_of_incident' : (($type === 'vto') ? 'shift_date' : 'date_of_absent');
if (!empty($dateFrom)) {
    $whereClauses[] = "$dateField >= :date_from";
    $params[':date_from'] = $dateFrom;
}
if (!empty($dateTo)) {
    $whereClauses[] = "$dateField <= :date_to";
    $params[':date_to'] = $dateTo;
}

if ($type === 'absenteeism' && !empty($coverage)) {
    $whereClauses[] = "coverage_1 = :coverage_1";
    $params[':coverage_1'] = $coverage;
}

function appendInFilter(&$whereClauses, &$params, $col, array $values, $prefix) {
    if (empty($values)) return;
    $placeholders = [];
    foreach ($values as $i => $val) {
        $key = ":{$prefix}_{$i}";
        $placeholders[] = $key;
        $params[$key] = $val;
    }
    $whereClauses[] = "$col IN (" . implode(',', $placeholders) . ")";
}
appendInFilter($whereClauses, $params, 'department', $departmentValues, 'dept');
appendInFilter($whereClauses, $params, 'shift', $shiftValues, 'shift');
if ($type !== 'vto') {
    appendInFilter($whereClauses, $params, 'operation_manager', $omValues, 'om');
}

if (!empty($irFilter) && ($type === 'tardiness' || $type === 'absenteeism')) {
    $irValues = array_values(array_filter(array_map('trim', explode(',', $irFilter))));
    if (!empty($irValues)) {
        $placeholders = [];
        foreach ($irValues as $i => $val) {
            $key = ":ir_$i";
            $placeholders[] = $key;
            $params[$key] = $val;
        }
        $whereClauses[] = "ir_form IN (" . implode(',', $placeholders) . ")";
    }
}

$where = !empty($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) . " AND $dbColumn IS NOT NULL AND $dbColumn != ''" : "WHERE $dbColumn IS NOT NULL AND $dbColumn != ''";

try {
    $sql = "SELECT $dbColumn AS value, COUNT(*) AS cnt FROM $table $where GROUP BY $dbColumn ORDER BY $dbColumn ASC";
    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $options = array_map(function ($r) {
        return ['value' => $r['value'], 'count' => (int)$r['cnt']];
    }, $rows);

    echo json_encode(['success' => true, 'options' => $options]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Query failed']);
}