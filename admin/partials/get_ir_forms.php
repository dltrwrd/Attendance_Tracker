<?php
/**
 * get_ir_forms.php
 * Returns a JSON list of distinct IR form values for a given type (absenteeism/tardiness),
 * optionally filtered by date range.
 */
require_once __DIR__ . '/../../includes/db.php';

header('Content-Type: application/json');

$type     = isset($_GET['type'])      ? $_GET['type']      : 'absenteeism';
$dateFrom = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$dateTo   = isset($_GET['date_to'])   ? $_GET['date_to']   : '';

// Determine the correct table and date column
if ($type === 'tardiness') {
    $table     = 'tardiness';
    $dateField = 'date_of_incident';
} else {
    $table     = 'absenteeism';
    $dateField = 'date_of_absent';
}

// Build query with optional date filters
$where  = ["ir_form IS NOT NULL", "ir_form != ''"];
$params = [];

if (!empty($dateFrom)) {
    $where[]                = "$dateField >= :date_from";
    $params[':date_from']   = $dateFrom;
}
if (!empty($dateTo)) {
    $where[]                = "$dateField <= :date_to";
    $params[':date_to']     = $dateTo;
}

$sql  = "SELECT DISTINCT ir_form FROM $table WHERE " . implode(' AND ', $where) . " ORDER BY ir_form";
$stmt = $pdo->prepare($sql);
foreach ($params as $key => $val) {
    $stmt->bindValue($key, $val);
}
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Categorise into standardOptions and pendingOptions (grouped dates)
$standardOptions = [];
$noNeedOptions   = [];
$pendingOptions  = [];  // keyed by "PENDING / MON DD"

foreach ($rows as $irForm) {
    if ($type === 'tardiness') {
        if (in_array($irForm, ['FOR IR', 'FOR ACCUMULATION', 'PENDING'])) {
            $standardOptions[] = $irForm;
        } elseif (strpos(strtoupper(trim($irForm)), 'NO NEED') === 0) {
            $noNeedOptions[$irForm] = $irForm;
        } elseif (preg_match('/PENDING \/ ([A-Z]{3,4} [0-9]{1,2})/', $irForm, $m)) {
            $key = 'PENDING / ' . $m[1];
            $pendingOptions[$key] = $key;
        }
    } else {
        // absenteeism
        if ($irForm === 'FOR IR') {
            $standardOptions[] = $irForm;
        } elseif (strpos(strtoupper(trim($irForm)), 'NO NEED') === 0) {
            $noNeedOptions[$irForm] = $irForm;
        } elseif (preg_match('/PENDING \/ ([A-Z]{3,4} [0-9]{1,2})/', $irForm, $m)) {
            $key = 'PENDING / ' . $m[1];
            $pendingOptions[$key] = $key;
        }
    }
}

krsort($pendingOptions);
ksort($noNeedOptions);

// Build final ordered list
$result = [];
foreach ($standardOptions as $v) {
    $result[] = $v;
}
foreach ($pendingOptions as $v) {
    $result[] = $v;
}
foreach ($noNeedOptions as $v) {
    $result[] = $v;
}

// Remove duplicates while preserving order
$result = array_values(array_unique($result));

echo json_encode($result);
