<?php
// components/fetch_notifications.php
session_start();

// Prevent unauthorized access/bots from hitting this endpoint
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    die(json_encode(['error' => 'Unauthorized']));
}

require_once '../connection.php';

$action = isset($_GET['action']) ? $_GET['action'] : 'fetch';

if ($action === 'clear_all') {
    // Clear all notifications
    $query = "TRUNCATE TABLE global_notifications";
    if ($con->query($query)) {
        echo json_encode(['success' => true]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to clear notifications']);
    }
    exit;
}

if ($action === 'mark_read' && isset($_GET['id'])) {
    // Mark a specific notification as read
    $id = (int)$_GET['id'];
    $query = "UPDATE global_notifications SET is_read = 1 WHERE id = ?";
    $stmt = $con->prepare($query);
    $stmt->bind_param("i", $id);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to update']);
    }
    exit;
}

// FIX: Force Manila timezone and calculate exactly 24 hours ago in PHP
date_default_timezone_set('Asia/Manila');
$twenty_four_hours_ago = date('Y-m-d H:i:s', strtotime('-24 hours'));

// Default action: Fetch all notifications using PHP's Manila time instead of MySQL's NOW()
$query = "SELECT * FROM global_notifications WHERE created_at >= ? ORDER BY created_at DESC";
$stmt = $con->prepare($query);
$stmt->bind_param("s", $twenty_four_hours_ago);
$stmt->execute();
$result = $stmt->get_result();
$notifications = $result->fetch_all(MYSQLI_ASSOC);

header('Content-Type: application/json');
echo json_encode(['notifications' => $notifications]);
?>