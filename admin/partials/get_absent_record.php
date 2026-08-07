<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/functions.php';

header('Content-Type: application/json');

if (!isLoggedIn() || !isAdmin()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if (!isset($_GET['id'])) {
    echo json_encode(['success' => false, 'message' => 'Record ID is required']);
    exit();
}

$recordId = (int)$_GET['id'];

try {
    // Get the absenteeism record - Removed schedule_from and schedule_to
    $stmt = $pdo->prepare("
        SELECT a.*, 
               e.full_name,
               e.department,
               e.supervisor,
               e.operation_manager
        FROM absenteeism a
        LEFT JOIN employees e ON a.employee_id = e.employee_id
        WHERE a.id = ?
    ");
    $stmt->execute([$recordId]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($record) {
        echo json_encode([
            'success' => true,
            'record' => $record
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Record not found'
        ]);
    }
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>