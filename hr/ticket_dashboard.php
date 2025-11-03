<?php
// ticket_dashboard.php
require_once '../includes/config.php';
require_once '../includes/functions.php';

if (!isLoggedIn() || !isHR()) {
    header('HTTP/1.1 403 Forbidden');
    exit();
}

if (isset($_GET['action']) && $_GET['action'] === 'get_pending_count') {
    try {
        // Count pending tickets (adjust query based on your actual database structure)
        $stmt = $pdo->query("SELECT COUNT(*) as pending_count FROM tickets WHERE status = 'pending'");
        $result = $stmt->fetch();
        
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'pendingCount' => $result['pending_count'] ?? 0
        ]);
        exit;
        
    } catch (PDOException $e) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'pendingCount' => 0,
            'error' => $e->getMessage()
        ]);
        exit;
    }
}

header('HTTP/1.1 400 Bad Request');
echo json_encode(['success' => false, 'error' => 'Invalid action']);