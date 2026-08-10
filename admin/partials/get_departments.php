<?php
/**
 * get_departments.php
 * Returns a JSON list of distinct departments for a given type (absenteeism/tardiness),
 * optionally filtered by date range.
 */
require_once __DIR__ . '/../../includes/db.php';

header('Content-Type: application/json');

$type     = isset($_GET['type'])      ? $_GET['type']      : 'absenteeism';
$dateFrom = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$dateTo   = isset($_GET['date_to'])   ? $_GET['date_to']   : '';

if ($type === 'tardiness') {
    $table     = 'tardiness';
    $dateField = 'date_of_incident';
} elseif ($type === 'absenteeism') {
    $table     = 'absenteeism';
    $dateField = 'date_of_absent';
} else {
    // VTO or others — return all from both tables
    $stmt = $pdo->query("SELECT DISTINCT department FROM absenteeism UNION SELECT DISTINCT department FROM tardiness ORDER BY department");
    echo json_encode($stmt->fetchAll(PDO::FETCH_COLUMN));
    exit;
}

$where  = [];
$params = [];

if (!empty($dateFrom)) {
    $where[]              = "$dateField >= :date_from";
    $params[':date_from'] = $dateFrom;
}
if (!empty($dateTo)) {
    $where[]            = "$dateField <= :date_to";
    $params[':date_to'] = $dateTo;
}

$sql = "SELECT DISTINCT department FROM $table"
     . (!empty($where) ? ' WHERE ' . implode(' AND ', $where) : '')
     . " ORDER BY department";

$stmt = $pdo->prepare($sql);
foreach ($params as $key => $val) {
    $stmt->bindValue($key, $val);
}
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

echo json_encode(array_values($rows));
