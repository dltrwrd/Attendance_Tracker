<?php
// components/vto_webhook.php

// Ensure timestamps are correctly set to Manila time
date_default_timezone_set('Asia/Manila');

require_once '../connection.php'; // Adjust path as needed

// A secret key only you and your automation tool know
$secret_key = "MY_SUPER_SECRET_VTO_KEY_123!"; 

// Check if the request has the right key
if (!isset($_GET['token']) || $_GET['token'] !== $secret_key) {
    http_response_code(403);
    die(json_encode(['error' => 'Unauthorized']));
}

// Get the data sent by Zapier/Make (usually JSON)
$data = json_decode(file_get_contents('php://input'), true);
$subject = isset($data['subject']) ? $data['subject'] : 'New VTO Request';

// FIX: Generate the exact Manila time in PHP
$current_time = date('Y-m-d H:i:s');

// Insert into database with explicit Manila time
$query = "INSERT INTO global_notifications (subject, created_at) VALUES (?, ?)";
$stmt = $con->prepare($query);
$stmt->bind_param("ss", $subject, $current_time);

if ($stmt->execute()) {
    http_response_code(200);
    echo json_encode(['success' => true]);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
}
?>