<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

if (!isLoggedIn() || !isAdmin()) {
    header('HTTP/1.1 403 Forbidden');
    exit;
}

header('Content-Type: application/json');

$query = isset($_GET['query']) ? trim($_GET['query']) : '';

try {
    if (strlen($query) >= 2) {
        $stmt = $pdo->prepare("SELECT employee_id, full_name FROM employees 
                              WHERE (employee_id LIKE :query OR full_name LIKE :query) 
                              AND is_active = '1' 
                              LIMIT 10");
        $stmt->execute([':query' => "%$query%"]);
        $employees = $stmt->fetchAll();
        
        echo json_encode(['success' => true, 'employees' => $employees]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Query too short']);
    }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}