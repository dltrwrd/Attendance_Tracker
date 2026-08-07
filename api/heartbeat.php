<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

try {
    $action = $_GET['action'] ?? 'ping';
    $userId = $_SESSION['user_id'];

    if ($action === 'offline') {
        $stmt = $pdo->prepare("UPDATE users SET last_activity = NULL WHERE id = ?");
        $stmt->execute([$userId]);
        echo json_encode(['success' => true, 'message' => 'Set offline']);
    } else {
        $stmt = $pdo->prepare("UPDATE users SET last_activity = CONVERT_TZ(NOW(), 'SYSTEM', '+08:00') WHERE id = ?");
        $stmt->execute([$userId]);
        echo json_encode(['success' => true, 'message' => 'Heartbeat updated']);
    }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
