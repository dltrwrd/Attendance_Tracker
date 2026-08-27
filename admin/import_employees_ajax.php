<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

// Ensure user is admin (or appropriate role)
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'No file uploaded or upload error']);
    exit;
}

$overwrite = isset($_POST['overwrite']) && $_POST['overwrite'] === 'true';
$fileTempName = $_FILES['csv_file']['tmp_name'];
$fileName = $_FILES['csv_file']['name'];
$fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

if ($fileExt !== 'csv') {
    echo json_encode(['success' => false, 'message' => 'Only CSV files are allowed.']);
    exit;
}

$fileHandle = fopen($fileTempName, 'r');
if (!$fileHandle) {
    echo json_encode(['success' => false, 'message' => 'Failed to open the uploaded file.']);
    exit;
}

// Read header row
$header = fgetcsv($fileHandle);
if (!$header) {
    echo json_encode(['success' => false, 'message' => 'The CSV file is empty.']);
    exit;
}

// Normalize headers
$expectedHeaders = ['employee_id', 'full_name', 'department', 'supervisor', 'operation_manager', 'email', 'is_active'];
$headerMap = [];
foreach ($header as $index => $colName) {
    $headerMap[trim(strtolower($colName))] = $index;
}

// Check required headers
$requiredHeaders = ['employee_id', 'full_name'];
foreach ($requiredHeaders as $req) {
    if (!isset($headerMap[$req])) {
        echo json_encode(['success' => false, 'message' => "Missing required column: $req"]);
        exit;
    }
}

$stats = [
    'inserted' => 0,
    'updated' => 0,
    'skipped' => 0,
    'errors' => 0
];

$pdo->beginTransaction();
try {
    // Prepare statements
    $checkStmt = $pdo->prepare("SELECT id FROM employees WHERE employee_id = ?");
    
    $insertStmt = $pdo->prepare("
        INSERT INTO employees (employee_id, full_name, department, supervisor, operation_manager, email, is_active) 
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    
    $updateStmt = $pdo->prepare("
        UPDATE employees 
        SET full_name = ?, department = ?, supervisor = ?, operation_manager = ?, email = ?, is_active = ?
        WHERE employee_id = ?
    ");

    while (($data = fgetcsv($fileHandle)) !== false) {
        // Skip empty rows
        if (empty(array_filter($data))) continue;
        
        $employeeId = strtoupper(trim($data[$headerMap['employee_id']] ?? ''));
        $fullName = strtoupper(trim($data[$headerMap['full_name']] ?? ''));
        
        if (empty($employeeId) || empty($fullName)) {
            $stats['errors']++;
            continue;
        }

        $department = isset($headerMap['department']) ? strtoupper(trim($data[$headerMap['department']])) : '';
        $supervisor = isset($headerMap['supervisor']) ? strtoupper(trim($data[$headerMap['supervisor']])) : '';
        $operationManager = isset($headerMap['operation_manager']) ? strtoupper(trim($data[$headerMap['operation_manager']])) : '';
        $email = isset($headerMap['email']) ? strtolower(trim($data[$headerMap['email']])) : '';
        $isActive = isset($headerMap['is_active']) ? (int)trim($data[$headerMap['is_active']]) : 1;

        // Check if exists
        $checkStmt->execute([$employeeId]);
        $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            if ($overwrite) {
                $updateStmt->execute([
                    $fullName, $department, $supervisor, $operationManager, $email, $isActive, $employeeId
                ]);
                $stats['updated']++;
            } else {
                $stats['skipped']++;
            }
        } else {
            $insertStmt->execute([
                $employeeId, $fullName, $department, $supervisor, $operationManager, $email, $isActive
            ]);
            $stats['inserted']++;
        }
    }
    
    $pdo->commit();
    fclose($fileHandle);
    
    $message = "Import completed successfully. Inserted: {$stats['inserted']}, Updated: {$stats['updated']}, Skipped: {$stats['skipped']}.";
    echo json_encode(['success' => true, 'message' => $message, 'stats' => $stats]);

} catch (Exception $e) {
    $pdo->rollBack();
    fclose($fileHandle);
    error_log("Import error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error during import.']);
}
?>
