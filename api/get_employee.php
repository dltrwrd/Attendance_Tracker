<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

if (!isLoggedIn() || !isAdmin()) {
    redirect(BASE_URL);
}

header('Content-Type: application/json');

$employeeId = isset($_GET['employee_id']) ? trim($_GET['employee_id']) : '';

try {
    $stmt = $pdo->prepare("SELECT * FROM employees WHERE employee_id = ?");
    $stmt->execute([$employeeId]);
    $employee = $stmt->fetch();
    
    if ($employee) {
        echo json_encode(['success' => true, 'employee' => $employee]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Employee not found']);
    }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}