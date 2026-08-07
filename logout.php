<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

if (isset($_SESSION['user_id'])) {
    // Clear last activity timestamp and voice status on logout
    $stmt = $pdo->prepare("UPDATE users SET last_activity = NULL, is_in_voice = 0, peer_id = NULL WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
}

// Destroy the session
session_destroy();

// Redirect to login page
redirect(BASE_URL);
exit;