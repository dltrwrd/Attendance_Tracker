<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

// Get all users who have been active in the last 90 seconds and have non-NULL last_activity
$onlineThreshold = time() - 90;

try {
    $stmt = $pdo->prepare("SELECT id FROM users WHERE last_activity IS NOT NULL AND last_activity > ?");
    $stmt->execute([date('Y-m-d H:i:s', $onlineThreshold)]);
    $onlineUsers = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo json_encode([
        'success' => true,
        'onlineUsers' => $onlineUsers
    ]);
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}